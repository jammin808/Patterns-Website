<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use PHPUnit\Framework\TestCase;
use Psc\News\Prose;

final class ProseTest extends TestCase
{
    private const EM = "\u{2014}";
    private const EN = "\u{2013}";

    public function testUndashTurnsDashesIntoCommasAndRangesIntoHyphens(): void
    {
        self::assertSame('The switcher, a big one, rebooted.', Prose::undash('The switcher ' . self::EM . ' a big one ' . self::EM . ' rebooted.'));
        self::assertSame('The switcher, a big one, rebooted.', Prose::undash('The switcher' . self::EM . 'a big one' . self::EM . 'rebooted.'));
        self::assertSame('London, Paris', Prose::undash('London ' . self::EN . ' Paris'));
        self::assertSame('Prices rose 10-30 per cent in 2025-2026.', Prose::undash('Prices rose 10' . self::EN . '30 per cent in 2025 ' . self::EN . ' 2026.'));
        self::assertSame(0, Prose::countDashes(Prose::undash('a ' . self::EM . ' b ' . self::EN . ' c 1' . self::EN . '2')));
    }

    public function testUndashTidiesTheCommasItLeavesAndKeepsLineBreaks(): void
    {
        self::assertSame('It worked.', Prose::undash('It worked ' . self::EM . '.'));
        self::assertSame('Yes, really', Prose::undash('Yes, ' . self::EM . ' really'));
        self::assertSame("A line\nNext line", Prose::undash('A line ' . self::EM . "\nNext line"));
        self::assertSame('Opening words', Prose::undash(self::EM . ' Opening words'));
        self::assertSame("## Heading, part two\n\n- one, two\n- three", Prose::undash('## Heading ' . self::EM . " part two\n\n- one " . self::EM . " two\n- three"));
        self::assertSame("Text  with  doubled spaces\nstays as it was", Prose::undash("Text  with  doubled spaces\nstays as it was"), 'no dash, no change');
        self::assertSame(3, Prose::countDashes('a' . self::EM . 'b' . self::EN . 'c' . self::EM));
    }

    public function testTellsFindsWholePhrasesOutsideQuotesAndLinkText(): void
    {
        $phrases = ['delve', 'game-changer', 'game changer', "it's worth noting", 'seamlessly'];
        self::assertSame(['delve'], Prose::tells('Today we Delve into the release.', $phrases));
        self::assertSame([], Prose::tells('Delving deeper, the delves are fine.', $phrases), 'whole words only');
        self::assertSame([], Prose::tells('The vendor called it "a game changer" in its release.', $phrases), 'a quote is the speaker\'s');
        self::assertSame([], Prose::tells("The vendor called it \u{201C}a game-changer\u{201D}.", $phrases));
        self::assertSame([], Prose::tells('See [seamlessly switching](https://example.com/a) for details.', $phrases), 'link text is skipped');
        self::assertSame(["it's worth noting"], Prose::tells("It\u{2019}s worth noting that the firmware is out.", $phrases), 'curly apostrophes match straight ones');
        self::assertSame(['game-changer', 'game changer'], Prose::tells('A game changer, a real game-changer.', $phrases), 'in the order of the list');
        self::assertSame(['game changer'], Prose::mentions('He said "a game changer".', ['game changer']), 'mentions() reads quotes too');
    }

    public function testWordsAndReadTime(): void
    {
        self::assertSame(6, Prose::words("## It's a test\n\n- one [two words](https://example.com/x)"));
        self::assertSame(1, Prose::readMinutes(10));
        self::assertSame(2, Prose::readMinutes(231));
        self::assertSame(1, Prose::readMinutes('A short text.'));
    }

    public function testSlugsArePlainAsciiCutAtAHyphen(): void
    {
        self::assertSame('cafe-deja-vu-4k-and-hdr', Prose::slug("Caf\u{00E9} D\u{00E9}j\u{00E0} Vu: 4K & HDR!"));
        self::assertSame('its-here-at-last', Prose::slug("It\u{2019}s here, at last"));
        self::assertSame('', Prose::slug('!!!'));
        $long = Prose::slug(str_repeat('switcher ', 20));
        self::assertLessThanOrEqual(80, strlen($long));
        self::assertMatchesRegularExpression('/^(switcher-)*switcher$/', $long, 'cut at a hyphen, no word broken');
        self::assertSame('uk-right-to-work', Prose::slug('UK right to work', 190));
    }

    public function testPlainCutAndEmails(): void
    {
        self::assertSame('Head Bold link and code, emphasis', Prose::plain("## Head\n\n- **Bold** [link](https://example.com/x) and `code`, *emphasis*"));
        self::assertSame('The quick brown', Prose::cut('The quick brown fox jumps', 18));
        self::assertSame('The quick brown', Prose::cut('The quick brown, fox jumps', 16), 'no trailing punctuation');
        self::assertSame('It fits.', Prose::cut('It fits.', 60), 'a text that fits keeps its full stop');
        self::assertTrue(Prose::emails('Write to ben@example.co.uk for details.'));
        self::assertTrue(Prose::emails('[Email us](MAILTO:someone)'));
        self::assertFalse(Prose::emails('No address here, just @handles and example.com.'));
    }
}
