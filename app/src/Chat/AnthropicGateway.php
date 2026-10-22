<?php

declare(strict_types=1);

namespace Psc\Chat;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The assistant's model calls through the official Anthropic PHP SDK.
 *
 * - The scope check runs on the gate model (Claude Haiku 5.5) with thinking off, low effort and a JSON-schema
 *   structured output, so its verdict is always one of the allowed values.
 * - The answer streams from the answer model (Claude Sonnet 5.5) with thinking off (between_tools: no tools are
 *   offered, so no reasoning is generated or replayed) and low effort; the long system prompt is cached.
 *   Server-side fallbacks ("default") retry a classifier decline on the model Anthropic recommends.
 * - Usage is kept as the stream runs, cache writes and reads included (the API reports them apart from
 *   input_tokens), so lastUsage() answers for a stream that was stopped early too.
 * - The SDK enforces no timeout of its own (it never reads its timeout option), so the HTTP clients carry the
 *   limits: a call ends after the configured timeout, a connection takes at most CONNECT_TIMEOUT seconds, and a
 *   call that receives nothing for LOW_SPEED_TIME seconds is dropped.
 */
final class AnthropicGateway implements ModelGateway
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Seconds to open a connection to the API. */
    public const CONNECT_TIMEOUT = 10;

    /** A call that receives less than LOW_SPEED_LIMIT bytes a second for LOW_SPEED_TIME seconds is dropped. */
    public const LOW_SPEED_LIMIT = 1;
    public const LOW_SPEED_TIME = 30;

    private Client $client;

    /** @var array{tokens_in: int, tokens_out: int, cache_write: int, cache_read: int} */
    private array $usage = ['tokens_in' => 0, 'tokens_out' => 0, 'cache_write' => 0, 'cache_read' => 0];

    /**
     * @param float $timeoutSeconds the longest one call may take, chat.timeout_seconds
     * @param array<string, mixed> $requestOptions extra SDK request options; a transporter passed here (tests pass
     *     one) carries every call, streams included, in place of the clients below
     */
    public function __construct(
        string $apiKey,
        private readonly string $model,
        private readonly string $gateModel,
        private readonly int $maxTokens,
        float $timeoutSeconds,
        array $requestOptions = [],
    ) {
        if (!isset($requestOptions['transporter'])) {
            $requestOptions['transporter'] = self::http($timeoutSeconds);
            // Without allow_url_fopen there is no stream handler, and the SDK streams through curl, all at once.
            if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                $requestOptions['streamingTransporter'] ??= self::streaming($timeoutSeconds);
            }
        }
        $this->client = new Client(
            apiKey: $apiKey,
            requestOptions: ['maxRetries' => 1] + $requestOptions,
            openTelemetry: false,
        );
    }

    /** Whether the SDK, Guzzle and curl are installed. */
    public static function available(): bool
    {
        return class_exists(Client::class) && class_exists(\GuzzleHttp\Client::class) && class_exists(CurlHandler::class) && extension_loaded('curl');
    }

    /**
     * The client for every call that is not streamed (the scope check), on curl: $timeoutSeconds for the whole call,
     * CONNECT_TIMEOUT to connect, and dropped after LOW_SPEED_TIME seconds of receiving nothing.
     */
    public static function http(float $timeoutSeconds): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'handler' => HandlerStack::create(new CurlHandler()),
            'timeout' => $timeoutSeconds,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'curl' => [CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT, CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME],
        ]);
    }

    /**
     * The client for the streamed answer, so the visitor reads it as it is written: curl hands a body over only once
     * the whole of it has arrived, so streams go through PHP's own stream handler. The limits are the same: a wait
     * of LOW_SPEED_TIME seconds for the connection, the headers or the next bytes ends the call, and so does reading
     * past $timeoutSeconds from the start. A read that times out fails (PHP returns false and the body throws); one
     * that comes back empty because it timed out is failed here too, so a stalled stream is never waited on for ever.
     *
     * @param callable|null $handler the HTTP handler (tests pass a mock); null: PHP's stream handler
     */
    public static function streaming(float $timeoutSeconds, ?callable $handler = null): ClientInterface
    {
        // The stream handler's timeout is each wait on the socket (connecting, the headers), not the whole call.
        $http = new \GuzzleHttp\Client([
            'handler' => HandlerStack::create($handler ?? new StreamHandler()),
            'timeout' => self::LOW_SPEED_TIME,
            'read_timeout' => self::LOW_SPEED_TIME,
        ]);
        return new class ($http, $timeoutSeconds) implements ClientInterface {
            public function __construct(private readonly \GuzzleHttp\Client $http, private readonly float $timeoutSeconds)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $deadline = hrtime(true) + (int) ($this->timeoutSeconds * 1e9);
                // The SDK's own options for a stream: error statuses and redirects come back as responses.
                $response = $this->http->send($request, ['stream' => true, 'http_errors' => false, 'allow_redirects' => false]);
                $body = $response->getBody();
                $limit = $this->timeoutSeconds;
                return $response->withBody(FnStream::decorate($body, [
                    'read' => static function (int $length) use ($body, $deadline, $limit): string {
                        if (hrtime(true) >= $deadline) {
                            throw new \RuntimeException('The answer took longer than ' . $limit . ' seconds.');
                        }
                        $chunk = $body->read($length);
                        if ($chunk === '' && $body->getMetadata('timed_out') === true) {
                            throw new \RuntimeException('Nothing arrived for ' . AnthropicGateway::LOW_SPEED_TIME . ' seconds.');
                        }
                        return $chunk;
                    },
                ]));
            }
        };
    }

    public function classify(string $system, array $messages, array $schema): array
    {
        $message = $this->client->messages->create(
            maxTokens: 300,
            messages: $messages,
            model: $this->gateModel,
            system: $system,
            thinking: ['type' => 'disabled'],
            outputConfig: [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        );
        $usageIn = (int) $message->usage->inputTokens;
        $usageOut = (int) $message->usage->outputTokens;
        if ($message->stopReason === 'refusal') {
            return ['data' => null, 'refused' => true, 'tokens_in' => $usageIn, 'tokens_out' => $usageOut];
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);
        return ['data' => is_array($data) ? $data : null, 'refused' => false, 'tokens_in' => $usageIn, 'tokens_out' => $usageOut];
    }

    public function stream(array $system, array $messages, callable $onText): array
    {
        $this->usage = ['tokens_in' => 0, 'tokens_out' => 0, 'cache_write' => 0, 'cache_read' => 0];
        $stream = $this->client->beta->messages->createStream(
            maxTokens: $this->maxTokens,
            messages: $messages,
            model: $this->model,
            system: $system,
            thinking: ['type' => 'between_tools'],
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: [self::FALLBACK_BETA],
        );
        $text = '';
        $stop = 'end_turn';
        $in = 0;
        $out = 0;
        $cacheWrite = 0;
        $cacheRead = 0;
        $served = $this->model;
        $complete = false;
        try {
            foreach ($stream as $event) {
                if ($event instanceof BetaRawMessageStartEvent) {
                    $u = $event->message->usage;
                    $in = (int) $u->inputTokens;
                    $out = (int) $u->outputTokens;
                    $cacheWrite = (int) ($u->cacheCreationInputTokens ?? 0);
                    $cacheRead = (int) ($u->cacheReadInputTokens ?? 0);
                    $served = (string) $event->message->model;
                } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                    $text .= $event->delta->text;
                    $onText($event->delta->text);
                } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                    // message_delta carries cumulative counts; a field it leaves out keeps message_start's value.
                    $stop = (string) ($event->delta->stopReason ?? $stop);
                    $out = (int) $event->usage->outputTokens;
                    $in = $event->usage->inputTokens !== null ? (int) $event->usage->inputTokens : $in;
                    $cacheWrite = $event->usage->cacheCreationInputTokens !== null ? (int) $event->usage->cacheCreationInputTokens : $cacheWrite;
                    $cacheRead = $event->usage->cacheReadInputTokens !== null ? (int) $event->usage->cacheReadInputTokens : $cacheRead;
                }
                $this->usage = ['tokens_in' => $in, 'tokens_out' => $out, 'cache_write' => $cacheWrite, 'cache_read' => $cacheRead];
            }
            $complete = true;
        } finally {
            if (!$complete) {
                // Output is counted only at the end of a stream: one cut short counts the text it produced at about
                // four characters a token, so the budget never takes a withdrawn answer as free.
                $this->usage['tokens_out'] = max($out, intdiv(strlen($text) + 3, 4));
            }
        }
        return ['text' => $text, 'stop' => $stop, 'tokens_in' => $in, 'tokens_out' => $out, 'cache_write' => $cacheWrite, 'cache_read' => $cacheRead, 'model' => $served];
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }
}
