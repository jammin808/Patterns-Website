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

    /** @var list<string> verdicts for the next scope checks, in order; once empty, $verdict answers */
    public array $verdicts = [];

    public function classify(string $system, array $messages, array $schema): array
    {
        $this->classified[] = ['system' => $system, 'messages' => $messages];
        if ($this->gateError !== null) {
            throw $this->gateError;
        }
        $verdict = $this->verdicts !== [] ? array_shift($this->verdicts) : $this->verdict;
        return ['data' => ['verdict' => $verdict, 'product' => 'stagewatch', 'reason' => 'test'], 'refused' => $this->gateRefused, 'tokens_in' => 50, 'tokens_out' => 10];
    }

    /** @var array{tokens_in: int, tokens_out: int, cache_write: int, cache_read: int} */
    private array $usage = ['tokens_in' => 0, 'tokens_out' => 0, 'cache_write' => 0, 'cache_read' => 0];

    public function stream(array $system, array $messages, callable $onText): array
    {
        $this->streamed[] = ['system' => $system, 'messages' => $messages];
        // As the API reports it: the prompt and cache counts at the start, output at the end.
        $this->usage = ['tokens_in' => 900, 'tokens_out' => 1, 'cache_write' => 3000, 'cache_read' => 800];
        $text = '';
        foreach ($this->fragments as $f) {
            $onText($f);
            $text .= $f;
        }
        if ($this->streamError !== null) {
            throw $this->streamError;
        }
        $this->usage['tokens_out'] = 40;
        return ['text' => $text, 'stop' => $this->stop, 'tokens_in' => 900, 'tokens_out' => 40, 'cache_write' => 3000, 'cache_read' => 800, 'model' => 'fake'];
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }
}
