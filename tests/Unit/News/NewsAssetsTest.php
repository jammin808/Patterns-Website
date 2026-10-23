<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use PHPUnit\Framework\TestCase;
use Psc\News\Art;
use Psc\News\Categories;

/**
 * The files Industry Updates' public pages are drawn with (docs/DESIGN.md 8.36): the category artwork is a test card
 * per category, printed by Psc\News\Art from its generated table as decoration painted only by the stylesheet's
 * classes in the seven bars, picked by the article's id so neighbours differ, and printed only on an edge its picture
 * leaves clear; the stylesheet uses tokens only under its own prefix, the script counts clicks the way the endpoint
 * accepts them, the share card exists at its size, and none of the files carries an inline style, an inline script or
 * a dash used as punctuation.
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
        'app/src/News/Art.php',
        'app/src/News/art-data.php',
        'tools/news-art/build.mjs',
        'tools/news-art/edges.mjs',
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

    /** The seven SMPTE bars, in their order, as the stylesheet's tokens name them. */
    private const BARS = ['grey', 'yellow', 'cyan', 'green', 'magenta', 'red', 'blue'];

    /**
     * Every category has its artwork, in Categories::KEYS order: four compositions of three takes, on the card and on
     * the article's band, twelve different pictures each, a share card's picture, and an edge map for every take.
     */
    public function testTheArtworkIsATestCardForEveryCategory(): void
    {
        $art = self::table();
        foreach (['card', 'band'] as $frame) {
            self::assertSame(Categories::KEYS, array_keys($art[$frame]), $frame . ': every category, in order');
            self::assertSame(Categories::KEYS, array_keys($art['edge'][$frame]), $frame . ': an edge map for every category');
            foreach (Categories::KEYS as $key) {
                self::assertCount(4, $art[$frame][$key], $key . ' ' . $frame . ': four compositions');
                foreach ($art[$frame][$key] as $v => $takes) {
                    self::assertCount(3, $takes, $key . ' ' . $frame . ' v' . $v . ': three takes');
                    self::assertCount(3, $art['edge'][$frame][$key][$v], $key . ' ' . $frame . ' v' . $v);
                }
                self::assertCount(12, array_unique(array_merge(...$art[$frame][$key])), $key . ' ' . $frame . ': twelve different pictures');
            }
        }
        self::assertSame(Categories::KEYS, array_keys(Art::SHARE), 'every category has its share card\'s picture');
        self::assertSame('', Art::tile('no-such-category', 1), 'an unknown key prints nothing');
    }

    /**
     * The artwork is decoration painted only by the stylesheet: nothing but span, svg, g and path; every svg hidden from
     * assistive technology and unable to take focus; no style, script, picture, title, words, link, id or tab stop; no
     * colour or paint in the markup, only classes pages/news.css defines; every take well-formed.
     */
    public function testTheArtworkIsDecorationPaintedOnlyByClasses(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(PSC_ROOT . '/public/assets/css/pages/news.css'));
        preg_match_all('/\.(news-art[a-z0-9-]*)/', $css, $m);
        $defined = array_flip($m[1]);
        $attributes = ['class', 'd', 'viewBox', 'preserveAspectRatio', 'aria-hidden', 'focusable', 'stroke-width', 'stroke-dasharray', 'fill-opacity'];
        $art = self::table();
        $errors = libxml_use_internal_errors(true);
        foreach (['card', 'band'] as $frame) {
            foreach ($art[$frame] as $key => $takes) {
                foreach (array_merge(...$takes) as $i => $take) {
                    $doc = new \DOMDocument();
                    self::assertTrue($doc->loadXML('<svg xmlns="http://www.w3.org/2000/svg">' . $take . '</svg>'), $key . ' ' . $frame . ' ' . $i . ': well-formed');
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        foreach (Categories::KEYS as $key) {
            for ($id = 0; $id < 336; $id++) {
                foreach ([[false, false], [false, true], [true, false]] as [$wide, $lead]) {
                    $tile = Art::tile($key, $id, $wide, $lead);
                    $where = $key . ' ' . $id . ($wide ? ' band' : ($lead ? ' lead' : ''));
                    self::assertMatchesRegularExpression('#^<span class="news-art news-art--p[0-6] news-art--f[0-3]' . ($wide ? ' news-art--wide' : '') . '"><svg [^>]+>.*</svg></span>$#s', $tile, $where);
                    self::assertSame(0, preg_match('#<(?!/?(?:span|svg|g|path)[\s>/])#', $tile), $where . ': only span, svg, g and path');
                    preg_match_all('#<svg\b[^>]*>#', $tile, $svgs);
                    self::assertCount($wide ? 2 : 1, $svgs[0], $where);
                    foreach ($svgs[0] as $svg) {
                        self::assertStringContainsString(' aria-hidden="true" focusable="false"', $svg, $where . ': hidden and never focused');
                    }
                    preg_match_all('#\s([a-zA-Z:-]+)=#', $tile, $names);
                    self::assertSame([], array_values(array_diff(array_unique($names[1]), $attributes)), $where . ': no style, id, link, paint or tab stop');
                    self::assertSame(0, preg_match('/#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(|url\(|var\(/i', $tile), $where . ': no colour of its own');
                    preg_match_all('#class="([^"]+)"#', $tile, $classes);
                    foreach (array_unique(explode(' ', implode(' ', $classes[1]))) as $class) {
                        self::assertArrayHasKey($class, $defined, $where . ': ' . $class . ' is a class of pages/news.css');
                    }
                }
            }
        }
    }

    /**
     * The artwork's colours are the seven bars, as their tokens: each palette runs through all seven from the bar it
     * names, its calm run (for a lone lamp, key or display) has every bar but green and red, so nothing alone reads as
     * a state, and the fixed SMPTE slots keep the bars' order. Everything else it paints is the screen's own greys.
     */
    public function testTheArtworksColoursAreTheSevenBars(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(PSC_ROOT . '/public/assets/css/pages/news.css'));
        $calm = array_values(array_diff(self::BARS, ['green', 'red']));
        for ($p = 0; $p < 7; $p++) {
            self::assertSame(1, preg_match('#\.news-art--p' . $p . ' \{([^}]*)\}#', $css, $rule), 'palette ' . $p);
            preg_match_all('#--na(\d): var\(--bar-([a-z]+)\)#', $rule[1], $na);
            preg_match_all('#--nq(\d): var\(--bar-([a-z]+)\)#', $rule[1], $nq);
            $run = array_merge(array_slice(self::BARS, $p), array_slice(self::BARS, 0, $p));
            self::assertSame($run, $na[2], 'palette ' . $p . ': the seven bars from ' . self::BARS[$p]);
            self::assertSame(array_values(array_diff($run, ['green', 'red'])), $nq[2], 'palette ' . $p . ': its calm run');
            self::assertEqualsCanonicalizing($calm, $nq[2]);
        }
        foreach (self::BARS as $i => $bar) {
            self::assertMatchesRegularExpression('#\.news-art-c' . $i . ' \{ fill: var\(--bar-' . $bar . '\); \}#', $css, 'SMPTE slot ' . $i);
        }
        preg_match_all('#\.news-art-[a-z]+\d? \{([^}]*)\}#', $css, $paints);
        foreach ($paints[1] as $rule) {
            foreach (explode(';', $rule) as $decl) {
                if (preg_match('#^\s*(fill|stroke):\s*(.+?)\s*$#', $decl, $d) === 1) {
                    self::assertMatchesRegularExpression('#^(none|var\(--(n[aq]\d|bar-[a-z]+|s1|s3|bg|line|line-2|text|text-3)\)|color-mix\(in srgb, var\(--text\) \d+%, transparent\))$#', $d[2], $decl);
                }
            }
        }
    }

    /**
     * The article's id picks its picture: the same id always the same one; any four ids in a row are four
     * compositions, so a card never shares one with the card beside or above it; of nine in a row, two that share a
     * composition differ in take and in lead; the whole repeats every 336 ids and no sooner.
     */
    public function testTheArticlesIdPicksAPictureItsNeighboursDoNotShare(): void
    {
        $seen = [];
        for ($i = 0; $i < 1344; $i++) {
            $a = Art::pick($i);
            if ($i < 336) {
                $seen[implode(',', $a)] = true;
            }
            self::assertSame($a, Art::pick($i + 336));
            for ($d = 1; $d <= 8; $d++) {
                $b = Art::pick($i + $d);
                if ($d <= 3) {
                    self::assertNotSame($a['v'], $b['v'], 'ids ' . $i . ' and ' . ($i + $d) . ': four in a row are four compositions');
                } elseif ($a['v'] === $b['v']) {
                    self::assertNotSame($a['k'], $b['k'], 'ids ' . $i . ' and ' . ($i + $d) . ': another take');
                    self::assertNotSame($a['p'], $b['p'], 'ids ' . $i . ' and ' . ($i + $d) . ': another lead');
                }
            }
        }
        self::assertCount(336, $seen, 'every composition, take, lead and edge once in 336 ids');
        foreach (Categories::KEYS as $key) {
            self::assertSame(Art::tile($key, 101), Art::tile($key, 101), $key);
            self::assertSame(Art::tile($key, 101), Art::tile($key, 101 + 336), $key);
            self::assertSame(Art::tile($key, 0), Art::tile($key, -7), $key . ': a negative id is id 0');
        }
    }

    /**
     * Each picture prints only an edge it leaves clear: tools/news-art/edges.mjs measured every take as it is drawn
     * (its signature matches), and each take's map prints an allowed edge as itself and any other as an allowed one
     * (on the band, allowed for both its pictures), so no figure stands on a strip of bars.
     */
    public function testEveryTakePrintsOnlyAnEdgeItsPictureLeavesClear(): void
    {
        $edges = json_decode((string) file_get_contents(PSC_ROOT . '/tools/news-art/edges.json'), true, 512, JSON_THROW_ON_ERROR);
        $art = self::table();
        foreach (Categories::KEYS as $key) {
            for ($v = 0; $v < 4; $v++) {
                for ($k = 0; $k < 3; $k++) {
                    $where = $key . ' v' . $v . ' k' . $k;
                    foreach (['card', 'band'] as $frame) {
                        self::assertSame($edges[$frame][$key][$v][$k]['sig'], self::sig($art[$frame][$key][$v][$k]), $where . ' ' . $frame . ': measured as drawn (node tools/news-art/edges.mjs)');
                    }
                    $card = $edges['card'][$key][$v][$k]['ok'];
                    foreach (['card' => $card, 'band' => array_values(array_intersect($edges['band'][$key][$v][$k]['ok'], $card))] as $frame => $ok) {
                        self::assertNotSame([], $ok, $where . ' ' . $frame . ': an edge it leaves clear');
                        $map = $art['edge'][$frame][$key][$v][$k];
                        for ($f = 0; $f < 4; $f++) {
                            self::assertContains((int) $map[$f], $ok, $where . ' ' . $frame . ': edge ' . $f);
                            if (in_array($f, $ok, true)) {
                                self::assertSame($f, (int) $map[$f], $where . ' ' . $frame . ': a clear edge prints as itself');
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Nothing in the artwork reaches into another document, so no external sprite (whose gradients, patterns and masks
     * not every browser paints through use) can come back: no use, href or url() in the markup (the element and
     * attribute lists above), and no url() in its rules; the tiles are inline and painted by the page's own classes.
     */
    public function testTheArtworkReachesIntoNoOtherDocument(): void
    {
        $css = (string) file_get_contents(PSC_ROOT . '/public/assets/css/pages/news.css');
        $start = strpos($css, '/* ===== Artwork');
        $end = strpos($css, '/* ===== The article');
        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertSame(0, preg_match('#url\(|@import|image-set\(#', substr($css, $start, $end - $start)), 'the artwork\'s rules load nothing');
        $table = (string) file_get_contents(PSC_ROOT . '/app/src/News/art-data.php');
        self::assertSame(0, preg_match('#<use\b|href=|url\(|<defs|Gradient|<pattern|<mask|<clipPath|<filter|<symbol|<image#i', $table), 'no reference, sprite or paint server in the table');
        self::assertFileDoesNotExist(PSC_ROOT . '/public/assets/img/news-art.svg', 'the old sprite is gone');
    }

    /** A list of twelve of a category's heaviest picture stays inside the artwork's budget, 25,000 bytes of HTML. */
    public function testAListOfTwelveStaysInsideTheArtworksBudget(): void
    {
        foreach (Categories::KEYS as $key) {
            $max = 0;
            for ($id = 0; $id < 336; $id++) {
                $max = max($max, strlen(Art::tile($key, $id)));
            }
            self::assertLessThanOrEqual(25000, 12 * $max, $key);
        }
    }

    /**
     * The generated table (tools/news-art/build.mjs).
     *
     * @return array{card: array<string, list<list<string>>>, band: array<string, list<list<string>>>, edge: array{card: array<string, list<list<string>>>, band: array<string, list<list<string>>>}}
     */
    private static function table(): array
    {
        return require PSC_ROOT . '/app/src/News/art-data.php';
    }

    /** A take's signature as edges.mjs records it: FNV-1a over its bytes, in base 36. */
    private static function sig(string $s): string
    {
        $h = 2166136261;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $h = (($h ^ ord($s[$i])) * 16777619) & 0xFFFFFFFF;
        }
        return base_convert((string) $h, 10, 36);
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
     * category's artwork (Art::tile() for its Art::SHARE id) and headline by tools/og/build.mjs, at the size share
     * cards are, under 150 KB.
     */
    public function testEachCategoryHasItsOwnShareCard(): void
    {
        $build = (string) file_get_contents(PSC_ROOT . '/tools/og/build.mjs');
        self::assertStringContainsString('cards[`news-${key}`]', $build, 'one card per category in the build');
        self::assertStringContainsString('Art::SHARE', $build, 'drawn from the artwork its pages print');
        self::assertStringContainsString('Art::tile(', $build);
        self::assertStringContainsString('/assets/css/pages/news.css', (string) file_get_contents(PSC_ROOT . '/tools/og/template.html'), 'painted by the pages\' own stylesheet');
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
