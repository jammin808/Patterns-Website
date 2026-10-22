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
        'public/assets/css/pages/news.css',
        'public/assets/js/news.js',
        'public/assets/img/news-art.svg',
        'content/news.php',
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
        $css = (string) file_get_contents(PSC_ROOT . '/public/assets/css/pages/news.css');
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
        self::assertGreaterThan(20, count($outer));
        foreach ($outer as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                self::assertStringContainsString('.news-', $selector, $selectors);
            }
        }
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
