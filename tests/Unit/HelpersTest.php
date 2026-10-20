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
}
