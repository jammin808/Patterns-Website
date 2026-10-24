<?php

declare(strict_types=1);

namespace Psc\Tests\Fakes;

use Psc\News\NewsModel;
use Psc\News\Prompts;

/**
 * A scripted desk model. Each kind of call (research, select, compose, repair, check) answers from its own queue,
 * in order, with the defaults below once the queue is empty; an answer that is a Throwable is thrown. Like the real
 * model it runs the call's before hook before answering and reports usage through the after hook, so the pipeline's
 * deadline, ceiling, lease and spending records are exercised. Every call is recorded with its kind.
 */
final class FakeNewsModel implements NewsModel
{
    /** @var list<array{kind: string, call: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array<string, mixed>|\Throwable>> answers by kind */
    public array $answers = ['research' => [], 'select' => [], 'compose' => [], 'repair' => [], 'check' => []];

    /** @var array<string, array<string, int>> the usage each kind reports per call */
    public array $usage = [
        'research' => ['usage_in' => 20000, 'usage_out' => 4000, 'cache_write' => 30000, 'cache_read' => 200000, 'searches' => 5, 'fetches' => 2],
        'select' => ['usage_in' => 8000, 'usage_out' => 1500, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0],
        'compose' => ['usage_in' => 9000, 'usage_out' => 6000, 'cache_write' => 3000, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0],
        'repair' => ['usage_in' => 7000, 'usage_out' => 5000, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0],
        'check' => ['usage_in' => 3000, 'usage_out' => 200, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0],
    ];

    /** @var (\Closure(string $kind): void)|null runs after each answer, before its usage is reported (a test breaks the database here) */
    public ?\Closure $beforeReport = null;

    /** @var (\Closure(string $kind): void)|null runs after each call has reported its usage */
    public ?\Closure $afterCall = null;

    /** @param array<string, mixed>|\Throwable $answer */
    public function queue(string $kind, array|\Throwable $answer): self
    {
        $this->answers[$kind][] = $answer;
        return $this;
    }

    /** @return list<string> the kinds of the calls made, in order */
    public function kinds(): array
    {
        return array_column($this->calls, 'kind');
    }

    /** @return list<array<string, mixed>> the calls of one kind */
    public function callsOf(string $kind): array
    {
        return array_values(array_column(array_filter($this->calls, static fn (array $c): bool => $c['kind'] === $kind), 'call'));
    }

    public function research(array $call): array
    {
        $answer = $this->answer('research', $call);
        return $answer + ['text' => self::BRIEF, 'refs' => self::refs(), 'stop' => 'end_turn', 'model' => (string) $call['model'], 'container' => null, 'usage' => $this->usage['research']];
    }

    public function structured(array $call): array
    {
        $kind = match (true) {
            ($call['schema'] ?? null) === Prompts::selectSchema() => 'select',
            ($call['schema'] ?? null) === Prompts::checkSchema() => 'check',
            $call['system'] === Prompts::repairRules() => 'repair',
            default => 'compose',
        };
        $answer = $this->answer($kind, $call);
        $default = match ($kind) {
            'select' => ['considered' => [self::candidate(self::briefDay($call))]],
            'check' => self::clean(),
            default => self::article(),
        };
        return $answer + ['data' => $default, 'stop' => 'end_turn', 'model' => (string) $call['model'], 'usage' => $this->usage[$kind]];
    }

    /**
     * The default candidate's date, read as a model reads it: from the day the brief says it is ("Today is Thursday
     * 22 October 2026 in London."), so a session asked about a fixed time finds its story dated that day, whatever
     * the wall clock says. Empty, and the candidate keeps its own date, when the brief names no day.
     *
     * @param array<string, mixed> $call
     * @return array<string, string>
     */
    private static function briefDay(array $call): array
    {
        $text = $call['messages'][0]['content'] ?? null;
        if (!is_string($text) || preg_match('/Today is [A-Za-z]+ (\d{1,2} [A-Za-z]+ \d{4})/', $text, $m) !== 1) {
            return [];
        }
        $day = \DateTimeImmutable::createFromFormat('!j F Y', $m[1], new \DateTimeZone('Europe/London'));
        return $day === false ? [] : ['newest_source_date' => $day->format('Y-m-d')];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    private function answer(string $kind, array $call): array
    {
        $this->calls[] = ['kind' => $kind, 'call' => $call];
        if (is_callable($call['before'] ?? null)) {
            ($call['before'])();
        }
        $answer = $this->answers[$kind] !== [] ? array_shift($this->answers[$kind]) : [];
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        if ($this->beforeReport !== null) {
            ($this->beforeReport)($kind);
        }
        if (is_callable($call['after'] ?? null)) {
            ($call['after'])($answer['usage'] ?? $this->usage[$kind], (string) ($answer['model'] ?? $call['model']));
        }
        if ($this->afterCall !== null) {
            ($this->afterCall)($kind);
        }
        return $answer;
    }

    // ------------------------------------------------------------------------------------------- the scripts

    public const BRIEF = "1. Vendor ships LED processor firmware with frame-accurate genlock\nStory key: vendor-led-processor-genlock-firmware\nImportance: 4, it changes how walls are locked to cameras.\nNewest source: today.\nVerified: the firmware is out (https://vendor.example/news/genlock-firmware).\nSpeculation: a rival is said to follow (https://trade.example/rumour).";

    /** @return array<string, array{url: string, title: string, via: string, page_age: ?string, cited: bool}> S1 fetched, S2 cited, S3 seen in search only */
    public static function refs(): array
    {
        return [
            'S1' => ['url' => 'https://vendor.example/news/genlock-firmware', 'title' => 'Firmware 4.2 adds frame-accurate genlock', 'via' => 'fetch', 'page_age' => '1 day ago', 'cited' => false],
            'S2' => ['url' => 'https://trade.example/2026/10/led-genlock-review', 'title' => 'Hands on with the new genlock firmware', 'via' => 'search', 'page_age' => '2 days ago', 'cited' => true],
            'S3' => ['url' => 'https://trade.example/rumour', 'title' => 'A rival is said to be close behind', 'via' => 'search', 'page_age' => '3 days ago', 'cited' => false],
        ];
    }

    /**
     * A candidate the select step chose.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function candidate(array $over = []): array
    {
        return $over + [
            'headline' => 'Vendor ships LED processor firmware with frame-accurate genlock',
            'story_key' => 'vendor-led-processor-genlock-firmware',
            'importance' => 4,
            'newest_source_date' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d'),
            'decision' => 'write',
            'category' => 'video',
            'standards' => false,
            'duplicate_of' => [],
            'follow_up_of' => [],
            'refs' => ['S1', 'S2'],
            'reason' => 'A shipped release that changes camera work on LED walls.',
        ];
    }

    /**
     * A valid AI article on S1 and S2.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function article(array $over = []): array
    {
        return $over + [
            'title' => 'LED processor firmware 4.2 brings frame-accurate genlock to touring walls',
            'seo_title' => 'LED processor firmware adds frame-accurate genlock',
            'dek' => 'The 4.2 release locks the wall to house sync on every frame, which should end the rolling bars on camera that have haunted hybrid shows.',
            'summary' => 'Firmware 4.2 locks LED processors to house sync on every frame, ending rolling bars on camera for hybrid and broadcast shows.',
            'body' => "The release notes are short and the change is not. [Firmware 4.2](S1) moves the processor to frame-accurate genlock, so the wall's refresh follows house sync rather than drifting against it.\n\n## What changed\n\nUntil now the processor locked at power-up and then trusted its own clock. Over a long show that let the wall slide against the cameras, and the shutter caught it as a slow rolling bar. The new build re-checks the reference every frame.\n\n## On a real job\n\nThe [first hands-on test](S2) found the bars gone at every shutter speed tried, which is the part that matters when the client's camera op is standing beside you looking worried.",
            'confirmed' => [['text' => 'Firmware 4.2 adds frame-accurate genlock to the processor.', 'ref' => 'S1']],
            'speculation' => [['text' => 'Testers expect the change to cut rolling bars at most shutter speeds.', 'ref' => 'S2']],
            'impact' => 'If you put LED walls in front of cameras, update the processors before your next hybrid job and check the reference input in rehearsal.',
            'verdict' => 'test',
            'verdict_note' => 'Load it on a spare processor and run it against your camera package before it goes on a show.',
            'categories' => ['video', 'technology'],
            'tags' => ['led', 'genlock'],
            'sources' => [['ref' => 'S1', 'publisher' => 'Vendor', 'kind' => 'primary'], ['ref' => 'S2', 'publisher' => 'Trade Weekly', 'kind' => 'press']],
            'follow_up_of' => [],
            'story_key' => 'vendor-led-processor-genlock-firmware',
        ];
    }

    /** @return array<string, mixed> a check that found nothing */
    public static function clean(): array
    {
        return ['banned_topics' => [], 'names_private_individual' => false, 'alleges_wrongdoing' => false, 'reason' => 'Nothing found.'];
    }
}
