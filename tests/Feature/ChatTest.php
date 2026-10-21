<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Chat\AnthropicGateway;
use Psc\Chat\ChatService;
use Psc\Chat\Prompts;
use Psc\Controllers\ChatController;
use Psc\Http\Response;
use Psc\Tests\Fakes\FakeGateway;
use Psc\Tests\TestCase;

final class ChatTest extends TestCase
{
    private function ask(string $message, string $page = '/stagewatch/', array $headers = ['X-Requested-With' => 'fetch', 'Sec-Fetch-Site' => 'same-origin']): Response
    {
        return $this->request('POST', '/api/chat', [], $headers, json_encode(['message' => $message, 'page' => $page]));
    }

    /** @return list<array{string, array<string, mixed>}> */
    private static function events(Response $r): array
    {
        $out = [];
        foreach (preg_split("/\n\n/", $r->captureStream()) as $block) {
            if (preg_match('/^event: (\w+)\ndata: (.*)$/s', trim($block), $m)) {
                $out[] = [$m[1], json_decode($m[2], true)];
            }
        }
        return $out;
    }

    private static function text(array $events): string
    {
        return implode('', array_map(static fn ($e) => $e[0] === 'delta' ? $e[1]['t'] : '', $events));
    }

    public function testUnavailableWithoutAKey(): void
    {
        $r = $this->ask('What is StageWatch?');
        self::assertSame(503, $r->status);
        self::assertSame('unavailable', json_decode($r->body, true)['error']);
    }

    /** @return array{status: string, scope: string, fab: string, html: string} the panel's parts on a public page */
    private function panel(string $path): array
    {
        $r = $this->request('GET', $path);
        self::assertSame(200, $r->status);
        self::assertSame([], $r->cookies(), 'the panel adds no cookie to a public page');
        self::assertMatchesRegularExpression('/^public,/', (string) $r->getHeader('Cache-Control'));
        preg_match('#<span class="c-chat-status">(.*?)</span>#s', $r->body, $status);
        preg_match('#<p class="c-chat-scope">(.*?)</p>#s', $r->body, $scope);
        preg_match('#<a class="c-chat-fab[^"]*"[^>]*data-chat-fab[^>]*>(.*?)</a>#s', $r->body, $fab);
        return ['status' => $status[1] ?? '', 'scope' => $scope[1] ?? '', 'fab' => $fab[1] ?? '', 'html' => $r->body];
    }

    public function testThePanelSaysOfflineWhenTheAssistantCannotAnswer(): void
    {
        foreach ([[], ['chat' => ['api_key' => 'sk-test', 'enabled' => false]]] as $config) {
            $this->bootApp($config);
            self::assertFalse(ChatController::online($this->app->config));
            foreach (['/stagewatch/', '/support/'] as $path) {
                $p = $this->panel($path);
                self::assertStringContainsString('c-lamp--off', $p['status'], $path);
                self::assertStringContainsString('Offline', $p['status'], $path);
                self::assertStringNotContainsString('c-lamp--go', $p['status'], $path);
                self::assertStringContainsString("The assistant isn't available right now.", $p['scope'], $path);
                self::assertStringContainsString('href="/contact/"', $p['scope'], $path);
                self::assertStringNotContainsString('data-chat-suggest', $p['html'], $path);
            }
            self::assertStringNotContainsString('c-lamp', $this->panel('/stagewatch/')['fab'], 'no live lamp on the launcher');
        }
    }

    public function testThePanelSaysOnlineWhenConfigured(): void
    {
        self::assertTrue(AnthropicGateway::available(), 'the SDK is installed');
        $this->bootApp(['chat' => ['api_key' => 'sk-test']]);
        self::assertTrue(ChatController::online($this->app->config));
        foreach (['/stagewatch/', '/support/'] as $path) {
            $p = $this->panel($path);
            self::assertStringContainsString('c-lamp--go', $p['status'], $path);
            self::assertStringContainsString('Online', $p['status'], $path);
            self::assertStringContainsString('I can help with', $p['scope'], $path);
            self::assertStringContainsString('data-chat-suggest', $p['html'], $path);
        }
        self::assertStringContainsString('c-lamp', $this->panel('/stagewatch/')['fab']);
    }

    public function testTheFloatingLauncherStartsTuckedWithAPlainLinkForNoScript(): void
    {
        $html = $this->panel('/stagewatch/')['html'];
        self::assertMatchesRegularExpression('#<a class="c-chat-fab is-tucked" href="/contact/" data-chat-open data-chat-fab>#', $html);
        self::assertMatchesRegularExpression('#<noscript><a class="c-chat-fab" href="/contact/">#', $html);
        self::assertStringNotContainsString('<noscript><a class="c-chat-fab"', $this->request('GET', '/contact/')->body, 'no link to the page you are on');
        self::assertStringNotContainsString('data-chat-fab', $this->panel('/support/')['html'], 'the support page has the inline panel only');
    }

    public function testCrossSiteOrHeaderlessRequestsAreRefused(): void
    {
        ChatController::useGateway(new FakeGateway());
        self::assertSame(403, $this->ask('hi', '/', [])->status);
        self::assertSame(403, $this->ask('hi', '/', ['X-Requested-With' => 'fetch', 'Sec-Fetch-Site' => 'cross-site'])->status);
    }

    public function testInScopeStreamsTheAnswerAndStoresBothMessages(): void
    {
        $fake = new FakeGateway('in_scope', ['StageWatch ', 'counts down.']);
        ChatController::useGateway($fake);
        $r = $this->ask('What does StageWatch do?');
        self::assertSame(200, $r->status);
        self::assertStringStartsWith('text/event-stream', (string) $r->getHeader('Content-Type'));
        self::assertSame('no', $r->getHeader('X-Accel-Buffering'));
        $events = self::events($r);
        self::assertSame('meta', $events[0][0]);
        self::assertSame('StageWatch counts down.', self::text($events));
        self::assertSame(['done', ['scope' => 'in', 'handoff' => false, 'truncated' => false]], end($events));

        self::assertCount(1, $fake->streamed);
        $sent = $fake->streamed[0];
        self::assertSame('ephemeral', $sent['system'][0]['cacheControl']['type']);
        self::assertStringContainsString('KNOWLEDGE', $sent['system'][0]['text']);
        self::assertSame('user', $sent['messages'][0]['role']);
        self::assertSame('system', end($sent['messages'])['role']);
        self::assertStringContainsString('/stagewatch/', end($sent['messages'])['content']);

        $rows = $this->db->all('SELECT role, content, verdict FROM chat_messages ORDER BY id');
        self::assertSame(['user', 'assistant'], array_column($rows, 'role'));
        self::assertSame('StageWatch counts down.', $rows[1]['content']);
        self::assertSame('in_scope', $rows[1]['verdict']);
        self::assertGreaterThan(0, (int) $this->db->value('SELECT tokens_in FROM chat_usage'));
    }

    public function testOutOfScopeGetsTheFixedReplyAndNeverReachesTheAnswerModel(): void
    {
        $fake = new FakeGateway('out_of_scope');
        ChatController::useGateway($fake);
        $events = self::events($this->ask('Write me a poem about the sea'));
        self::assertSame(Prompts::OUT_OF_SCOPE, self::text($events));
        self::assertSame('out', end($events)[1]['scope']);
        self::assertCount(0, $fake->streamed);
        self::assertSame('out_of_scope', $this->db->value("SELECT verdict FROM chat_messages WHERE role = 'assistant'"));
    }

    public function testAbuseAndGateRefusalGetTheShortRefusal(): void
    {
        ChatController::useGateway(new FakeGateway('abuse'));
        self::assertSame(Prompts::ABUSE, self::text(self::events($this->ask('something nasty'))));
        $_SESSION = [];
        $fake = new FakeGateway('in_scope', gateRefused: true);
        ChatController::useGateway($fake);
        self::assertSame(Prompts::ABUSE, self::text(self::events($this->ask('something else'))));
        self::assertCount(0, $fake->streamed);
    }

    public function testAFailedScopeCheckFailsClosed(): void
    {
        $fake = new FakeGateway(gateError: new \RuntimeException('overloaded'));
        ChatController::useGateway($fake);
        $events = self::events($this->ask('What is Patterns?'));
        self::assertSame(Prompts::UNAVAILABLE, self::text($events));
        self::assertTrue(end($events)[1]['handoff']);
        self::assertCount(0, $fake->streamed);
    }

    public function testNeedsHumanAnswersWithAHandoff(): void
    {
        ChatController::useGateway(new FakeGateway('needs_human', ['Please use the contact form.']));
        $events = self::events($this->ask('I want a refund'));
        self::assertSame(['scope' => 'human', 'handoff' => true, 'truncated' => false], end($events)[1]);
    }

    public function testARefusedAnswerIsReplacedNotShown(): void
    {
        ChatController::useGateway(new FakeGateway('in_scope', ['partial'], 'refusal'));
        $events = self::events($this->ask('What is PlayMap?'));
        self::assertSame('error', end($events)[0]);
        self::assertSame('declined', end($events)[1]['code']);
    }

    public function testValidationAndTurnLimit(): void
    {
        $this->bootApp(['chat' => ['max_turns' => 2, 'max_input_chars' => 20]]);
        ChatController::useGateway(new FakeGateway());
        self::assertSame(400, $this->ask('   ')->status);
        self::assertSame(400, $this->ask(str_repeat('x', 21))->status);
        foreach (['one', 'two'] as $m) {
            $r = $this->ask($m);
            self::assertSame(200, $r->status);
            $r->captureStream();
        }
        $r = $this->ask('three');
        self::assertSame(429, $r->status);
        self::assertSame('turn_limit', json_decode($r->body, true)['error']);
    }

    public function testHistoryIsServerSideAndAlternates(): void
    {
        $fake = new FakeGateway('in_scope', ['Answer one.']);
        ChatController::useGateway($fake);
        self::events($this->ask('First question'));
        $fake->fragments = ['Answer two.'];
        self::events($this->ask('Second question'));
        $msgs = $fake->streamed[1]['messages'];
        self::assertSame(['user', 'assistant', 'user', 'system'], array_column($msgs, 'role'));
        self::assertSame('Answer one.', $msgs[1]['content']);
        // The gate saw the earlier turns as context and the new message marked.
        self::assertStringContainsString('First question', $fake->classified[1]['messages'][0]['content']);
        self::assertStringContainsString("LATEST VISITOR MESSAGE", $fake->classified[1]['messages'][0]['content']);
    }

    public function testRateLimitPerAddress(): void
    {
        $this->bootApp(['chat' => ['per_ip_per_10min' => 2]]);
        ChatController::useGateway(new FakeGateway());
        $this->ask('a');
        $this->ask('b');
        self::assertSame(429, $this->ask('c')->status);
    }

    public function testDailyBudgetHandsOver(): void
    {
        $this->bootApp(['chat' => ['daily_token_budget' => 100]]);
        ChatController::useGateway(new FakeGateway());
        $this->db->insert('chat_usage', ['day' => gmdate('Y-m-d'), 'requests' => 1, 'tokens_in' => 90, 'tokens_out' => 20]);
        $r = $this->ask('hello');
        self::assertSame(503, $r->status);
        self::assertSame('busy', json_decode($r->body, true)['error']);
    }

    public function testCodeAndInternalsRequestsGetTheNoCodeReply(): void
    {
        $fake = new FakeGateway('code_or_internals');
        ChatController::useGateway($fake);
        $events = self::events($this->ask('Give me the PowerShell command to build Patterns from source'));
        self::assertSame(Prompts::NO_CODE, self::text($events));
        self::assertSame('out', end($events)[1]['scope']);
        self::assertCount(0, $fake->streamed);
    }

    public function testAnAnswerThatStartsToContainCodeIsWithdrawn(): void
    {
        ChatController::useGateway(new FakeGateway('in_scope', ['To install, run ', '`winget install ', 'Patterns`', ' and then...']));
        $events = self::events($this->ask('How do I install Patterns?'));
        $names = array_column($events, 0);
        self::assertContains('replace', $names);
        $replace = $events[array_search('replace', $names, true)][1];
        self::assertSame(Prompts::NO_CODE, $replace['t']);
        self::assertStringNotContainsString('winget', self::text($events));
        self::assertSame('code_guard', $this->db->value("SELECT verdict FROM chat_messages WHERE role = 'assistant'"));
    }

    /** @return iterable<array{string, bool}> */
    public static function codeSamples(): iterable
    {
        yield ['Run `dotnet build -c Release`', true];
        yield ["```\nanything\n```", true];
        yield ['PS C:\\> Install-Module Thing', true];
        yield ['pwsh -File tools\\release.ps1', true];
        yield ['git clone https://example.test/repo', true];
        yield ['    public void Start() {', true];
        yield ['Download it from the portal, unzip and double-click start-agmessage.bat.', false];
        yield ['You can import your running order from a CSV file.', false];
        yield ['The holographic display uses three.js and the Node.js runtime ships inside.', false];
        yield ['Bitfocus Companion can start the countdown over OSC or HTTP.', false];
        yield ["- **Standard** — £9\n- **Pro** — £29", false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('codeSamples')]
    public function testCodeGuard(string $text, bool $isCode): void
    {
        self::assertSame($isCode, ChatService::looksLikeCode($text), $text);
    }

    public function testAlternateMergesRepeatedRoles(): void
    {
        $m = ChatService::alternate([
            ['role' => 'assistant', 'content' => 'orphan'],
            ['role' => 'user', 'content' => 'a'],
            ['role' => 'user', 'content' => 'b'],
            ['role' => 'assistant', 'content' => 'c'],
        ]);
        self::assertSame([['role' => 'user', 'content' => "a\n\nb"], ['role' => 'assistant', 'content' => 'c']], $m);
    }

    public function testTheGateSchemaIsClosed(): void
    {
        $s = Prompts::gateSchema();
        self::assertFalse($s['additionalProperties']);
        self::assertSame(['in_scope', 'needs_human', 'code_or_internals', 'out_of_scope', 'abuse'], $s['properties']['verdict']['enum']);
    }
}
