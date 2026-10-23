<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use PHPUnit\Framework\TestCase;
use Psc\News\Categories;

/**
 * The files Industry Updates' public pages are drawn with (docs/DESIGN.md 8.36): the category artwork is one
 * monochrome symbol per category in currentColor, the stylesheet uses tokens only under its own prefix, the
 * script counts clicks the way the endpoint accepts them, the share card exists at its size, and none of the files
 * carries an inline style, an inline script or a dash used as punctuation.
 */
final class NewsAssetsTest extends TestCase
{
    private const FILES = [
        'app/src/Controllers/NewsController.php',
        'templates/news/index.php',
        'templates/news/show.php',
        'templates/partials/news/art.php',
        'templates/partials/news/card.php',
        'templates/partials/news/figure.php',
        'templates/partials/news/filters.php',
        'templates/partials/news/pager.php',
        'templates/partials/news/silo.php',
        'templates/news/comment.php',
        'app/src/News/Paths.php',
        'app/src/Controllers/SeoController.php',
        'public/assets/css/pages/news-comments.css',
        'tools/og/build.mjs',
        'tools/og/template.html',
        'public/assets/css/pages/news.css',
        'public/assets/js/news.js',
        'public/assets/img/news-art.svg',
        'content/news.php',
        'templates/partials/news/follow.php',
        'templates/partials/news/share.php',
        'templates/partials/news/latest.php',
        'templates/news/standards.php',
        'public/assets/css/pages/news-latest.css',
        'app/src/News/Announcer.php',
        'app/src/News/Latest.php',
        'app/src/News/HttpPingTransport.php',
        'database/migrations/mysql/0006_news_exposure.sql',
        'database/migrations/sqlite/0006_news_exposure.sql',
    ];

    public function testTheArtworkIsOneMonochromeSymbolPerCategory(): void
    {
        $svg = (string) file_get_contents(PSC_ROOT . '/public/assets/img/news-art.svg');
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($svg), 'well-formed');
        $symbols = $doc->getElementsByTagName('symbol');
        $ids = [];
        foreach ($symbols as $symbol) {
            $ids[] = $symbol->getAttribute('id');
            self::assertSame('0 0 160 90', $symbol->getAttribute('viewBox'), $symbol->getAttribute('id'));
        }
        self::assertSame(array_map(static fn (string $k): string => 'art-' . $k, Categories::KEYS), $ids, 'every category, in order');

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@fill or @stroke]') ?: [] as $el) {
            foreach (['fill', 'stroke'] as $paint) {
                if ($el instanceof \DOMElement && $el->hasAttribute($paint)) {
                    self::assertContains($el->getAttribute($paint), ['none', 'currentColor'], 'no colour of its own');
                }
            }
        }
        foreach ($xpath->query('//*[local-name()="path" or local-name()="rect" or local-name()="circle"]') ?: [] as $shape) {
            self::assertInstanceOf(\DOMElement::class, $shape);
            self::assertSame('non-scaling-stroke', $shape->getAttribute('vector-effect'), 'hairlines keep their weight at any size');
        }
        self::assertSame(0, preg_match('/\bstyle\b|<script|<image|<title|href=|#[0-9a-f]{3,6}\b/i', $svg), 'no style, script, picture, tooltip, link or colour');
    }

    public function testTheStylesheetUsesTokensUnderItsOwnPrefix(): void
    {
        self::assertGreaterThan(20, count(self::assertTokensUnderPrefix('public/assets/css/pages/news.css', '.news-')));
    }

    /** The strips of the newest articles on the home and product pages: a stylesheet of their own, by the same rules. */
    public function testTheLatestStripsStylesheetUsesTokensUnderItsOwnPrefix(): void
    {
        self::assertGreaterThan(4, count(self::assertTokensUnderPrefix('public/assets/css/pages/news-latest.css', '.news-latest')));
        self::assertStringContainsString("asset('/assets/css/pages/news-latest.css')", (string) file_get_contents(PSC_ROOT . '/templates/partials/news/latest.php'), 'pushed by the strip itself');
    }

    /**
     * Asserts that the stylesheet sits in the components layer, uses no raw colour, and names $prefix in each selector
     * of every rule not nested in another; returns those rules' selectors.
     *
     * @return list<string>
     */
    private static function assertTokensUnderPrefix(string $file, string $prefix): array
    {
        $css = (string) file_get_contents(PSC_ROOT . '/' . $file);
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        self::assertStringStartsWith('@layer components {', trim($code));
        self::assertSame(0, preg_match('/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i', $code), 'tokens only, no raw colours');
        // Every rule that is not nested in another rule names a news- class in each of its selectors.
        $outer = [];
        $stack = [];
        $prelude = '';
        foreach (str_split($code) as $ch) {
            if ($ch === '{') {
                $p = trim($prelude);
                if (!str_starts_with($p, '@') && !in_array('rule', $stack, true)) {
                    $outer[] = $p;
                }
                $stack[] = str_starts_with($p, '@') ? 'at' : 'rule';
                $prelude = '';
            } elseif ($ch === '}') {
                array_pop($stack);
                $prelude = '';
            } elseif ($ch === ';') {
                $prelude = '';
            } else {
                $prelude .= $ch;
            }
        }
        foreach ($outer as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                self::assertStringContainsString($prefix, $selector, $selectors);
            }
        }
        return $outer;
    }

    public function testTheScriptReportsClicksTheWayTheEndpointAccepts(): void
    {
        $js = (string) file_get_contents(PSC_ROOT . '/public/assets/js/news.js');
        self::assertStringContainsString('a[data-news-link]', $js, 'by its data hook, never a class');
        self::assertStringContainsString('`/api/news/links/${id}/click`', $js);
        self::assertStringContainsString("method: 'POST'", $js);
        self::assertStringContainsString('keepalive: true', $js);
        self::assertStringContainsString("'X-Requested-With': 'fetch'", $js);
        self::assertStringContainsString("'auxclick'", $js, 'a middle click too');
        self::assertStringNotContainsString('preventDefault', $js, 'the browser follows the link as it would anyway');
    }

    public function testTheShareCardIsBuiltAtItsSize(): void
    {
        $card = PSC_ROOT . '/public/assets/img/og/news.png';
        self::assertFileExists($card);
        $size = getimagesize($card);
        self::assertIsArray($size);
        self::assertSame([1200, 630], [$size[0], $size[1]]);
        self::assertLessThan(150 * 1024, (int) filesize($card));
        $pages = require PSC_ROOT . '/content/pages.php';
        self::assertSame('/assets/img/og/news.png', $pages['/news/']['og']);
        self::assertStringContainsString('news: {', (string) file_get_contents(PSC_ROOT . '/tools/og/build.mjs'), 'the card has its build');
    }

    /**
     * Each category's hub, and each of its articles without a picture, shares a card of its own: built from the
     * category's artwork and headline by tools/og/build.mjs, at the size share cards are, under 150 KB.
     */
    public function testEachCategoryHasItsOwnShareCard(): void
    {
        $build = (string) file_get_contents(PSC_ROOT . '/tools/og/build.mjs');
        self::assertStringContainsString('cards[`news-${key}`]', $build, 'one card per category in the build');
        self::assertStringContainsString("public/assets/img/news-art.svg", $build, 'drawn from the category artwork');
        foreach (Categories::KEYS as $key) {
            $card = PSC_ROOT . '/public' . Categories::shareCard($key);
            self::assertFileExists($card, $key);
            $size = getimagesize($card);
            self::assertIsArray($size, $key);
            self::assertSame([1200, 630, IMAGETYPE_PNG], [$size[0], $size[1], $size[2]], $key);
            self::assertLessThan(150 * 1024, (int) filesize($card), $key);
        }
    }

    /** A comment a moderator hid, shown to its author alone, looks set apart as well as saying so. */
    public function testAHiddenCommentHasAStyleOfItsOwn(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(PSC_ROOT . '/public/assets/css/pages/news-comments.css'));
        self::assertSame(1, preg_match('#\.news-comment--hidden \{([^}]*)#', $css, $rule));
        self::assertMatchesRegularExpression('#border:\s*1px dotted#', $rule[1]);
        self::assertSame(0, preg_match('/#[0-9a-f]{3,8}\b|\brgba?\(/i', $css), 'tokens only');
        self::assertStringContainsString("li.classList.add('news-comment--hidden')", (string) file_get_contents(PSC_ROOT . '/public/assets/js/news-comments.js'), 'the cached page marks it the same way');
    }

    public function testNoFileCarriesAnInlineStyleAScriptOrADash(): void
    {
        foreach (self::FILES as $file) {
            $text = (string) file_get_contents(PSC_ROOT . '/' . $file);
            self::assertStringNotContainsString("\u{2014}", $text, $file . ': no em dash');
            self::assertStringNotContainsString("\u{2013}", $text, $file . ': no en dash');
            if (str_starts_with($file, 'templates/')) {
                self::assertStringNotContainsString('style="', $text, $file);
                self::assertSame(0, preg_match('#<script(?![^>]*\bsrc=)#', $text), $file . ': scripts are files');
            }
        }
    }
}
