<?php

declare(strict_types=1);

namespace Psc\Tests\Fakes;

use Psc\Chat\ModelGateway;

/** A scripted model: a fixed verdict for the scope check and fixed fragments for the answer, with call records. */
final class FakeGateway implements ModelGateway
{
    /** @var list<array{system: string, messages: list<array<string, string>>}> */
    public array $classified = [];

    /** @var list<array{system: list<array<string, mixed>>, messages: list<array<string, string>>}> */
    public array $streamed = [];

    /** @param list<string> $fragments */
    public function __construct(
        public string $verdict = 'in_scope',
        public array $fragments = ['Stage', 'Watch is a timer.'],
        public string $stop = 'end_turn',
        public ?\Throwable $gateError = null,
        public ?\Throwable $streamError = null,
        public bool $gateRefused = false,
    ) {
    }

    public function classify(string $system, array $messages, array $schema): array
    {
        $this->classified[] = ['system' => $system, 'messages' => $messages];
        if ($this->gateError !== null) {
            throw $this->gateError;
        }
        return ['data' => ['verdict' => $this->verdict, 'product' => 'stagewatch', 'reason' => 'test'], 'refused' => $this->gateRefused, 'tokens_in' => 50, 'tokens_out' => 10];
    }

    public function stream(array $system, array $messages, callable $onText): array
    {
        $this->streamed[] = ['system' => $system, 'messages' => $messages];
        $text = '';
        foreach ($this->fragments as $f) {
            $onText($f);
            $text .= $f;
        }
        if ($this->streamError !== null) {
            throw $this->streamError;
        }
        return ['text' => $text, 'stop' => $this->stop, 'tokens_in' => 900, 'tokens_out' => 40, 'cache_read' => 800, 'model' => 'fake'];
    }
}
