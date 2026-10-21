<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Content\Content;
use Psc\Tests\TestCase;

final class SiteTest extends TestCase
{
    public function testSecurityHeadersOnPages(): void
    {
        $r = $this->request('GET', '/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString("default-src 'self'", (string) $r->getHeader('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $r->getHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        self::assertStringContainsString('max-age=31536000', (string) $r->getHeader('Strict-Transport-Security'));
        self::assertStringStartsWith('public', (string) $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies(), 'public pages set no cookies');
    }

    public function testUnknownPagesAre404AndNoindex(): void
    {
        $r = $this->request('GET', '/no-such-page/');
        self::assertSame(404, $r->status);
        self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
    }

    public function testCanonicalRedirects(): void
    {
        foreach (array_keys(Content::pages()) as $path) {
            if ($path === '/') {
                continue;
            }
            $r = $this->request('GET', rtrim($path, '/'));
            self::assertSame(301, $r->status, $path);
            self::assertSame($path, $r->getHeader('Location'));
            break;
        }
        $r = $this->request('GET', '/StageWatch');
        self::assertSame(301, $r->status);
    }

    public function testSitemapRobotsAndLlms(): void
    {
        $sitemap = $this->request('GET', '/sitemap.xml');
        self::assertSame(200, $sitemap->status);
        $xml = simplexml_load_string($sitemap->body);
        self::assertNotFalse($xml);
        $locs = array_map('strval', iterator_to_array($xml->url->loc ?? [], false));
        foreach ($xml->url as $u) {
            self::assertStringStartsWith('https://patternsshowcontrol.test/', (string) $u->loc);
            self::assertStringNotContainsString('/portal/', (string) $u->loc);
        }
        self::assertNotEmpty($xml->url);

        $robots = $this->request('GET', '/robots.txt')->body;
        self::assertStringContainsString('Disallow: /api/', $robots);
        self::assertStringNotContainsString('Disallow: /portal/', $robots);
        self::assertStringContainsString('Sitemap: https://patternsshowcontrol.test/sitemap.xml', $robots);

        $llms = $this->request('GET', '/llms.txt');
        self::assertSame(200, $llms->status);
        self::assertStringStartsWith('# Patterns Show Control', $llms->body);
    }

    public function testSecurityTxtAndManifest(): void
    {
        self::assertStringContainsString('Contact: mailto:', $this->request('GET', '/.well-known/security.txt')->body);
        $m = json_decode($this->request('GET', '/manifest.webmanifest')->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Patterns Show Control', $m['name']);
    }

    /** The manifest's colours are the dark theme's page colour, the same as the layout's theme-color meta. */
    public function testManifestColoursMatchTheBrandDark(): void
    {
        $m = json_decode($this->request('GET', '/manifest.webmanifest')->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, preg_match('/<meta name="theme-color" content="([^"]+)">/', $this->request('GET', '/')->body, $meta));
        preg_match('/--bg: light-dark\(#[0-9A-Fa-f]{6}, (#[0-9A-Fa-f]{6})\)/', (string) file_get_contents(PSC_ROOT . '/public/assets/css/site.css'), $bg);
        self::assertSame('#090C10', $bg[1] ?? null, 'the dark theme --bg token');
        self::assertSame($bg[1], $meta[1]);
        self::assertSame($bg[1], $m['theme_color']);
        self::assertSame($bg[1], $m['background_color']);
    }

    /**
     * Every FAQPage node's @id is the page URL with one fragment, and that fragment is the id of the FAQ block it
     * describes; FAQ blocks keep unique anchors when a page has two.
     */
    public function testFaqSchemaIdsAreOneFragmentNamingTheirBlock(): void
    {
        $expect = [
            '/' => '#faq-home',
            '/ecosystem/' => '#faq-eco',
            '/licensing/' => '#faq-licensing',
            '/stagewatch/' => '#faq-stagewatch',
            '/stagewatch/licensing/' => '#faq-stagewatch-licence',
            '/support/stagewatch/' => '#faq-trouble',
        ];
        foreach ($expect as $path => $fragment) {
            $body = $this->request('GET', $path)->body;
            $faqs = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'FAQPage'));
            self::assertCount(1, $faqs, $path . ' has one FAQPage');
            $id = (string) $faqs[0]['@id'];
            self::assertSame('https://patternsshowcontrol.test' . $path . $fragment, $id, $path);
            self::assertSame(1, substr_count($id, '#'), $path . ' @id has one fragment');
            self::assertNotEmpty($faqs[0]['mainEntity']);

            preg_match_all('/<div class="c-faq" id="([^"]+)">/', $body, $blocks);
            self::assertContains(substr($fragment, 1), $blocks[1], $path . ' the @id names a visible block');
            preg_match_all('/\sid="([^"]+)"/', $body, $ids);
            self::assertSame(array_unique($ids[1]), $ids[1], $path . ' ids are unique');
        }
        preg_match_all('/<div class="c-faq" id="([^"]+)">/', $this->request('GET', '/support/stagewatch/')->body, $two);
        self::assertSame(['faq-trouble', 'faq-questions'], $two[1]);
    }

    /** The FAQ page's one FAQPage covers every group, and its @id fragment is the element that holds them all. */
    public function testFaqPageSchemaIdNamesTheGroups(): void
    {
        $body = $this->request('GET', '/support/faq/')->body;
        $faqs = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'FAQPage'));
        self::assertCount(1, $faqs);
        self::assertSame('https://patternsshowcontrol.test/support/faq/#faq', (string) $faqs[0]['@id']);
        self::assertMatchesRegularExpression('/<div class="support-faq-groups" id="faq">/', $body);
        preg_match_all('/\sid="([^"]+)"/', $body, $ids);
        self::assertSame(array_unique($ids[1]), $ids[1], 'ids are unique');
    }

    /** A hyphenated word in a question is kept on one line, as in the product headings. */
    public function testFaqQuestionsKeepHyphenatedWordsWhole(): void
    {
        self::assertMatchesRegularExpression('#<summary class="c-faq-q"><span>[^<]*<span class="u-nowrap">14-day</span>#', $this->request('GET', '/stagewatch/licensing/')->body);
        $checked = 0;
        foreach (['/stagewatch/licensing/', '/agmessage/', '/patterns/', '/playmap/', '/support/faq/'] as $path) {
            preg_match_all('#<summary class="c-faq-q"><span>(.*?)</span><span class="c-xhair"#s', $this->request('GET', $path)->body, $qs);
            self::assertNotEmpty($qs[1], $path);
            foreach ($qs[1] as $q) {
                $checked += preg_match_all('#<span class="u-nowrap">[\p{L}\d]+(?:-[\p{L}\d]+)+</span>#u', $q);
                $rest = strip_tags((string) preg_replace('#<span class="u-nowrap">[^<]*</span>#', '', $q));
                self::assertDoesNotMatchRegularExpression('/[\p{L}\d]-[\p{L}\d]/u', $rest, $path . ': ' . $q);
            }
        }
        self::assertGreaterThan(0, $checked, 'the content has hyphenated questions to keep whole');
    }

    /**
     * The breadcrumbs open the page hero: first inside its .l-wrap, before the hero grid, once per page; the
     * BreadcrumbList structured data is unchanged. Home has none.
     */
    public function testBreadcrumbsOpenThePageHero(): void
    {
        foreach (['/stagewatch/', '/stagewatch/licensing/', '/ecosystem/', '/support/faq/', '/legal/privacy/', '/contact/', '/about/'] as $path) {
            $body = $this->request('GET', $path)->body;
            self::assertSame(1, substr_count($body, '<nav class="c-crumbs"'), $path);
            self::assertMatchesRegularExpression('#<main id="main"[^>]*>\s*<section class="c-hero[^"]*"[^>]*>\s*<div class="l-wrap[^"]*"><nav class="c-crumbs" aria-label="Breadcrumb">.*?</nav>\s*<div class="c-hero-grid#s', $body, $path);
            $crumbs = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'BreadcrumbList'));
            self::assertCount(1, $crumbs, $path);
            $items = $crumbs[0]['itemListElement'];
            self::assertSame('Home', $items[0]['name']);
            self::assertSame(count($items), $items[count($items) - 1]['position']);
            self::assertStringEndsWith($path, (string) $items[count($items) - 1]['item']);
        }
        self::assertStringNotContainsString('c-crumbs', $this->request('GET', '/')->body);
    }

    /** A page that does not open with a hero gets the trail as a strip of its own above the content. */
    public function testBreadcrumbsWithoutAHeroAreAStrip(): void
    {
        $seo = $this->app->seo('A page', 'A page without a hero.', '/about/')->crumb('Home', '/')->crumb('About', '/about/');
        $this->app->view()->share('seo', $seo);
        $html = $this->app->view()->render('layouts/base', ['content' => '<section class="l-section"><div class="l-wrap"><h1>A page</h1></div></section>', 'seo' => $seo]);
        self::assertMatchesRegularExpression('#<main id="main">\s*<div class="l-wrap c-crumbs-bar"><nav class="c-crumbs" aria-label="Breadcrumb">.*?</nav>\s*</div>\s*<section class="l-section">#s', $html);
        self::assertSame(1, substr_count($html, '<nav class="c-crumbs"'));

        // A hero whose wrap is itself the grid (the home hero's shape) would make the trail a grid cell: a strip too.
        $grid = '<section class="c-hero" aria-labelledby="t"><div class="l-wrap c-hero-grid"><div><h1 id="t">A page</h1></div></div></section>';
        $html = $this->app->view()->render('layouts/base', ['content' => $grid, 'seo' => $seo]);
        self::assertMatchesRegularExpression('#<main id="main">\s*<div class="l-wrap c-crumbs-bar"><nav class="c-crumbs" aria-label="Breadcrumb">.*?</nav>\s*</div>\s*<section class="c-hero"#s', $html);
        self::assertStringContainsString('<div class="l-wrap c-hero-grid"><div><h1', $html);
        self::assertSame(1, substr_count($html, '<nav class="c-crumbs"'));
    }

    /** All four products' menus and sub-navigation use the same labels, in the same order. */
    public function testProductLinksShareOneSetOfLabels(): void
    {
        foreach (Content::get('site')['nav'] as $item) {
            foreach ($item['children'] ?? [] as $child) {
                $key = (string) $child['product'];
                self::assertSame(['Overview', 'Features', 'Licensing', 'Help'], array_column($child['links'], 'label'), $key);
                self::assertSame(['/' . $key . '/', '/' . $key . '/features/', '/' . $key . '/licensing/', '/support/' . $key . '/'], array_column($child['links'], 'href'), $key);
            }
        }
        self::assertStringContainsString('<a href="/stagewatch/licensing/" aria-current="page">Licensing</a>', $this->request('GET', '/stagewatch/licensing/')->body);
    }

    /** The Show Bus points use the icons their content names, and each is a symbol in the sprite. */
    public function testEcosystemShowBusUsesItsOwnIcons(): void
    {
        $sprite = (string) file_get_contents(PSC_ROOT . '/public/assets/img/icons.svg');
        $body = $this->request('GET', '/ecosystem/')->body;
        foreach (Content::get('ecosystem')['show_bus']['points'] as $pt) {
            $icon = (string) $pt['icon'];
            self::assertStringContainsString('id="i-' . $icon . '"', $sprite, $icon);
            self::assertStringContainsString('<use href="#i-' . $icon . '"/></svg><h3>' . htmlspecialchars((string) $pt['title'], ENT_QUOTES), $body, $icon);
        }
    }

    /** @return list<array<string, mixed>> the page's JSON-LD @graph */
    private static function graph(string $body): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m));
        $ld = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        return $ld['@graph'];
    }
}
