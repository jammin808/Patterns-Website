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
 */
final class AnthropicGateway implements ModelGateway
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private Client $client;

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
        $cacheRead = 0;
        $served = $this->model;
        foreach ($stream as $event) {
            if ($event instanceof BetaRawMessageStartEvent) {
                $in = (int) $event->message->usage->inputTokens;
                $cacheRead = (int) ($event->message->usage->cacheReadInputTokens ?? 0);
                $served = (string) $event->message->model;
            } elseif ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                $text .= $event->delta->text;
                $onText($event->delta->text);
            } elseif ($event instanceof BetaRawMessageDeltaEvent) {
                $stop = (string) ($event->delta->stopReason ?? $stop);
                $out = (int) $event->usage->outputTokens;
                if ($event->usage->inputTokens !== null) {
                    $in = (int) $event->usage->inputTokens;
                }
            }
        }
        return ['text' => $text, 'stop' => $stop, 'tokens_in' => $in, 'tokens_out' => $out, 'cache_read' => $cacheRead, 'model' => $served];
    }
}
