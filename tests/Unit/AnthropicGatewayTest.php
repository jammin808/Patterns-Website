<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psc\Chat\AnthropicGateway;
use Psc\Chat\Prompts;

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
}
