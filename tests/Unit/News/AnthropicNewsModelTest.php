<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psc\News\AnthropicNewsModel;
use Psc\News\Prompts;

/**
 * The desk's real SDK path against a mocked HTTP transport (docs/NEWS.md §12 Unit): the requests sent (tools, their
 * limits, the location, the output format, the fallbacks beta, the cache markers, the streaming client's timeouts), a
 * paused research turn resumed as it is with its container and what is left of its allowance, the fresh call that
 * writes the brief once the allowance is spent, the references from searches, page reads and citations, the usage
 * summed by the model that served it, a broken stream's usage, and the one resend an overload before any event gets.
 */
final class AnthropicNewsModelTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var list<array{0: array<string, int>, 1: string}> what the after hook was told */
    private array $reported = [];

    private int $befores = 0;

    private function model(Response|\Throwable ...$responses): AnthropicNewsModel
    {
        return $this->timed(600, ...$responses);
    }

    private function timed(float $timeout, Response|\Throwable ...$responses): AnthropicNewsModel
    {
        if (!AnthropicNewsModel::available()) {
            self::fail('The Anthropic SDK, Guzzle and curl must be installed (composer install).');
        }
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new AnthropicNewsModel('sk-test', $timeout, 15, $stack);
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
        return new Response(200, ['Content-Type' => 'text/event-stream'], self::events($events));
    }

    /** @param list<array<string, mixed>> $events */
    private static function events(array $events): string
    {
        $body = '';
        foreach ($events as $e) {
            $body .= 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n";
        }
        return $body;
    }

    /**
     * A stream whose body hands over $chunks one read at a time, each a string, or a callable that returns one or
     * throws (the connection breaking partway).
     *
     * @param list<string|callable(): string> $chunks
     */
    private static function pumped(array $chunks): Response
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], FnStream::decorate(Utils::streamFor(''), [
            'read' => static function () use (&$chunks): string {
                $next = array_shift($chunks) ?? '';
                return is_callable($next) ? $next() : $next;
            },
            'eof' => static function () use (&$chunks): bool {
                return $chunks === [];
            },
        ]));
    }

    private static function overloaded(int $status = 529): Response
    {
        return new Response($status, ['Content-Type' => 'application/json', 'retry-after-ms' => '1'], '{"type":"error","error":{"type":"' . ($status === 429 ? 'rate_limit_error' : 'overloaded_error') . '","message":"busy"}}');
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
        self::assertSame('0', $req->getHeaderLine('x-stainless-retry-count'));
        self::assertStringContainsString('server-side-fallback-2026-07-01', $req->getHeaderLine('anthropic-beta'));
        $body = $this->body(0);
        self::assertSame(['claude-sonnet-5-5', 32000, true, 'default'], [$body['model'], $body['max_tokens'], $body['stream'], $body['fallbacks']]);
        self::assertSame(['type' => 'adaptive'], $body['thinking']);
        self::assertSame(['effort' => 'high'], $body['output_config'], 'free text: no format with the web tools');
        self::assertSame(['type' => 'ephemeral'], $body['cache_control'], 'research continuations read their history from the cache');
        self::assertSame('RULES', $body['system']);
        self::assertSame([
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 12, 'user_location' => ['type' => 'approximate', 'country' => 'GB', 'timezone' => 'Europe/London'], 'blocked_domains' => ['spam.example']],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'max_uses' => 6, 'max_content_tokens' => 8000, 'citations' => ['enabled' => false], 'blocked_domains' => ['spam.example']],
        ], $body['tools']);
        self::assertArrayNotHasKey('container', $body);

        // The streaming client: each wait on the socket is at most two minutes, and the stream is read as it comes.
        $options = $this->history[0]['options'];
        self::assertSame([120.0, 120.0, true], [(float) $options['timeout'], (float) $options['read_timeout'], $options['stream']]);
        self::assertArrayNotHasKey('curl', $options, 'not curl, which hands a body over only once all of it has arrived');
        self::assertSame(1, $this->befores);
    }

    public function testAPausedTurnIsResumedAsItIsAndASpentAllowanceWritesTheBriefInAFreshCall(): void
    {
        $container = ['id' => 'container_abc', 'expires_at' => '2026-10-22T07:00:00Z'];
        $first = self::message([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            self::search('srvtoolu_1', 'LED processor firmware'),
            self::results('srvtoolu_1', [['https://www.vendor.example/news/genlock?utm_source=feed', 'Genlock firmware', '1 day ago'], ['https://trade.example/review', 'A review', null]]),
            ...self::fetch('srvtoolu_2', 'https://vendor.example/news/genlock', 'https://vendor.example/news/genlock-4-2', 'Firmware 4.2 release notes'),
            // The server paused with a call it has yet to run: the turn goes back exactly so, with nothing after it.
            self::search('srvtoolu_9', 'genlock firmware review'),
        ], 'pause_turn', ['input_tokens' => 1000, 'output_tokens' => 200, 'cache_creation_input_tokens' => 600, 'server_tool_use' => ['web_search_requests' => 3, 'web_fetch_requests' => 1]], $container);
        $second = self::message([
            ['type' => 'text', 'text' => "Firmware 4.2 adds frame-accurate genlock, per the release notes.\n", 'citations' => [
                ['type' => 'web_search_result_location', 'url' => 'https://maker.example/note', 'title' => 'Only cited', 'encrypted_index' => 'EI2', 'cited_text' => 'A note.'],
            ]],
            ...self::fetch('srvtoolu_3', 'https://trade.example/review', 'https://trade.example/review', 'A review'),
            self::search('srvtoolu_4', 'one more search'),
        ], 'pause_turn', ['input_tokens' => 1500, 'output_tokens' => 100, 'cache_read_input_tokens' => 1600, 'server_tool_use' => ['web_search_requests' => 0, 'web_fetch_requests' => 1]], $container);
        $third = self::message([['type' => 'text', 'text' => "=== BRIEF ===\nThe genlock firmware shipped (https://vendor.example/news/genlock-4-2).\nTesters liked it.\n=== END ==="]], 'end_turn', ['input_tokens' => 1800, 'output_tokens' => 900]);

        $m = $this->model(self::sse($first), self::sse($second), self::sse($third));
        $out = $m->research($this->call(['tools' => self::tools(3, 2)]));

        self::assertCount(3, $this->history);
        self::assertSame(3, $this->befores, 'the hooks run before every request');
        // The continuation carries the container and resends the content verbatim, its pending call last.
        $two = $this->body(1);
        self::assertSame('container_abc', $two['container']);
        self::assertSame(['user', 'assistant'], array_column($two['messages'], 'role'), 'nothing is added after paused content');
        self::assertSame(['thinking', 'server_tool_use', 'web_search_tool_result', 'server_tool_use', 'web_fetch_tool_result', 'server_tool_use'], array_column($two['messages'][1]['content'], 'type'));
        self::assertSame('sig-1', $two['messages'][1]['content'][0]['signature']);
        self::assertSame('ENC', $two['messages'][1]['content'][2]['content'][0]['encrypted_content']);
        // What is left: the searches are spent (the API's least is 1), one page read remains.
        self::assertSame([1, 1], array_column($two['tools'], 'max_uses'));
        self::assertSame(['type' => 'ephemeral'], $two['cache_control']);

        // Both spent: a fresh call with no tools and one message writes the brief from what was found.
        $three = $this->body(2);
        self::assertSame(['user'], array_column($three['messages'], 'role'));
        self::assertArrayNotHasKey('tools', $three);
        self::assertArrayNotHasKey('container', $three);
        self::assertArrayNotHasKey('cache_control', $three, 'a one-off prompt is never read back');
        self::assertSame('RULES', $three['system']);
        $turn = $three['messages'][0]['content'];
        self::assertStringStartsWith("Research the week.\n\n" . Prompts::WRITE_NOW, $turn);
        self::assertStringContainsString("The research so far:\nFirmware 4.2 adds frame-accurate genlock, per the release notes.", $turn);
        self::assertStringContainsString('- https://vendor.example/news/genlock-4-2 | Firmware 4.2 release notes | age unknown | read', $turn);
        self::assertStringContainsString('- https://www.vendor.example/news/genlock?utm_source=feed | Genlock firmware | 1 day ago | read', $turn);
        self::assertStringContainsString('untrusted', Prompts::WRITE_NOW . Prompts::researchRules());

        // The brief is the fresh call's, the references every address seen, the usage every response's.
        self::assertSame("The genlock firmware shipped (https://vendor.example/news/genlock-4-2).\nTesters liked it.", $out['text']);
        self::assertSame(['end_turn', 'claude-sonnet-5-5', 'container_abc'], [$out['stop'], $out['model'], $out['container']]);
        self::assertSame([
            'S1' => ['url' => 'https://www.vendor.example/news/genlock?utm_source=feed', 'title' => 'Genlock firmware', 'via' => 'fetch', 'page_age' => '1 day ago', 'cited' => false],
            'S2' => ['url' => 'https://trade.example/review', 'title' => 'A review', 'via' => 'fetch', 'page_age' => null, 'cited' => false],
            'S3' => ['url' => 'https://vendor.example/news/genlock-4-2', 'title' => 'Firmware 4.2 release notes', 'via' => 'fetch', 'page_age' => null, 'cited' => false],
            'S4' => ['url' => 'https://maker.example/note', 'title' => 'Only cited', 'via' => 'search', 'page_age' => null, 'cited' => true],
        ], $out['refs']);
        self::assertSame(['usage_in' => 4300, 'usage_out' => 1200, 'cache_write' => 600, 'cache_read' => 1600, 'searches' => 3, 'fetches' => 2], $out['usage']);
        self::assertSame([
            [['usage_in' => 1000, 'usage_out' => 200, 'cache_write' => 600, 'cache_read' => 0, 'searches' => 3, 'fetches' => 1], 'claude-sonnet-5-5'],
            [['usage_in' => 1500, 'usage_out' => 100, 'cache_write' => 0, 'cache_read' => 1600, 'searches' => 0, 'fetches' => 1], 'claude-sonnet-5-5'],
            [['usage_in' => 1800, 'usage_out' => 900, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0], 'claude-sonnet-5-5'],
        ], $this->reported, 'each response is reported as it arrives');
    }

    public function testResearchResumesAtMostFourTimesThenWritesTheBrief(): void
    {
        $paused = static fn (): Response => self::sse(self::message([['type' => 'text', 'text' => 'Still going.']], 'pause_turn', ['input_tokens' => 10, 'output_tokens' => 5]));
        $written = self::sse(self::message([['type' => 'text', 'text' => "=== BRIEF ===\nWhat there was.\n=== END ==="]], 'end_turn', ['input_tokens' => 10, 'output_tokens' => 5]));
        $m = $this->model($paused(), $paused(), $paused(), $paused(), $paused(), $written);
        $out = $m->research($this->call(['tools' => self::tools(5, 0)]));
        self::assertCount(1 + AnthropicNewsModel::MAX_CONTINUATIONS + 1, $this->history);
        self::assertArrayNotHasKey('tools', $this->body(5), 'the last call writes the brief without tools');
        self::assertSame(['end_turn', 'What there was.'], [$out['stop'], $out['text']]);
    }

    public function testAnOverloadBeforeAnyEventIsSentOnceMoreAfterTheBeforeHook(): void
    {
        $answer = self::sse(self::message([['type' => 'text', 'text' => "=== BRIEF ===\nShipped.\n=== END ==="]], 'end_turn', ['input_tokens' => 100, 'output_tokens' => 20]));
        $m = $this->model(self::overloaded(529), $answer);
        self::assertSame('Shipped.', $m->research($this->call(['tools' => self::tools(5, 2)]))['text']);
        self::assertCount(2, $this->history, 'an overloaded answer generated nothing, so nothing is billed twice');
        self::assertSame(2, $this->befores, 'the deadline, ceiling and lease are checked again before the resend');
        self::assertCount(1, $this->reported);

        // A rate limit, and an overloaded error sent as the stream's first event, are resent the same way.
        $this->history = [];
        $m = $this->model(self::overloaded(429), self::sse(self::message([], 'end_turn', ['input_tokens' => 5])));
        $m->structured($this->call(['schema' => Prompts::selectSchema()]));
        self::assertCount(2, $this->history);
        $this->history = [];
        $event = new Response(200, ['Content-Type' => 'text/event-stream', 'retry-after-ms' => '1'], "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n");
        $m = $this->model($event, self::sse(self::message([], 'end_turn', ['input_tokens' => 5])));
        $m->structured($this->call(['schema' => Prompts::selectSchema()]));
        self::assertCount(2, $this->history);
    }

    public function testASecondOverloadEndsTheCall(): void
    {
        $m = $this->model(self::overloaded(), self::overloaded(), self::sse(self::message([], 'end_turn', [])));
        try {
            $m->research($this->call(['tools' => self::tools(5, 2)]));
            self::fail('one resend only');
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            self::assertSame(529, $e->status);
        }
        self::assertCount(2, $this->history);
    }

    public function testAServerErrorOrABrokenConnectionIsNeverResent(): void
    {
        $m = $this->model(new Response(500, ['Content-Type' => 'application/json', 'retry-after-ms' => '1'], '{"type":"error","error":{"type":"api_error","message":"boom"}}'), self::sse(self::message([], 'end_turn', [])));
        try {
            $m->research($this->call(['tools' => self::tools(5, 2)]));
            self::fail('a server error ends the research');
        } catch (\Anthropic\Core\Exceptions\APIException) {
            self::assertCount(1, $this->history, 'the request may have started: a resend could bill its searches again');
        }

        $this->history = [];
        $m = $this->model(new ConnectException('Connection reset', new Request('POST', 'https://api.anthropic.com/v1/messages')), self::sse(self::message([], 'end_turn', [])));
        try {
            $m->structured($this->call(['schema' => Prompts::articleSchema()]));
            self::fail('a broken connection ends the call');
        } catch (\Anthropic\Core\Exceptions\APIConnectionException) {
            self::assertCount(1, $this->history, 'the SDK never resends: it would bill the call twice unseen');
        }
    }

    public function testAStructuredCallAsksForTheSchemaAndCachesNothingItself(): void
    {
        $answer = ['considered' => []];
        $m = $this->model(self::sse(self::message([['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => json_encode($answer)]], 'end_turn', ['input_tokens' => 800, 'output_tokens' => 120], null, 'claude-opus-5-5')));
        $out = $m->structured($this->call(['model' => 'claude-opus-5-5', 'effort' => 'medium', 'maxTokens' => 16000, 'schema' => Prompts::selectSchema()]));

        self::assertCount(1, $this->history);
        self::assertSame($answer, $out['data']);
        self::assertSame(['end_turn', 'claude-opus-5-5'], [$out['stop'], $out['model']]);
        $body = $this->body(0);
        self::assertSame(['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => Prompts::selectSchema()]], $body['output_config']);
        self::assertArrayNotHasKey('tools', $body);
        self::assertArrayNotHasKey('cache_control', $body, 'a one-off prompt pays the write and never reads it back');
        self::assertSame(['default', 16000], [$body['fallbacks'], $body['max_tokens']]);
        self::assertSame(['usage_in' => 800, 'usage_out' => 120, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0], $out['usage']);
    }

    public function testAMessageMarksWhatItSharesForTheCache(): void
    {
        $m = $this->model(self::sse(self::message([['type' => 'text', 'text' => '{"title":"x"}']], 'end_turn', ['input_tokens' => 50, 'cache_read_input_tokens' => 9000], null, 'claude-opus-5-5')));
        $out = $m->structured($this->call(['model' => 'claude-opus-5-5', 'schema' => Prompts::articleSchema(), 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'The brief, the references and the context.', 'cacheControl' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'This story.'],
        ]]]]));
        $body = $this->body(0);
        self::assertSame(['type' => 'ephemeral'], $body['messages'][0]['content'][0]['cache_control']);
        self::assertArrayNotHasKey('cache_control', $body['messages'][0]['content'][1]);
        self::assertArrayNotHasKey('cache_control', $body);
        self::assertSame(9000, $out['usage']['cache_read']);
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

    public function testAStreamThatBreaksAfterItStartedReportsWhatItHadToldAndIsNotResent(): void
    {
        $events = self::message([
            self::search('srvtoolu_1', 'genlock'),
            self::results('srvtoolu_1', [['https://vendor.example/a', 'A', null]]),
            ['type' => 'text', 'text' => str_repeat('Twelve chars', 100)],
        ], 'end_turn', ['input_tokens' => 250000, 'cache_read_input_tokens' => 4000], complete: false);
        $m = $this->model(self::pumped([self::events($events), static function (): string {
            throw new \RuntimeException('Connection reset by peer');
        }]), self::sse(self::message([], 'end_turn', [])));
        try {
            $m->research($this->call(['tools' => self::tools(5, 2)]));
            self::fail('a broken stream ends the call');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Connection reset', $e->getMessage());
        }
        self::assertCount(1, $this->history, 'the call had started, so it is never resent');
        self::assertCount(1, $this->reported, 'what it cost is recorded');
        [$usage, $model] = $this->reported[0];
        self::assertSame('claude-sonnet-5-5', $model);
        self::assertSame([250000, 4000, 1], [$usage['usage_in'], $usage['cache_read'], $usage['searches']], 'message_start and the results that arrived');
        self::assertSame(300, $usage['usage_out'], 'the 1,200 characters it streamed, at about four a token');
    }

    public function testAStreamPastItsTimeoutFailsAndReportsItsUsage(): void
    {
        $start = self::events(array_slice(self::message([], 'end_turn', ['input_tokens' => 5000]), 0, 1));
        $m = $this->timed(0.05, self::pumped([$start, static function (): string {
            usleep(100000);
            return "event: ping\ndata: {\"type\":\"ping\"}\n\n";
        }, "event: ping\ndata: {\"type\":\"ping\"}\n\n"]));
        try {
            $m->structured($this->call(['schema' => Prompts::selectSchema()]));
            self::fail('a call past its timeout ends');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('took longer than 0.05 seconds', $e->getMessage());
        }
        self::assertSame(5000, $this->reported[0][0]['usage_in']);
        self::assertCount(1, $this->history);
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

    public function testTheBriefIsTheFinishedTextBetweenTheMarkers(): void
    {
        self::assertSame("One\n\nTwo", AnthropicNewsModel::brief("Notes\n=== BRIEF ===\nOne\n=== END ===\nchat\n=== BRIEF ===\nTwo\n=== END ===\n"));
        self::assertSame('', AnthropicNewsModel::brief("=== BRIEF ===\nCut short"), 'a brief without its end line was cut short');
        self::assertSame('One', AnthropicNewsModel::brief("=== BRIEF ===\nOne\n=== END ===\n=== BRIEF ===\nTwo, cut"));
        self::assertSame('', AnthropicNewsModel::brief('No markers at all.'));
    }
}
