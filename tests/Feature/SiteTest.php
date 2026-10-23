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

    /**
     * The contact page is in the sitemap and its meta robots says index, but the route's nostore step replaced the
     * page's own X-Robots-Tag with noindex, which search engines obey. The page's choice now stands; the portal's
     * pages stay noindex.
     */
    public function testTheContactPageIsIndexableAndThePortalIsNot(): void
    {
        $contact = $this->request('GET', '/contact/');
        self::assertSame(200, $contact->status);
        self::assertStringNotContainsString('noindex', (string) $contact->getHeader('X-Robots-Tag'));
        self::assertMatchesRegularExpression('#<meta name="robots" content="index, follow[^"]*">#', $contact->body);
        self::assertSame('private, no-store', $contact->getHeader('Cache-Control'), 'still never cached: it holds a form token');
        self::assertStringContainsString('<loc>https://patternsshowcontrol.test/contact/</loc>', $this->request('GET', '/sitemap.xml')->body);

        foreach (['/portal/login/', '/portal/register/', '/portal/forgot/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame('noindex, nofollow', $r->getHeader('X-Robots-Tag'), $path);
            self::assertSame('private, no-store', $r->getHeader('Cache-Control'), $path);
        }
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

    /**
     * The lowercase redirect echoed the decoded path into Location, so /%5CEvil.com became "Location: /\evil.com",
     * which browsers follow to another host. A path holding a backslash, a control character or a space is no page.
     */
    public function testEncodedBackslashesAndControlCharactersAreNeverRedirected(): void
    {
        foreach (['/%5CEvil.com', '/%09/Evil.com', '/%5C/Evil.com', '/%5C%5CAttacker.example/y', '/%0D%0A/Evil.com', '/Evil%20.com', '/patterns%5C'] as $uri) {
            $r = $this->request('GET', $uri);
            self::assertSame(404, $r->status, $uri);
            self::assertNull($r->getHeader('Location'), $uri);
        }
    }

    /** A mixed-case path is lowercased only when the lowercase path is a page; otherwise it is a plain 404. */
    public function testTheLowercaseRedirectOnlyLeadsToPages(): void
    {
        $nope = $this->request('GET', '/Nope');
        self::assertSame(404, $nope->status);
        self::assertNull($nope->getHeader('Location'));

        $patterns = $this->request('GET', '/Patterns');
        self::assertSame(301, $patterns->status);
        self::assertSame('/patterns/', $patterns->getHeader('Location'));

        $deep = $this->request('GET', '/PATTERNS/Features/?utm_source=x');
        self::assertSame(301, $deep->status);
        self::assertSame('/patterns/features/?utm_source=x', $deep->getHeader('Location'));

        // Only a page with nothing in its path a route reads as a value: an article's slug or a category key in
        // capitals is no page, so it is a 404, never a permanent hop to a guess.
        self::assertSame([301, '/news/'], [$this->request('GET', '/News/')->status, $this->request('GET', '/News/')->getHeader('Location')]);
        foreach (['/news/NOPE/', '/news/Some-Typo/comment/', '/news/Video/', '/news/video/Some-Story/', '/NEWS/video/some-story/', '/news/NOPE'] as $uri) {
            $r = $this->request('GET', $uri);
            self::assertSame(404, $r->status, $uri);
            self::assertNull($r->getHeader('Location'), $uri);
        }
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

    /** llms.txt said every licensing page had prices; only a product with prices on its page says so now. */
    public function testLlmsTxtClaimsPricesOnlyWherePricesArePublished(): void
    {
        $llms = $this->request('GET', '/llms.txt')->body;
        foreach (Content::PRODUCTS as $key) {
            self::assertSame(1, preg_match('#^- \[[^\]]+ licensing\]\(https://patternsshowcontrol\.test/' . $key . '/licensing/\): (.+)$#m', $llms, $m), $key);
            $live = (Content::licensing($key)['pricing_status'] ?? 'live') === 'live';
            if ($live) {
                self::assertStringContainsString('prices', $m[1], $key);
            } else {
                self::assertStringNotContainsString('prices', $m[1], $key . ': prices are not announced');
            }
        }
        self::assertStringContainsString('priced on application', $llms, 'AGMessage is quoted per AGM');
    }

    public function testSecurityTxtAndManifest(): void
    {
        $txt = $this->request('GET', '/.well-known/security.txt')->body;
        // The contact page's https URI (RFC 9116 allows one), so the file publishes no address to collect.
        self::assertSame(1, preg_match('/^Contact: (\S+)$/m', $txt, $contact));
        self::assertSame('https://patternsshowcontrol.test/contact/', $contact[1]);
        self::assertStringNotContainsString('mailto:', $txt);
        // Policy is for a disclosure policy (RFC 9116), not the privacy notice; Expires rolls, so it never falls due
        // within the file's cache life (on 31 December it used to be hours away).
        self::assertStringNotContainsString('Policy:', $txt);
        self::assertSame(1, preg_match('/^Expires: (\S+)$/m', $txt, $m));
        $expires = (int) strtotime($m[1]);
        self::assertGreaterThan(time() + 150 * 86400, $expires);
        self::assertLessThan(time() + 365 * 86400, $expires);
        $m = json_decode($this->request('GET', '/manifest.webmanifest')->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Patterns Show Control', $m['name']);
    }

    /**
     * /health answered "ok" on a deploy without vendor/, so the assistant was offline on every page while the smoke
     * test passed. Once the assistant is switched on with a key, its SDK is part of being healthy.
     */
    public function testHealthCountsTheAssistantsSdkOnceTheAssistantIsConfigured(): void
    {
        $r = $this->request('GET', '/health');
        self::assertSame(200, $r->status);
        $body = json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertSame(['storage' => true, 'vendor' => true, 'database' => true, 'config_shadowed' => false, 'schema' => true], $body['checks']);
        self::assertSame(\Psc\Controllers\SeoController::build(), $body['build'], 'the build it runs, for the deploy job to wait on');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,40}$/', $body['build']);
        self::assertStringContainsString('no-store', (string) $r->getHeader('Cache-Control'), 'never served from a cache');

        $this->bootApp(['chat' => ['api_key' => 'sk-test']]);
        self::assertSame(200, $this->request('GET', '/health')->status, 'the SDK is installed');

        $working = ['storage' => true, 'vendor' => true, 'database' => true];
        $noSdk = ['vendor' => false] + $working;
        self::assertTrue(\Psc\Controllers\SeoController::healthy($working, true));
        self::assertFalse(\Psc\Controllers\SeoController::healthy($noSdk, true), 'a configured assistant without its SDK is degraded');
        self::assertTrue(\Psc\Controllers\SeoController::healthy($noSdk, false), 'an assistant left off needs no SDK');
        self::assertFalse(\Psc\Controllers\SeoController::healthy(['database' => false] + $working, false));
        self::assertFalse(\Psc\Controllers\SeoController::healthy(['schema' => false] + $working, false), 'a database missing its tables is degraded');
    }

    /** A config/local.php uploaded after the server file outside the web root exists is reported, not read. */
    public function testHealthReportsAShadowedLocalConfig(): void
    {
        $this->bootShadowed();
        $r = $this->request('GET', '/health');
        self::assertSame(200, $r->status, 'the site still runs on the file it read');
        self::assertTrue(json_decode($r->body, true, 512, JSON_THROW_ON_ERROR)['checks']['config_shadowed']);
        self::assertStringContainsString('max-age=300', (string) $this->request('GET', '/')->getHeader('Cache-Control'), 'the shadowed file changes nothing');
    }

    /**
     * No page a visitor or crawler can fetch names an email address, so none can be collected for spam: every
     * page in the registry and every workflow, the machine-readable files and the portal's sign-in pages. The way
     * to reach a person is the contact form, a support ticket or the assistant; the organisation's structured data
     * points at the contact page.
     */
    public function testNoPublicPageNamesAnEmailAddress(): void
    {
        $paths = array_keys(Content::pages());
        foreach (array_keys(Content::get('workflows')) as $slug) {
            $paths[] = '/workflows/' . $slug . '/';
        }
        $paths = array_merge(array_values(array_unique($paths)), [
            '/llms.txt', '/.well-known/security.txt', '/sitemap.xml', '/robots.txt', '/manifest.webmanifest',
            '/portal/login/', '/portal/register/', '/portal/forgot/', '/portal/verify/resend/', '/portal/verify/', '/portal/reset/',
        ]);
        self::assertGreaterThan(30, count($paths));
        $address = '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i';
        foreach ($paths as $path) {
            $r = $this->request('GET', (string) $path);
            self::assertSame(200, $r->status, (string) $path);
            self::assertSame(0, preg_match($address, $r->body, $m), $path . ' names ' . ($m[0] ?? ''));
            self::assertStringNotContainsString('mailto:', $r->body, (string) $path);
        }
        $missing = $this->request('GET', '/no-such-page/');
        self::assertSame(404, $missing->status);
        self::assertSame(0, preg_match($address, $missing->body), 'nor does the page for a missing address');

        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $this->request('GET', '/')->body, $ld));
        $graph = json_decode($ld[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
        $org = array_values(array_filter($graph, static fn (array $n): bool => ($n['@type'] ?? '') === 'Organization'))[0];
        self::assertSame([['@type' => 'ContactPoint', 'contactType' => 'customer support', 'url' => 'https://patternsshowcontrol.test/contact/', 'availableLanguage' => ['en-GB']]], $org['contactPoint']);
        self::assertArrayNotHasKey('email', $org);
    }

    /**
     * With the latest-articles file in place, the home page and each product page carry their strip of Industry Updates
     * articles, and every registry page still answers whole, public, without a cookie and without an email address;
     * the strip leads to the section and to the hubs it draws from.
     */
    public function testEveryRegistryPageStillAnswersWithTheNewsStripsInPlace(): void
    {
        foreach (['video', 'streaming', 'automation', 'showcalling', 'production', 'legal', 'ai', 'interactive', 'lighting'] as $i => $key) {
            $this->makeArticle(['title' => 'A strip story about ' . $key . ' for the registry pages', 'categories' => [$key], 'published_at' => time() - 60 * ($i + 1)]);
        }
        self::assertTrue(\Psc\News\Latest::refresh($this->app));
        $address = '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i';
        foreach (array_keys(Content::pages()) as $path) {
            $r = $this->request('GET', (string) $path);
            self::assertSame(200, $r->status, (string) $path);
            self::assertSame([], $r->cookies(), (string) $path);
            self::assertSame(0, preg_match($address, $r->body), (string) $path);
            $strip = str_contains($r->body, 'class="l-section l-section--tight news-latest"');
            self::assertSame(in_array($path, ['/', '/patterns/', '/stagewatch/', '/agmessage/', '/playmap/'], true), $strip, $path . ': the strip only where it belongs');
            if ($strip) {
                self::assertStringContainsString('<a href="/news/">All Industry Updates</a>', $r->body, (string) $path);
                self::assertSame(3, substr_count($r->body, '<li class="news-latest-item">'), $path . ': three articles');
            }
        }
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
     * describes; FAQ blocks keep unique anchors when a page has two. Pages that only repeat questions marked up
     * elsewhere, and troubleshooting lists (statements, not questions), keep their accordions but add no node.
     */
    public function testFaqSchemaIdsAreOneFragmentNamingTheirBlock(): void
    {
        $expect = [
            '/ecosystem/' => '#faq-eco',
            '/stagewatch/' => '#faq-stagewatch',
            '/stagewatch/licensing/' => '#faq-stagewatch-licence',
            '/workflows/conference-timing/' => '#faq-wf',
        ];
        foreach (['/' => 'faq-home', '/licensing/' => 'faq-licensing', '/support/stagewatch/' => 'faq-trouble'] as $path => $block) {
            $body = $this->request('GET', $path)->body;
            self::assertSame([], array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'FAQPage')), $path . ' marks up no FAQ');
            self::assertStringContainsString('<div class="c-faq" id="' . $block . '">', $body, $path . ' still shows its questions');
        }
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

    /**
     * Google asks for each FAQ to be marked up on one page only: 25 questions were marked up on two or three pages.
     * Across the whole site each question is now in one FAQPage, and every marked-up question is shown on its page.
     */
    public function testEachFaqQuestionIsMarkedUpOnOnePageOnly(): void
    {
        $where = [];
        foreach (array_keys(Content::pages()) as $path) {
            $body = $this->request('GET', $path)->body;
            foreach (self::graph($body) as $node) {
                if (($node['@type'] ?? '') !== 'FAQPage') {
                    continue;
                }
                self::assertFalse(str_starts_with($path, '/support/') && $path !== '/support/faq/', $path . ': troubleshooting pages mark up no FAQ');
                $text = strip_tags($body);
                foreach ($node['mainEntity'] as $q) {
                    $where[(string) $q['name']][] = $path;
                    self::assertStringContainsString(htmlspecialchars((string) $q['name'], ENT_QUOTES), $text, $path . ': the marked-up question is shown on the page');
                }
            }
        }
        self::assertGreaterThan(50, count($where), 'the site still marks up its questions');
        $twice = array_filter($where, static fn (array $paths): bool => count($paths) > 1);
        self::assertSame([], $twice, 'no question is marked up on two pages');
        self::assertSame('/stagewatch/', Content::faqOwner('Is there a free trial?'), 'a product question belongs to its product page');
    }

    /**
     * The founder and the workflow guides' author were anonymous Person nodes; the About page's Person stood
     * alone. One Person, in every page's graph, is now what both point at, and every reference resolves on its page.
     */
    public function testThePublisherIsOnePersonThatEveryReferenceReaches(): void
    {
        $person = 'https://patternsshowcontrol.test/about/#ben';
        foreach (array_keys(Content::pages()) as $path) {
            $graph = self::graph($this->request('GET', $path)->body);
            $ids = array_values(array_filter(array_map(static fn (array $n): ?string => $n['@id'] ?? null, $graph)));
            self::assertSame(array_unique($ids), $ids, $path . ': no @id twice');
            $byType = [];
            foreach ($graph as $n) {
                $byType[(string) ($n['@type'] ?? '')][] = $n;
            }
            self::assertSame(['@id' => $person], $byType['Organization'][0]['founder'] ?? null, $path);
            self::assertCount(1, array_filter($byType['Person'] ?? [], static fn (array $n): bool => ($n['@id'] ?? '') === $person), $path);
            foreach ($byType['TechArticle'] ?? [] as $article) {
                self::assertSame(['@id' => $person], $article['author'] ?? null, $path);
                self::assertContains($article['mainEntityOfPage']['@id'] ?? '', $ids, $path . ': the article names its page');
            }
        }
        $about = self::graph($this->request('GET', '/about/')->body);
        $ben = array_values(array_filter($about, static fn (array $n): bool => ($n['@id'] ?? '') === $person))[0];
        self::assertSame(['Person', 'Ben Rohleder', 'https://patternsshowcontrol.test/about/'], [$ben['@type'], $ben['name'], $ben['url']]);
    }

    /** The FAQ page's one FAQPage covers every group, and its @id fragment is the element that holds them all. */
    public function testFaqPageSchemaIdNamesTheGroups(): void
    {
        $body = $this->request('GET', '/support/faq/')->body;
        $faqs = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'FAQPage'));
        self::assertCount(1, $faqs);
        self::assertSame('https://patternsshowcontrol.test/support/faq/#faq', (string) $faqs[0]['@id']);
        // It stands in for the page's WebPage node, so it names the page and the site it belongs to.
        self::assertSame('https://patternsshowcontrol.test/support/faq/', $faqs[0]['url'] ?? null);
        self::assertSame(Content::page('/support/faq/')['title'], $faqs[0]['name'] ?? null);
        self::assertSame('en-GB', $faqs[0]['inLanguage'] ?? null);
        self::assertSame(['@id' => 'https://patternsshowcontrol.test/#website'], $faqs[0]['isPartOf'] ?? null);
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

    /** The mega-menu's "Licensing" links went to four pages under one anchor text; each now names its product. */
    public function testMegaMenuLinksNameTheirProduct(): void
    {
        $body = $this->request('GET', '/')->body;
        foreach (['patterns' => 'Patterns', 'stagewatch' => 'StageWatch', 'agmessage' => 'AGMessage', 'playmap' => 'PlayMap'] as $key => $name) {
            self::assertStringContainsString('<a href="/' . $key . '/licensing/"><span class="u-visually-hidden">' . $name . ' </span>Licensing</a>', $body, $key);
            self::assertStringContainsString('<a href="/support/' . $key . '/"><span class="u-visually-hidden">' . $name . ' </span>Help</a>', $body, $key);
        }
    }

    /** Every share card was described as "Patterns Show Control"; a card now says what it shows. */
    public function testShareCardsAreDescribedByTheirPage(): void
    {
        $alt = static function (string $body): string {
            self::assertSame(1, preg_match('#<meta property="og:image:alt" content="([^"]*)">#', $body, $m));
            return html_entity_decode($m[1], ENT_QUOTES);
        };
        self::assertSame(Content::page('/patterns/')['title'], $alt($this->request('GET', '/patterns/')->body));
        self::assertSame('StageWatch prices: Standard £9, Pro £29, Production £59 launch price (was £79)', $alt($this->request('GET', '/stagewatch/licensing/')->body));
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

    /**
     * The StageWatch Production offer in the structured data said £79 while every page sold it at the £59 launch
     * price, and the assistant's tier list said £79 too. One field, launch_price, now feeds them all.
     */
    public function testTheLaunchPriceIsThePriceEverywhere(): void
    {
        foreach (['/stagewatch/', '/stagewatch/licensing/'] as $path) {
            $body = $this->request('GET', $path)->body;
            $software = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'SoftwareApplication'));
            self::assertCount(1, $software, $path);
            $offers = array_column($software[0]['offers'] ?? [], 'price', 'name');
            self::assertSame('59', $offers['StageWatch Production'] ?? null, $path);
            self::assertSame('29', $offers['StageWatch Pro'] ?? null, $path);
        }
        self::assertStringContainsString('<b>£59</b> <s><span class="u-visually-hidden">was </span>£79</s> launch price', $this->request('GET', '/stagewatch/licensing/')->body);
        $knowledge = (new \Psc\Chat\Knowledge(PSC_ROOT, $this->storage . '/cache'))->compile();
        self::assertStringContainsString('- Production: £59 launch price (list price £79), 2 machine(s)', $knowledge);
        self::assertStringNotContainsString('- Production: £79', $knowledge);
    }

    /** The schema's operatingSystem names operating systems, while the page keeps its sentence. */
    public function testOperatingSystemsInTheSchemaAreOperatingSystems(): void
    {
        $body = $this->request('GET', '/agmessage/')->body;
        $software = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === 'SoftwareApplication'));
        self::assertSame('Windows (64-bit)', $software[0]['operatingSystem']);
        self::assertStringContainsString('everyone joins from a web browser', $body, 'the page keeps its "Runs on" sentence');
        $downloads = (string) json_encode(self::graph($this->request('GET', '/downloads/')->body));
        self::assertStringNotContainsString('hosts the meeting', $downloads);
    }

    /** @return list<array<string, mixed>> the page's JSON-LD @graph */
    private static function graph(string $body): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m));
        $ld = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        return $ld['@graph'];
    }
}
