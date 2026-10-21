<?php

declare(strict_types=1);

namespace Psc\Chat;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageStartEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;

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
 */
final class AnthropicGateway implements ModelGateway
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private Client $client;

    /** @var array{tokens_in: int, tokens_out: int, cache_write: int, cache_read: int} */
    private array $usage = ['tokens_in' => 0, 'tokens_out' => 0, 'cache_write' => 0, 'cache_read' => 0];

    /** @param array<string, mixed> $requestOptions extra SDK request options (tests pass a transporter) */
    public function __construct(
        string $apiKey,
        private readonly string $model,
        private readonly string $gateModel,
        private readonly int $maxTokens,
        float $timeoutSeconds,
        array $requestOptions = [],
    ) {
        $this->client = new Client(
            apiKey: $apiKey,
            requestOptions: ['timeout' => $timeoutSeconds, 'maxRetries' => 1] + $requestOptions,
            openTelemetry: false,
        );
    }

    public static function available(): bool
    {
        return class_exists(Client::class) && class_exists(\GuzzleHttp\Client::class);
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
