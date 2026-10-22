<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use PHPUnit\Framework\TestCase;
use Psc\News\Pricing;
use Psc\News\Settings;

/** The desk's cost estimates: each model's published rates, Haiku's long prompts, fallbacks and the session range. */
final class PricingTest extends TestCase
{
    private const MILLION = ['usage_in' => 1000000, 'usage_out' => 1000000, 'cache_write' => 1000000, 'cache_read' => 1000000];

    public function testAMillionOfEachAtEachModelsRates(): void
    {
        self::assertEqualsWithDelta(4 + 20 + 5 + 0.20, Pricing::cost(self::MILLION, 'claude-opus-5-5'), 1e-9);
        self::assertEqualsWithDelta(2 + 10 + 2.50 + 0.10, Pricing::cost(self::MILLION, 'claude-sonnet-5-5'), 1e-9);
        // A million-token prompt is over 100,000 tokens, so Haiku's long-prompt rates apply.
        self::assertEqualsWithDelta(0.50 + 2.50 + 0.625 + 0.05, Pricing::cost(self::MILLION, 'claude-haiku-5-5'), 1e-9);
    }

    public function testHaikuIsPricedByTheLengthOfEachPrompt(): void
    {
        $short = ['usage_in' => 60000, 'cache_write' => 20000, 'cache_read' => 20000, 'usage_out' => 1000];
        self::assertEqualsWithDelta((60000 * 0.10 + 20000 * 0.125 + 20000 * 0.01 + 1000 * 0.50) / 1e6, Pricing::cost($short, 'claude-haiku-5-5'), 1e-12);
        $long = ['usage_in' => 60001] + $short;
        self::assertEqualsWithDelta((60001 * 0.50 + 20000 * 0.625 + 20000 * 0.05 + 1000 * 2.50) / 1e6, Pricing::cost($long, 'claude-haiku-5-5'), 1e-12);
    }

    public function testSearchesCostACentAndFetchesNothing(): void
    {
        self::assertEqualsWithDelta(0.12, Pricing::cost(['searches' => 12, 'fetches' => 6], 'claude-sonnet-5-5'), 1e-12);
        self::assertSame(120000, Pricing::micros(['searches' => 12], 'claude-sonnet-5-5'));
        self::assertSame(0.0, Pricing::cost([], 'claude-opus-5-5'));
    }

    public function testAFallbackModelIsPricedAtItsOwnRatesAndAnUnknownOneAtTheDearest(): void
    {
        self::assertSame(Pricing::RATES['claude-opus-4-8'], Pricing::rates('claude-opus-4-8'));
        self::assertSame(Pricing::RATES['claude-sonnet-5-5'], Pricing::rates('claude-sonnet-5-5-20261001'), 'a dated name');
        $unknown = Pricing::rates('claude-something-new');
        foreach (Pricing::RATES as $rates) {
            self::assertGreaterThanOrEqual($rates['output'], $unknown['output']);
        }
    }

    public function testMicrosRoundUpAndNeverCountNoise(): void
    {
        self::assertSame(1, Pricing::micros(['usage_out' => 1], 'claude-haiku-5-5'), 'half a millionth rounds up');
        self::assertSame(20, Pricing::micros(['usage_out' => 1], 'claude-opus-5-5'));
        self::assertSame(4000000, Pricing::micros(['usage_in' => 1000000], 'claude-opus-5-5'));
    }

    public function testTheDefaultSessionCostsWhatTheDesignSays(): void
    {
        $d = Settings::DEFAULTS;
        $e = Pricing::session($d['research_model'], $d['compose_model'], $d['max_searches']);
        // docs/NEWS.md §2.9: about 1 to 2.50 USD a session.
        self::assertGreaterThanOrEqual(0.9, $e['low']);
        self::assertLessThanOrEqual(2.5, $e['high']);
        self::assertLessThan($e['high'], $e['low']);
        self::assertEqualsWithDelta($e['research']['low'] + $e['writing']['low'], $e['low'], 1e-9);
        $opus = Pricing::session('claude-opus-5-5', 'claude-opus-5-5', 12);
        self::assertGreaterThan($e['research']['high'], $opus['research']['high'], 'research on Opus costs more');
        self::assertGreaterThan(Pricing::session('claude-sonnet-5-5', 'claude-opus-5-5', 1)['low'], $e['low'], 'more searches cost more');
        self::assertSame('1.25', Pricing::usd(1.249));
        self::assertSame('0.00', Pricing::usd(-1));
    }
}
