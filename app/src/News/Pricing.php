<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * What the desk's model calls cost, estimated from the usage the API reports at the published rates (USD per
 * million tokens, and per web search). Every figure here is an estimate and the admin pages say so: the invoice is
 * Anthropic's. A call is priced at the rates of the model that served it, which after a refusal fallback can be a
 * model the desk never asked for; a model this table does not know is priced at its dearest rates, so a fallback is
 * never counted as cheaper than it was.
 */
final class Pricing
{
    /** Per million tokens: input, a five-minute cache write, a cache read, output. */
    public const RATES = [
        'claude-opus-5-5' => ['input' => 4.0, 'cache_write' => 5.0, 'cache_read' => 0.20, 'output' => 20.0],
        'claude-sonnet-5-5' => ['input' => 2.0, 'cache_write' => 2.50, 'cache_read' => 0.10, 'output' => 10.0],
        'claude-haiku-5-5' => ['input' => 0.10, 'cache_write' => 0.125, 'cache_read' => 0.01, 'output' => 0.50],
        // The models a refusal fallback can hand a call to.
        'claude-opus-5' => ['input' => 5.0, 'cache_write' => 6.25, 'cache_read' => 0.50, 'output' => 25.0],
        'claude-opus-4-8' => ['input' => 5.0, 'cache_write' => 6.25, 'cache_read' => 0.50, 'output' => 25.0],
        'claude-sonnet-5' => ['input' => 2.0, 'cache_write' => 2.50, 'cache_read' => 0.20, 'output' => 10.0],
    ];

    /** Claude Haiku 5.5's rates for a call whose prompt (input, cache writes and reads together) is over 100,000 tokens. */
    public const HAIKU_LONG = ['input' => 0.50, 'cache_write' => 0.625, 'cache_read' => 0.05, 'output' => 2.50];
    public const HAIKU_LONG_FROM = 100000;

    /** One web search; fetching a page costs only the tokens it adds. */
    public const SEARCH = 0.01;

    /**
     * The usage of a session the estimates assume: research reads 0.4 to 1M tokens, mostly from the cache; selecting
     * and writing read 30 to 60k and write 20 to 40k (docs/NEWS.md §2.9). The first compose call writes the part the
     * session's compose calls share (the brief, the references, the context) to the cache and the others read it
     * back, 10 to 20k more read from the cache.
     */
    private const PROFILE = [
        'research' => [
            'low' => ['usage_in' => 50000, 'cache_write' => 50000, 'cache_read' => 300000, 'usage_out' => 10000],
            'high' => ['usage_in' => 100000, 'cache_write' => 100000, 'cache_read' => 800000, 'usage_out' => 25000],
        ],
        'writing' => [
            'low' => ['usage_in' => 20000, 'cache_write' => 10000, 'cache_read' => 10000, 'usage_out' => 20000],
            'high' => ['usage_in' => 40000, 'cache_write' => 20000, 'cache_read' => 20000, 'usage_out' => 40000],
        ],
    ];

    /**
     * The estimated cost of one call (or several on one model) in USD.
     *
     * @param array<string, int> $usage usage_in, usage_out, cache_write, cache_read, searches (missing keys are 0)
     */
    public static function cost(array $usage, string $model): float
    {
        $n = static fn (string $k): int => max(0, (int) ($usage[$k] ?? 0));
        $rates = self::rates($model);
        if (str_starts_with($model, 'claude-haiku-5-5') && $n('usage_in') + $n('cache_write') + $n('cache_read') > self::HAIKU_LONG_FROM) {
            $rates = self::HAIKU_LONG;
        }
        return ($n('usage_in') * $rates['input'] + $n('cache_write') * $rates['cache_write'] + $n('cache_read') * $rates['cache_read'] + $n('usage_out') * $rates['output']) / 1000000
            + $n('searches') * self::SEARCH;
    }

    /**
     * The same cost in millionths of a dollar, rounded up, as news_runs.cost_micros holds it.
     *
     * @param array<string, int> $usage
     */
    public static function micros(array $usage, string $model): int
    {
        return (int) ceil(round(self::cost($usage, $model) * 1000000, 3));
    }

    /**
     * A model's rates: its own, or for a dated or unknown name the closest entry, else the dearest in the table.
     *
     * @return array{input: float, cache_write: float, cache_read: float, output: float}
     */
    public static function rates(string $model): array
    {
        if (isset(self::RATES[$model])) {
            return self::RATES[$model];
        }
        foreach (self::RATES as $name => $rates) {
            if (str_starts_with($model, $name . '-')) {
                return $rates;
            }
        }
        $dearest = self::RATES['claude-opus-5-5'];
        foreach (self::RATES as $rates) {
            if ($rates['output'] > $dearest['output']) {
                $dearest = $rates;
            }
        }
        return $dearest;
    }

    /**
     * The estimated cost of one session, as a range: research on $researchModel with up to $searches web searches,
     * then selecting and writing on $composeModel. The writing part is for a full session of three articles.
     *
     * @return array{research: array{low: float, high: float}, writing: array{low: float, high: float}, low: float, high: float}
     */
    public static function session(string $researchModel, string $composeModel, int $searches): array
    {
        $part = static function (string $step, string $model, int $searches): array {
            $range = [];
            foreach (['low', 'high'] as $end) {
                $range[$end] = self::cost(self::PROFILE[$step][$end] + ['searches' => $searches], $model);
            }
            return $range;
        };
        $research = $part('research', $researchModel, max(0, $searches));
        $writing = $part('writing', $composeModel, 0);
        return [
            'research' => $research,
            'writing' => $writing,
            'low' => $research['low'] + $writing['low'],
            'high' => $research['high'] + $writing['high'],
        ];
    }

    /** "1.25" for display: dollars to the cent, never a minus sign. */
    public static function usd(float $amount): string
    {
        return number_format(max(0.0, $amount), 2, '.', ',');
    }
}
