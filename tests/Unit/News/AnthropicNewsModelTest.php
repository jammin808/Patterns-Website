<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psc\News\AnthropicNewsModel;
use Psc\News\Prompts;

/**
 * The desk's real SDK path against a mocked HTTP transport (docs/NEWS.md §12 Unit): the requests sent (tools, their
 * limits, the location, the output format, the fallbacks beta, the cache marker, the client's timeouts), a paused
 * research turn resumed with its container and what is left of its allowance, the last "write now" message, the
 * references from searches, page reads and citations, and the usage summed by the model that served it.
 */
final class AnthropicNewsModelTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var list<array{0: array<string, int>, 1: string}> what the after hook was told */
    private array $reported = [];

    private int $befores = 0;

    private function model(Response ...$responses): AnthropicNewsModel
    {
        if (!AnthropicNewsModel::available()) {
            self::fail('The Anthropic SDK, Guzzle and curl must be installed (composer install).');
        }
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new AnthropicNewsModel('sk-test', 600, 15, $stack);
    }

    /** @return array<string, mixed> */
    private function body(int $i): array
    {
        $this->history[$i]['request']->getBody()->rewind();
        return json_decode((string) $this->history[$i]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function call(array $over = []): array
    {
        return $over + [
            'model' => 'claude-sonnet-5-5',
            'system' => 'RULES',
            'messages' => [['role' => 'user', 'content' => 'Research the week.']],
            'maxTokens' => 32000,
            'effort' => 'high',
            'before' => function (): void {
                $this->befores++;
            },
            'after' => function (array $usage, string $model): void {
                $this->reported[] = [$usage, $model];
            },
        ];
    }

    /** @return list<array<string, mixed>> the research tools as the pipeline builds them */
    private static function tools(int $searches, int $fetches): array
    {
        return [
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => $searches,
                'userLocation' => ['type' => 'approximate', 'country' => 'GB', 'timezone' => 'Europe/London'], 'blockedDomains' => ['spam.example']],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'maxUses' => $fetches, 'maxContentTokens' => 8000, 'citations' => ['enabled' => false], 'blockedDomains' => ['spam.example']],
        ];
    }

    // ------------------------------------------------------------------------------------- stream builders

    /** @param list<array<string, mixed>> $events */
    private static function sse(array $events): Response
    {
        $body = '';
        foreach ($events as $e) {
            $body .= 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n";
        }
        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }

    /**
     * A whole message as stream events: each block started and stopped (text blocks streamed as deltas, citations
     * included), then the stop reason and the closing usage.
     *
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $usage
     * @param array<string, mixed>|null $container
     * @return list<array<string, mixed>>
     */
    private static function message(array $blocks, string $stop, array $usage, ?array $container = null, string $model = 'claude-sonnet-5-5', bool $complete = true): array
    {
        $events = [['type' => 'message_start', 'message' => [
            'id' => 'msg_' . bin2hex(random_bytes(3)), 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'container' => $container,
            'content' => [], 'stop_reason' => null, 'stop_sequence' => null,
            'usage' => ['input_tokens' => $usage['input_tokens'] ?? 0, 'output_tokens' => 1, 'cache_creation_input_tokens' => $usage['cache_creation_input_tokens'] ?? 0, 'cache_read_input_tokens' => $usage['cache_read_input_tokens'] ?? 0],
        ]]];
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'text') {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '', 'citations' => null]];
                foreach ($block['citations'] ?? [] as $citation) {
                    $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'citations_delta', 'citation' => $citation]];
                }
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
            } else {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => $block];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $i];
        }
        if (!$complete) {
            return $events;
        }
        $delta = ['output_tokens' => $usage['output_tokens'] ?? 0] + array_intersect_key($usage, ['server_tool_use' => 1, 'iterations' => 1]);
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => $delta];
        $events[] = ['type' => 'message_stop'];
        return $events;
    }

    /** @return array<string, mixed> */
    private static function search(string $id, string $query): array
    {
        return ['type' => 'server_tool_use', 'id' => $id, 'name' => 'web_search', 'input' => ['query' => $query], 'caller' => ['type' => 'direct']];
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $results url, title, page age
     * @return array<string, mixed>
     */
    private static function results(string $id, array $results): array
    {
        return ['type' => 'web_search_tool_result', 'tool_use_id' => $id, 'caller' => ['type' => 'direct'], 'content' => array_map(
            static fn (array $r): array => ['type' => 'web_search_result', 'url' => $r[0], 'title' => $r[1], 'encrypted_content' => 'ENC', 'page_age' => $r[2]],
            $results
        )];
    }

    /** @return list<array<string, mixed>> a page read: the tool call and its result (the address asked for, and where it ended up) */
    private static function fetch(string $id, string $asked, string $fetched, string $title): array
    {
        return [
            ['type' => 'server_tool_use', 'id' => $id, 'name' => 'web_fetch', 'input' => ['url' => $asked], 'caller' => ['type' => 'direct']],
            ['type' => 'web_fetch_tool_result', 'tool_use_id' => $id, 'caller' => ['type' => 'direct'], 'content' => [
                'type' => 'web_fetch_result', 'url' => $fetched, 'retrieved_at' => '2026-10-22T05:59:00Z',
                'content' => ['type' => 'document', 'title' => $title, 'citations' => ['enabled' => false], 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'The page.']],
            ]],
        ];
    }

    // ------------------------------------------------------------------------------------------- the tests

    public function testTheResearchRequest(): void
    {
        $m = $this->model(self::sse(self::message([['type' => 'text', 'text' => "=== BRIEF ===\nNothing much.\n=== END ==="]], 'end_turn', ['input_tokens' => 100, 'output_tokens' => 20])));
        $out = $m->research($this->call(['tools' => self::tools(12, 6)]));
        self::assertSame('Nothing much.', $out['text']);

        $req = $this->history[0]['request'];
        self::assertSame('/v1/messages', $req->getUri()->getPath());
        self::assertSame('beta=true', $req->getUri()->getQuery());
        self::assertSame('sk-test', $req->getHeaderLine('x-api-key'));
        self::assertStringContainsString('server-side-fallback-2026-07-01', $req->getHeaderLine('anthropic-beta'));
        $body = $this->body(0);
        self::assertSame(['claude-sonnet-5-5', 32000, true, 'default'], [$body['model'], $body['max_tokens'], $body['stream'], $body['fallbacks']]);
        self::assertSame(['type' => 'adaptive'], $body['thinking']);
        self::assertSame(['effort' => 'high'], $body['output_config'], 'free text: no format with the web tools');
        self::assertSame(['type' => 'ephemeral'], $body['cache_control']);
        self::assertSame('RULES', $body['system']);
        self::assertSame([
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 12, 'user_location' => ['type' => 'approximate', 'country' => 'GB', 'timezone' => 'Europe/London'], 'blocked_domains' => ['spam.example']],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 6, 'max_content_tokens' => 8000, 'citations' => ['enabled' => false], 'blocked_domains' => ['spam.example']],
        ], $body['tools']);
        self::assertArrayNotHasKey('container', $body);

        // The client: a whole call may take 600 seconds, connecting 15, and two silent minutes drop it.
        $options = $this->history[0]['options'];
        self::assertSame([600.0, 15.0], [(float) $options['timeout'], (float) $options['connect_timeout']]);
        self::assertSame([CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 120], $options['curl']);
        self::assertSame(1, $this->befores);
    }

    public function testAPausedTurnResumesWithItsContainerWhatIsLeftAndFinallyWriteNow(): void
    {
        $container = ['id' => 'container_abc', 'expires_at' => '2026-10-22T07:00:00Z'];
        $first = self::message([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            self::search('srvtoolu_1', 'LED processor firmware'),
            self::results('srvtoolu_1', [['https://www.vendor.example/news/genlock?utm_source=feed', 'Genlock firmware', '1 day ago'], ['https://trade.example/review', 'A review', null]]),
            ...self::fetch('srvtoolu_2', 'https://vendor.example/news/genlock', 'https://vendor.example/news/genlock-4-2', 'Firmware 4.2 release notes'),
        ], 'pause_turn', ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_creation_input_tokens' => 600, 'server_tool_use' => ['web_search_requests' => 3, 'web_fetch_requests' => 1]], $container);
        $second = self::message(
            self::fetch('srvtoolu_3', 'https://trade.example/review', 'https://trade.example/review', 'A review'),
            'pause_turn',
            ['input_tokens' => 1500, 'output_tokens' => 100, 'cache_read_input_tokens' => 1600, 'server_tool_use' => ['web_search_requests' => 0, 'web_fetch_requests' => 1]],
            $container,
        );
        $third = self::message([['type' => 'text', 'text' => "=== BRIEF ===\nThe genlock firmware shipped.\n", 'citations' => [
            ['type' => 'web_search_result_location', 'url' => 'https://trade.example/review', 'title' => 'A review', 'encrypted_index' => 'EI', 'cited_text' => 'Rolling bars gone.'],
            ['type' => 'web_search_result_location', 'url' => 'https://maker.example/note', 'title' => 'Only cited', 'encrypted_index' => 'EI2', 'cited_text' => 'A note.'],
        ]], ['type' => 'text', 'text' => "Testers liked it.\n=== END ===\nIgnored."]], 'end_turn', ['input_tokens' => 1800, 'output_tokens' => 900, 'cache_read_input_tokens' => 2000, 'server_tool_use' => ['web_search_requests' => 0, 'web_fetch_requests' => 0]], $container);

        $m = $this->model(self::sse($first), self::sse($second), self::sse($third));
        $out = $m->research($this->call(['tools' => self::tools(3, 2)]));

        self::assertCount(3, $this->history);
        self::assertSame(3, $this->befores, 'the hooks run before every request');
        // Each continuation carries the container and resends the content verbatim, signatures and all.
        $two = $this->body(1);
        self::assertSame('container_abc', $two['container']);
        self::assertSame(['user', 'assistant'], array_column($two['messages'], 'role'));
        self::assertSame(['thinking', 'server_tool_use', 'web_search_tool_result', 'server_tool_use', 'web_fetch_tool_result'], array_column($two['messages'][1]['content'], 'type'));
        self::assertSame('sig-1', $two['messages'][1]['content'][0]['signature']);
        self::assertSame('ENC', $two['messages'][1]['content'][2]['content'][0]['encrypted_content']);
        // What is left: the searches are spent (the API's least is 1), one page read remains.
        self::assertSame([1, 1], array_column($two['tools'], 'max_uses'));
        self::assertNotContains(Prompts::WRITE_NOW, array_column($two['messages'], 'content'), 'one tool still has uses');

        // Both spent: the last continuation tells the model to write the brief now.
        $three = $this->body(2);
        self::assertSame('container_abc', $three['container']);
        self::assertSame(['user', 'assistant', 'assistant', 'user'], array_column($three['messages'], 'role'));
        self::assertSame(Prompts::WRITE_NOW, $three['messages'][3]['content']);
        self::assertSame([1, 1], array_column($three['tools'], 'max_uses'));

        // The brief, the references and the usage.
        self::assertSame("The genlock firmware shipped.\nTesters liked it.", $out['text']);
        self::assertSame(['end_turn', 'claude-sonnet-5-5', 'container_abc'], [$out['stop'], $out['model'], $out['container']]);
        self::assertSame([
            'S1' => ['url' => 'https://www.vendor.example/news/genlock?utm_source=feed', 'title' => 'Genlock firmware', 'via' => 'fetch', 'page_age' => '1 day ago', 'cited' => false],
            'S2' => ['url' => 'https://trade.example/review', 'title' => 'A review', 'via' => 'fetch', 'page_age' => null, 'cited' => true],
            'S3' => ['url' => 'https://vendor.example/news/genlock-4-2', 'title' => 'Firmware 4.2 release notes', 'via' => 'fetch', 'page_age' => null, 'cited' => false],
            'S4' => ['url' => 'https://maker.example/note', 'title' => 'Only cited', 'via' => 'search', 'page_age' => null, 'cited' => true],
        ], $out['refs']);
        self::assertSame(['usage_in' => 4300, 'usage_out' => 1200, 'cache_write' => 600, 'cache_read' => 3600, 'searches' => 3, 'fetches' => 2], $out['usage']);
        self::assertSame([
            [['usage_in' => 1000, 'usage_out' => 200, 'cache_write' => 600, 'cache_read' => 0, 'searches' => 3, 'fetches' => 1], 'claude-sonnet-5-5'],
            [['usage_in' => 1500, 'usage_out' => 100, 'cache_write' => 0, 'cache_read' => 1600, 'searches' => 0, 'fetches' => 1], 'claude-sonnet-5-5'],
            [['usage_in' => 1800, 'usage_out' => 900, 'cache_write' => 0, 'cache_read' => 2000, 'searches' => 0, 'fetches' => 0], 'claude-sonnet-5-5'],
        ], $this->reported, 'each response is reported as it arrives');
    }

    public function testResearchResumesAtMostFourTimes(): void
    {
        $paused = static fn (): Response => self::sse(self::message([['type' => 'text', 'text' => 'Still going.']], 'pause_turn', ['input_tokens' => 10, 'output_tokens' => 5]));
        $m = $this->model($paused(), $paused(), $paused(), $paused(), $paused(), $paused());
        $out = $m->research($this->call(['tools' => self::tools(5, 0)]));
        self::assertCount(1 + AnthropicNewsModel::MAX_CONTINUATIONS, $this->history);
        self::assertSame(['pause_turn', ''], [$out['stop'], $out['text']], 'no brief was written');
    }

    public function testResearchIsNeverRetried(): void
    {
        $m = $this->model(new Response(500, ['Content-Type' => 'application/json', 'retry-after-ms' => '1'], '{"type":"error","error":{"type":"api_error","message":"boom"}}'), self::sse(self::message([], 'end_turn', [])));
        try {
            $m->research($this->call(['tools' => self::tools(5, 2)]));
            self::fail('a server error ends the research');
        } catch (\Anthropic\Core\Exceptions\APIException) {
            self::assertCount(1, $this->history, 'a retry would run and bill the searches again');
        }
    }

    public function testAStructuredCallAsksForTheSchemaAndIsRetriedOnce(): void
    {
        $answer = ['considered' => []];
        $m = $this->model(
            new Response(529, ['Content-Type' => 'application/json', 'retry-after-ms' => '1'], '{"type":"error","error":{"type":"overloaded_error","message":"busy"}}'),
            self::sse(self::message([['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => json_encode($answer)]], 'end_turn', ['input_tokens' => 800, 'output_tokens' => 120], null, 'claude-opus-5-5')),
        );
        $out = $m->structured($this->call(['model' => 'claude-opus-5-5', 'effort' => 'medium', 'maxTokens' => 16000, 'schema' => Prompts::selectSchema()]));

        self::assertCount(2, $this->history);
        self::assertSame($answer, $out['data']);
        self::assertSame(['end_turn', 'claude-opus-5-5'], [$out['stop'], $out['model']]);
        $body = $this->body(1);
        self::assertSame(['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => Prompts::selectSchema()]], $body['output_config']);
        self::assertArrayNotHasKey('tools', $body);
        self::assertSame(['default', ['type' => 'ephemeral'], 16000], [$body['fallbacks'], $body['cache_control'], $body['max_tokens']]);
        self::assertSame(['usage_in' => 800, 'usage_out' => 120, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0], $out['usage']);
    }

    public function testTheCheckGoesToHaikuWithoutFallbacks(): void
    {
        $m = $this->model(self::sse(self::message([['type' => 'text', 'text' => '{"banned_topics":[],"names_private_individual":false,"alleges_wrongdoing":false,"reason":"Nothing found."}']], 'end_turn', ['input_tokens' => 300, 'output_tokens' => 30], null, 'claude-haiku-5-5')));
        $out = $m->structured($this->call(['model' => 'claude-haiku-5-5', 'effort' => 'low', 'maxTokens' => 4000, 'schema' => Prompts::checkSchema()]));
        self::assertFalse($out['data']['names_private_individual']);
        $body = $this->body(0);
        self::assertArrayNotHasKey('fallbacks', $body);
        self::assertStringNotContainsString('server-side-fallback', $this->history[0]['request']->getHeaderLine('anthropic-beta'));
        self::assertSame('claude-haiku-5-5', $body['model']);
    }

    public function testARefusalOrACutOffAnswerHasNoData(): void
    {
        $m = $this->model(
            self::sse(self::message([], 'refusal', ['input_tokens' => 50, 'output_tokens' => 0])),
            self::sse(self::message([['type' => 'text', 'text' => '{"title": "Half an arti']], 'max_tokens', ['input_tokens' => 50, 'output_tokens' => 24000])),
            self::sse(self::message([['type' => 'text', 'text' => '["a list, not an object"]']], 'end_turn', ['input_tokens' => 50, 'output_tokens' => 5])),
        );
        $call = $this->call(['model' => 'claude-opus-5-5', 'schema' => Prompts::articleSchema()]);
        self::assertSame([null, 'refusal'], array_values(array_intersect_key($m->structured($call), ['data' => 1, 'stop' => 1])));
        self::assertSame([null, 'max_tokens'], array_values(array_intersect_key($m->structured($call), ['data' => 1, 'stop' => 1])));
        self::assertNull($m->structured($call)['data']);
        self::assertCount(3, $this->reported, 'every answer was paid for and reported');
    }

    public function testAStreamThatEndsEarlyFailsButItsUsageIsReported(): void
    {
        $m = $this->model(self::sse(self::message([['type' => 'text', 'text' => '{"title":']], 'end_turn', ['input_tokens' => 700, 'cache_read_input_tokens' => 300], complete: false)));
        try {
            $m->structured($this->call(['schema' => Prompts::articleSchema()]));
            self::fail('an incomplete stream is a failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('stopped before it was complete', $e->getMessage());
        }
        self::assertSame(700, $this->reported[0][0]['usage_in']);
        self::assertSame(300, $this->reported[0][0]['cache_read']);
    }

    public function testAFallbacksShareIsPricedByTheModelThatServedIt(): void
    {
        $m = $this->model(self::sse(self::message([['type' => 'text', 'text' => '{"considered":[]}']], 'end_turn', [
            'input_tokens' => 1500, 'output_tokens' => 300,
            'iterations' => [
                ['type' => 'message', 'model' => 'claude-opus-5-5', 'input_tokens' => 1000, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation' => null],
                ['type' => 'fallback_message', 'model' => 'claude-opus-4-8', 'input_tokens' => 500, 'output_tokens' => 300, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation' => null],
            ],
        ], null, 'claude-opus-4-8')));
        $out = $m->structured($this->call(['model' => 'claude-opus-5-5', 'schema' => Prompts::selectSchema()]));
        self::assertSame('claude-opus-4-8', $out['model']);
        self::assertSame(['claude-opus-5-5', 'claude-opus-4-8'], array_column($this->reported, 1));
        self::assertSame([1000, 500], array_map(static fn (array $r): int => $r[0]['usage_in'], $this->reported));
        self::assertSame(['usage_in' => 1500, 'usage_out' => 300, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0], $out['usage']);
    }

    public function testABeforeHookThatThrowsSendsNothing(): void
    {
        $m = $this->model(self::sse(self::message([], 'end_turn', [])));
        try {
            $m->research($this->call(['before' => static function (): void {
                throw new \RuntimeException('over the ceiling');
            }]));
            self::fail('the hook stops the call');
        } catch (\RuntimeException $e) {
            self::assertSame('over the ceiling', $e->getMessage());
        }
        self::assertSame([], $this->history);
    }

    public function testTheBriefIsTheTextBetweenTheMarkers(): void
    {
        self::assertSame("One\n\nTwo", AnthropicNewsModel::brief("Notes\n=== BRIEF ===\nOne\n=== END ===\nchat\n=== BRIEF ===\nTwo\n=== END ===\n"));
        self::assertSame('Cut short', AnthropicNewsModel::brief("=== BRIEF ===\nCut short"));
        self::assertSame('', AnthropicNewsModel::brief('No markers at all.'));
    }
}
