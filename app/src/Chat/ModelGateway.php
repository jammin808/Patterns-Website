<?php

declare(strict_types=1);

namespace Psc\Chat;

/**
 * The two model calls the assistant makes. The production implementation is AnthropicGateway; tests use a fake.
 */
interface ModelGateway
{
    /**
     * The scope check: one short structured verdict about the visitor's latest message.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema JSON Schema of the verdict
     * @return array{data: array<string, mixed>|null, refused: bool, tokens_in: int, tokens_out: int}
     */
    public function classify(string $system, array $messages, array $schema): array;

    /**
     * The answer, streamed: $onText receives each text fragment as it arrives and may throw StreamAborted to stop.
     *
     * @param list<array<string, mixed>> $system system blocks (the last one carries the cache breakpoint)
     * @param list<array{role: string, content: string}> $messages
     * @param callable(string): void $onText
     * @return array{text: string, stop: string, tokens_in: int, tokens_out: int, cache_read: int, model: string}
     */
    public function stream(array $system, array $messages, callable $onText): array;
}
