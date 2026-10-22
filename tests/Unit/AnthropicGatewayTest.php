<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psc\Chat\AnthropicGateway;
use Psc\Chat\Prompts;
use Psr\Http\Client\ClientInterface;

/**
 * The assistant's real SDK path against a mocked HTTP transport: the exact requests sent to the Messages API (models,
 * structured output, thinking off, low effort, the cached system prompt, the fallbacks beta) and the parsing of a
 * real-shaped SSE stream.
 */
final class AnthropicGatewayTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function gateway(Response ...$responses): AnthropicGateway
    {
        if (!AnthropicGateway::available()) {
            self::fail('The Anthropic SDK and Guzzle must be installed (composer install).');
        }
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new AnthropicGateway('sk-test', 'claude-sonnet-5-5', 'claude-haiku-5-5', 1200, 10.0, ['transporter' => new Guzzle(['handler' => $stack])]);
    }

    /** @return array<string, mixed> */
    private function body(int $i): array
    {
        $this->history[$i]['request']->getBody()->rewind();
        return json_decode((string) $this->history[$i]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheScopeCheckRequestAndVerdict(): void
    {
        $message = [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => '{"verdict":"code_or_internals","product":"patterns","reason":"asks for a build command"}']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 20],
        ];
        $g = $this->gateway(new Response(200, ['Content-Type' => 'application/json'], json_encode($message)));
        $out = $g->classify(Prompts::gateSystem(), [['role' => 'user', 'content' => 'How do I compile Patterns?']], Prompts::gateSchema());

        self::assertSame('code_or_internals', $out['data']['verdict']);
        self::assertFalse($out['refused']);
        self::assertSame([120, 20], [$out['tokens_in'], $out['tokens_out']]);

        $req = $this->history[0]['request'];
        self::assertSame('/v1/messages', $req->getUri()->getPath());
        self::assertSame('sk-test', $req->getHeaderLine('x-api-key'));
        $body = $this->body(0);
        self::assertSame('claude-haiku-5-5', $body['model']);
        self::assertSame(['type' => 'disabled'], $body['thinking']);
        self::assertSame('low', $body['output_config']['effort']);
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(Prompts::gateSchema()['properties']['verdict']['enum'], $body['output_config']['format']['schema']['properties']['verdict']['enum']);
        self::assertArrayNotHasKey('stream', $body);
    }

    public function testTheAnswerStreamRequestAndParsing(): void
    {
        $sse = '';
        $events = [
            ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 900, 'output_tokens' => 1, 'cache_read_input_tokens' => 800, 'cache_creation_input_tokens' => 0]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'StageWatch ']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'counts down.']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 12]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
        foreach ($events as [$name, $data]) {
            $sse .= "event: {$name}\ndata: " . json_encode($data) . "\n\n";
        }
        $g = $this->gateway(new Response(200, ['Content-Type' => 'text/event-stream'], $sse));
        $seen = [];
        $system = [['type' => 'text', 'text' => Prompts::answerSystem('KNOWLEDGE TEXT', 'Patterns Show Control', 'https://example.test'), 'cacheControl' => ['type' => 'ephemeral']]];
        $out = $g->stream($system, [
            ['role' => 'user', 'content' => 'What does StageWatch do?'],
            ['role' => 'system', 'content' => 'The visitor is reading this page of the site: /stagewatch/'],
        ], static function (string $t) use (&$seen): void {
            $seen[] = $t;
        });

        self::assertSame(['StageWatch ', 'counts down.'], $seen);
        self::assertSame('StageWatch counts down.', $out['text']);
        self::assertSame('end_turn', $out['stop']);
        self::assertSame([900, 12, 800], [$out['tokens_in'], $out['tokens_out'], $out['cache_read']]);
        self::assertSame('claude-sonnet-5-5', $out['model']);

        $req = $this->history[0]['request'];
        self::assertStringContainsString('server-side-fallback-2026-07-01', $req->getHeaderLine('anthropic-beta'));
        $body = $this->body(0);
        self::assertSame('claude-sonnet-5-5', $body['model']);
        self::assertTrue($body['stream']);
        self::assertSame(['type' => 'between_tools'], $body['thinking']);
        self::assertSame(['effort' => 'low'], $body['output_config']);
        self::assertSame('default', $body['fallbacks']);
        self::assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        self::assertStringContainsString('KNOWLEDGE TEXT', $body['system'][0]['text']);
        self::assertSame(['user', 'system'], array_column($body['messages'], 'role'));
        self::assertSame(1200, $body['max_tokens']);
    }

    /** @param list<array{string, array<string, mixed>}> $events */
    private static function sse(array $events): string
    {
        $sse = '';
        foreach ($events as [$name, $data]) {
            $sse .= "event: {$name}\ndata: " . json_encode($data) . "\n\n";
        }
        return $sse;
    }

    /** @return list<array{string, array<string, mixed>}> a stream whose prompt was written to the cache */
    private static function cacheWriteStream(): array
    {
        return [
            ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_3', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 300, 'output_tokens' => 1, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 49000]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'To install, run ']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'winget install Patterns now.']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 32]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
    }

    /** The cached prompt's tokens, which the API reports apart from input_tokens, come back with the answer. */
    public function testCacheWritesAndReadsAreReported(): void
    {
        $g = $this->gateway(new Response(200, ['Content-Type' => 'text/event-stream'], self::sse(self::cacheWriteStream())));
        $out = $g->stream([['type' => 'text', 'text' => 'S']], [['role' => 'user', 'content' => 'q']], static function (string $t): void {
        });
        self::assertSame([300, 32, 49000, 0], [$out['tokens_in'], $out['tokens_out'], $out['cache_write'], $out['cache_read']]);
        self::assertSame(['tokens_in' => 300, 'tokens_out' => 32, 'cache_write' => 49000, 'cache_read' => 0], $g->lastUsage());
    }

    /** @return array{transporter: mixed, streamingTransporter: mixed, maxRetries: mixed} the SDK options the gateway's client sends with */
    private static function options(AnthropicGateway $g): array
    {
        $client = (new \ReflectionProperty(AnthropicGateway::class, 'client'))->getValue($g);
        self::assertInstanceOf(\Anthropic\Client::class, $client);
        $o = $client->mergedOptions(null);
        return ['transporter' => $o->transporter, 'streamingTransporter' => $o->streamingTransporter, 'maxRetries' => $o->maxRetries];
    }

    /** Whether $client is the streaming client AnthropicGateway::streaming() makes, not the SDK's wrapper of a transporter. */
    private static function isTheGatewaysOwn(mixed $client): bool
    {
        $r = new \ReflectionClass($client);
        return $r->isAnonymous() && $r->getFileName() === (new \ReflectionClass(AnthropicGateway::class))->getFileName();
    }

    private static function innerHandler(Guzzle $http): mixed
    {
        $stack = $http->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        return (new \ReflectionProperty(HandlerStack::class, 'handler'))->getValue($stack);
    }

    /**
     * The SDK never reads its own timeout option, so the gateway's HTTP clients carry chat.timeout_seconds: curl for
     * the scope check (the whole call, 10 seconds to connect, dropped after 30 seconds of nothing) and a client of its
     * own for the streamed answer.
     */
    public function testTheDefaultClientsCarryTheTimeouts(): void
    {
        self::assertTrue(AnthropicGateway::available(), 'the SDK, Guzzle and curl are installed');
        $http = AnthropicGateway::http(42.0);
        self::assertSame(42.0, $http->getConfig('timeout'));
        self::assertSame(10, $http->getConfig('connect_timeout'));
        self::assertSame([CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 30], $http->getConfig('curl'));
        self::assertInstanceOf(CurlHandler::class, self::innerHandler($http));

        $o = self::options(new AnthropicGateway('sk-test', 'claude-sonnet-5-5', 'claude-haiku-5-5', 1200, 42.0));
        self::assertInstanceOf(Guzzle::class, $o['transporter']);
        self::assertSame(42.0, $o['transporter']->getConfig('timeout'), 'chat.timeout_seconds reaches the transport');
        self::assertSame(10, $o['transporter']->getConfig('connect_timeout'));
        self::assertSame([CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 30], $o['transporter']->getConfig('curl'));
        self::assertInstanceOf(CurlHandler::class, self::innerHandler($o['transporter']));
        self::assertSame(1, $o['maxRetries']);
        self::assertTrue(filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN), 'the test runs with allow_url_fopen on');
        self::assertInstanceOf(ClientInterface::class, $o['streamingTransporter']);
        self::assertTrue(self::isTheGatewaysOwn($o['streamingTransporter']), 'the answer streams through the gateway\'s own client');

        $mine = new Guzzle(['handler' => HandlerStack::create(new MockHandler([]))]);
        $passed = self::options(new AnthropicGateway('sk-test', 'claude-sonnet-5-5', 'claude-haiku-5-5', 1200, 42.0, ['transporter' => $mine]));
        self::assertSame($mine, $passed['transporter'], 'a transporter passed in is kept');
        self::assertFalse(self::isTheGatewaysOwn($passed['streamingTransporter']), 'and carries the streams too');
    }

    /** The answer's own client hands the stream on as it arrives, through the SDK's parsing, to the same result. */
    public function testTheAnswerStreamsThroughItsOwnClient(): void
    {
        $streamed = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], self::sse(self::cacheWriteStream()))]));
        $stack->push(Middleware::history($streamed));
        $g = new AnthropicGateway('sk-test', 'claude-sonnet-5-5', 'claude-haiku-5-5', 1200, 10.0, [
            'transporter' => new Guzzle(['handler' => HandlerStack::create(new MockHandler([]))]),
            'streamingTransporter' => AnthropicGateway::streaming(10.0, $stack),
        ]);
        $out = $g->stream([['type' => 'text', 'text' => 'S']], [['role' => 'user', 'content' => 'q']], static function (string $t): void {
        });
        self::assertSame('To install, run winget install Patterns now.', $out['text']);
        self::assertSame([300, 32, 49000], [$out['tokens_in'], $out['tokens_out'], $out['cache_write']]);
        self::assertCount(1, $streamed, 'one request, through the streaming client');
        self::assertTrue($streamed[0]['options']['stream']);
        self::assertSame(30, $streamed[0]['options']['read_timeout']);
        self::assertSame(30, $streamed[0]['options']['timeout']);
    }

    /**
     * A real socket that sends the start of an answer and then nothing: the read that waits out the socket's timeout
     * fails, the call ends and what the start reported is kept. The writer stays open, so only the timeout ends it.
     */
    public function testAStreamThatStopsSendingIsDropped(): void
    {
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) ?: self::fail('a socket pair');
        fwrite($write, self::sse(array_slice(self::cacheWriteStream(), 0, 3)));
        stream_set_timeout($read, 0, 200000);
        $g = new AnthropicGateway('sk-test', 'claude-sonnet-5-5', 'claude-haiku-5-5', 1200, 10.0, [
            'transporter' => new Guzzle(['handler' => HandlerStack::create(new MockHandler([]))]),
            'streamingTransporter' => AnthropicGateway::streaming(10.0, new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($read))])),
        ]);
        $seen = [];
        $started = hrtime(true);
        try {
            $g->stream([['type' => 'text', 'text' => 'S']], [['role' => 'user', 'content' => 'q']], static function (string $t) use (&$seen): void {
                $seen[] = $t;
            });
            self::fail('a stalled stream ends the call');
        } catch (\RuntimeException) {
            self::assertSame(['To install, run '], $seen, 'the text before the stall was handed on as it arrived');
            self::assertSame(300, $g->lastUsage()['tokens_in']);
            self::assertSame(49000, $g->lastUsage()['cache_write']);
            self::assertLessThan(5 * 1_000_000_000, hrtime(true) - $started, 'ended by the timeout, not by waiting');
        } finally {
            fclose($write);
        }
    }

    /** A read that comes back empty because it timed out (rather than failing) ends the call too. */
    public function testAnEmptyTimedOutReadEndsTheCallToo(): void
    {
        $reads = 0;
        $stalled = FnStream::decorate(Utils::streamFor(''), [
            'read' => static function () use (&$reads): string {
                ++$reads;
                return '';
            },
            // Ends after a few reads, so a fence that failed cannot hang the suite.
            'eof' => static function () use (&$reads): bool {
                return $reads >= 3;
            },
            'getMetadata' => static fn (?string $key = null): mixed => $key === 'timed_out' ? true : null,
        ]);
        $g = new AnthropicGateway('sk-test', 'claude-sonnet-5-5', 'claude-haiku-5-5', 1200, 10.0, [
            'transporter' => new Guzzle(['handler' => HandlerStack::create(new MockHandler([]))]),
            'streamingTransporter' => AnthropicGateway::streaming(10.0, new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], $stalled)])),
        ]);
        try {
            $g->stream([['type' => 'text', 'text' => 'S']], [['role' => 'user', 'content' => 'q']], static function (string $t): void {
            });
            self::fail('an empty timed-out read ends the call');
        } catch (\RuntimeException $e) {
            self::assertSame(1, $reads, 'the first empty read that timed out ends it');
            self::assertSame('Nothing arrived for 30 seconds.', $e->getMessage());
        }
    }

    /** A stream still running when chat.timeout_seconds is up ends the call at the next read. */
    public function testAStreamPastItsTimeIsDropped(): void
    {
        $client = AnthropicGateway::streaming(0.0, new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], self::sse(self::cacheWriteStream()))]));
        $response = $client->sendRequest(new \GuzzleHttp\Psr7\Request('POST', 'https://api.anthropic.com/v1/messages', ['Accept' => 'text/event-stream']));
        self::assertSame(200, $response->getStatusCode());
        try {
            $response->getBody()->read(64);
            self::fail('the read after the deadline fails');
        } catch (\RuntimeException $e) {
            self::assertSame('The answer took longer than 0 seconds.', $e->getMessage());
        }
    }

    /** A stream stopped by its callback (the code guard) still reports what it spent, cached prompt included. */
    public function testAStreamStoppedEarlyStillReportsItsUsage(): void
    {
        $g = $this->gateway(new Response(200, ['Content-Type' => 'text/event-stream'], self::sse(self::cacheWriteStream())));
        try {
            $g->stream([['type' => 'text', 'text' => 'S']], [['role' => 'user', 'content' => 'q']], static function (string $t): void {
                if (str_contains($t, 'winget')) {
                    throw new \Psc\Chat\CodeGuardTripped();
                }
            });
            self::fail('the callback stops the stream');
        } catch (\Psc\Chat\CodeGuardTripped) {
            $u = $g->lastUsage();
            self::assertSame([300, 49000, 0], [$u['tokens_in'], $u['cache_write'], $u['cache_read']]);
            self::assertSame(intdiv(strlen('To install, run winget install Patterns now.') + 3, 4), $u['tokens_out'], 'output counted from the text the model produced');
        }
    }
}
