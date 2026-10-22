<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use Psc\Tests\TestCase;

final class HelpersTest extends TestCase
{
    public function testMarkdownEscapesEverythingAndKeepsOnlySafeLinks(): void
    {
        $html = md("**Bold** <script>alert(1)</script>\n\n- [ok](/stagewatch/)\n- [bad](javascript:alert(1))");
        self::assertStringContainsString('<strong>Bold</strong>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<a href="/stagewatch/">ok</a>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('<li>bad', $html);
    }

    /**
     * A link counted as the site's own when its address merely began with the site's address, so a look-alike host
     * such as patternsshowcontrol.co.uk.evil.example opened in the same tab without nofollow. The host decides now.
     */
    public function testMarkdownLinksAreExternalByTheirHost(): void
    {
        self::rmrf($this->storage);
        $this->bootApp(['app' => ['url' => 'https://patternsshowcontrol.co.uk']]);
        $external = ' rel="nofollow noopener" target="_blank"';
        self::assertSame('<a href="https://patternsshowcontrol.co.uk.evil.example/"' . $external . '>x</a>', md_inline('[x](https://patternsshowcontrol.co.uk.evil.example/)'));
        self::assertSame('<p><a href="https://patternsshowcontrol.co.uk.evil.example/support/"' . $external . '>x</a></p>', md('[x](https://patternsshowcontrol.co.uk.evil.example/support/)'));
        self::assertSame('<a href="https://patternsshowcontrol.co.uk/support/">x</a>', md_inline('[x](https://patternsshowcontrol.co.uk/support/)'));
        self::assertSame('<a href="https://www.patternsshowcontrol.co.uk/support/">x</a>', md_inline('[x](https://www.patternsshowcontrol.co.uk/support/)'));
        self::assertSame('<a href="/support/">x</a>', md_inline('[x](/support/)'));
        self::assertSame('<a href="https://example.com/"' . $external . '>x</a>', md_inline('[x](https://example.com/)'));
    }

    public function testPriceFormatting(): void
    {
        self::assertSame('£29', price('29'));
        self::assertSame('£2.50', price('2.5'));
        self::assertSame('Coming soon', price(null));
        self::assertSame('Free', price('Free'));
    }

    public function testEscape(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;', e('<a href="x">'));
        self::assertSame('', e(null));
    }

    /** A phone at three times density took the 1600-pixel file; every screenshot now offers a 1200-pixel copy. */
    public function testScreenshotsOfferA1200PixelCopy(): void
    {
        $shot = ['src' => '/assets/img/products/patterns/show-panel.webp', 'src_small' => '/assets/img/products/patterns/show-panel-800.webp'];
        self::assertSame('/assets/img/products/patterns/show-panel-800.webp 800w, /assets/img/products/patterns/show-panel-1200.webp 1200w, /assets/img/products/patterns/show-panel.webp 1600w', shot_srcset($shot));
        self::assertSame('/x/none-800.webp 800w, /x/none.webp 1600w', shot_srcset(['src' => '/x/none.webp', 'src_small' => '/x/none-800.webp']), 'no copy, no candidate');
        self::assertSame('', shot_srcset(['src' => '/x/none.webp']));
        foreach (\Psc\Content\Content::products() as $key => $p) {
            foreach ($p['screenshots'] ?? [] as $s) {
                if (!empty($s['src_small'])) {
                    self::assertStringContainsString(' 1200w', shot_srcset($s), $key . ': ' . $s['src']);
                }
            }
        }
    }
}
