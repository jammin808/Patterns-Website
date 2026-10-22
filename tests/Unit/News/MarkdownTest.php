<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use Psc\News\Markdown;
use Psc\News\Urls;
use Psc\Tests\TestCase;

final class MarkdownTest extends TestCase
{
    private const BASE = 'https://patternsshowcontrol.test';

    public function testHeadingsAndListsAreGivenBlocksOfTheirOwn(): void
    {
        $html = Markdown::render("Intro line\n## What changed\n- one\n- two\nAfter the list\n1. first\n2. second");
        self::assertStringContainsString('<p>Intro line</p>', $html);
        self::assertStringContainsString('<h2>What changed</h2>', $html);
        self::assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
        self::assertStringContainsString('<p>After the list</p>', $html);
        self::assertStringContainsString('<ol><li>first</li><li>second</li></ol>', $html);
    }

    public function testABodyStartsAtH2AndNeverSkipsALevel(): void
    {
        $html = Markdown::render("# The top\n\nText.\n\n#### Deep\n\nMore.\n\n## Next");
        self::assertStringContainsString('<h2>The top</h2>', $html);
        self::assertStringContainsString('<h3>Deep</h3>', $html, 'h1 then h4 becomes h2 then h3');
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringNotContainsString('<h4', $html);
        $shallow = Markdown::render("### Only small headings\n\n### Another");
        self::assertStringContainsString('<h2>Only small headings</h2>', $shallow);
        self::assertStringContainsString('<h2>Another</h2>', $shallow);
    }

    public function testFencedCodeIsShownAsPlainParagraphs(): void
    {
        $html = Markdown::render("Before\n\n```php\n<?php echo 'x';\n```\n\nAfter");
        self::assertStringNotContainsString('<pre', $html);
        self::assertStringNotContainsString('```', $html);
        self::assertStringContainsString('&lt;?php echo &#039;x&#039;;', $html, 'escaped, as text');
        self::assertStringContainsString('<p>After</p>', $html);
    }

    public function testRegisteredExternalLinksCarryTheirLinkId(): void
    {
        $ids = [(string) Urls::key('https://example.com/release') => 7];
        $html = Markdown::render('Read [the notes](https://www.example.com/release/?utm_source=x), [our guide](/workflows/) and [elsewhere](https://other.example/a).', $ids, self::BASE);
        self::assertStringContainsString('<a href="https://www.example.com/release/?utm_source=x" rel="nofollow noopener" target="_blank" data-news-link="7">the notes</a>', $html, 'the real address, matched by its canonical key');
        self::assertStringContainsString('<a href="/workflows/">our guide</a>', $html);
        self::assertStringContainsString('<a href="https://other.example/a" rel="nofollow noopener" target="_blank">elsewhere</a>', $html, 'an unregistered link is left as it is');
        $inline = Markdown::inline("**New** firmware, [notes](https://example.com/release)\nand more", $ids, self::BASE);
        self::assertSame('<strong>New</strong> firmware, <a href="https://example.com/release" rel="nofollow noopener" target="_blank" data-news-link="7">notes</a> and more', $inline);
    }

    public function testRawHtmlIsEscaped(): void
    {
        $html = Markdown::render("<script>alert(1)</script>\n\n[x](javascript:alert(1))");
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('href="javascript', $html);
    }
}
