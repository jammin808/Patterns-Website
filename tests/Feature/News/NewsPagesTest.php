<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\Controllers\NewsController;
use Psc\Controllers\SeoController;
use Psc\Db\Database;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\News\Paths;
use Psc\News\Settings;
use Psc\Tests\TestCase;

/**
 * Industry Updates' public pages (docs/NEWS.md §10): the lists and their indexing rules, the category hubs, the
 * article in its order with its head and structured data, the images, the feed, the tracked links and their
 * counting, the site's sitemap, robots, llms.txt and /health. Headers, rows and the published markup first, words
 * second. The silo's addresses, redirects and the section's own sitemaps are NewsSiloTest's.
 */
final class NewsPagesTest extends TestCase
{
    private const API = ['X-Requested-With' => 'fetch', 'Sec-Fetch-Site' => 'same-origin'];
    private const BASE = 'https://patternsshowcontrol.test';

    private function body(string $uri): string
    {
        $r = $this->request('GET', $uri);
        self::assertSame(200, $r->status, $uri);
        return $r->body;
    }

    /** @return list<array<string, mixed>> */
    private static function graph(string $body): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m));
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    /** @return array<string, mixed> the graph's first node of the type */
    private static function node(string $body, string $type): array
    {
        $nodes = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === $type));
        self::assertNotSame([], $nodes, $type);
        return $nodes[0];
    }

    /** @return list<string> the slugs the page's cards link to, in order */
    private static function cards(string $body): array
    {
        preg_match_all('#<article class="c-card news-card[^"]*">\s*<h3 class="c-card-title"><a href="/news/[a-z]+/([a-z0-9-]+)/">#', $body, $m);
        return $m[1];
    }

    /** @return list<int> the levels of the page's h1 to h4, in order */
    private static function headings(string $body): array
    {
        preg_match_all('#<h([1-4])[\s>]#', $body, $m);
        return array_map('intval', $m[1]);
    }

    private static function assertNoHeadingJumps(string $body, string $what): void
    {
        $levels = self::headings($body);
        self::assertSame(1, count(array_keys($levels, 1, true)), $what . ': one h1');
        for ($i = 1, $n = count($levels); $i < $n; $i++) {
            self::assertLessThanOrEqual($levels[$i - 1] + 1, $levels[$i], $what . ': no heading skips a level');
        }
    }

    private static function meta(string $body, string $attr, string $name): ?string
    {
        return preg_match('#<meta ' . $attr . '="' . preg_quote($name, '#') . '" content="([^"]*)">#', $body, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    /** A page with the comments' render time blanked: the second it was drawn is all two views may differ by. */
    private static function withoutStamps(string $html): string
    {
        return (string) preg_replace('/data-rendered-at="\d+"/', 'data-rendered-at=""', $html);
    }

    /** An image row for the article, by the stored naming scheme; returns its base. */
    private function image(int $articleId, int $position, string $alt, array $over = []): string
    {
        $base = bin2hex(random_bytes(16));
        $this->db->insert('news_images', $over + [
            'article_id' => $articleId, 'position' => $position, 'base' => $base, 'widths' => '480,960,1600', 'width' => 1600, 'height' => 900,
            'alt' => $alt, 'caption' => null, 'credit' => null, 'credit_url' => null, 'original_name' => 'photo.jpg', 'bytes' => 1000, 'created_by' => null, 'created_at' => time(),
        ]);
        return $base;
    }

    /** @return array<string, mixed> the article's first source link */
    private function sourceLink(int $articleId): array
    {
        $link = $this->db->one('SELECT * FROM news_links WHERE article_id = ? AND is_source = 1 ORDER BY position, id', [$articleId]);
        self::assertNotNull($link);
        return $link;
    }

    private function clicks(int $linkId): int
    {
        return (int) $this->db->value('SELECT clicks FROM news_links WHERE id = ?', [$linkId]);
    }

    // ------------------------------------------------------------------------------------------------- the lists

    public function testTheIndexDrawsTheNewestWideThenAGridAndPagesTheRest(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->makeArticle(['slug' => 'story-' . $i, 'published_at' => time() - 3600 * $i]);
        }
        $r = $this->request('GET', '/news/');
        self::assertSame(200, $r->status);
        self::assertSame(NewsController::CACHE, $r->getHeader('Cache-Control'));
        self::assertSame(array_map(static fn (int $i): string => 'story-' . $i, range(1, 12)), self::cards($r->body), 'twelve a page, the newest first');
        self::assertSame(1, substr_count($r->body, 'class="c-card news-card news-card--lead"'));
        self::assertMatchesRegularExpression('#<article class="c-card news-card news-card--lead">\s*<h3 class="c-card-title"><a href="/news/video/story-1/">#', $r->body, 'the newest is the wide one');
        self::assertStringContainsString('<a rel="next" href="/news/page/2/">', $r->body, 'the next page has a path of its own');
        self::assertStringNotContainsString('rel="prev"', $r->body);
        self::assertStringContainsString('<span class="c-pager-info">1 to 12 of 14</span>', $r->body);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/">', $r->body);
        self::assertSame('index, follow, max-image-preview:large, max-snippet:-1', self::meta($r->body, 'name', 'robots'));
        $list = self::node($r->body, 'ItemList');
        self::assertCount(12, $list['itemListElement']);
        self::assertSame(self::BASE . '/news/video/story-1/', $list['itemListElement'][0]['url'], 'each article at its own address');
        self::assertSame(['@id' => self::BASE . '/news/#list'], self::node($r->body, 'CollectionPage')['mainEntity']);
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Industry Updates RSS feed" href="/news/feed.xml">', $r->body);

        // Page 2 is a page of its own: indexed, naming itself as canonical, with words of its own.
        $two = $this->request('GET', '/news/page/2/');
        self::assertSame(200, $two->status);
        self::assertSame(['story-13', 'story-14'], self::cards($two->body));
        self::assertStringNotContainsString('news-card--lead', $two->body, 'only a first page has the wide card');
        self::assertSame('index, follow, max-image-preview:large, max-snippet:-1', self::meta($two->body, 'name', 'robots'));
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/page/2/">', $two->body);
        self::assertSame(self::BASE . '/news/page/2/#list', self::node($two->body, 'ItemList')['@id']);
        self::assertSame(['Home', 'Industry Updates', 'Page 2'], array_column(self::node($two->body, 'BreadcrumbList')['itemListElement'], 'name'));
        self::assertStringContainsString('<a rel="prev" href="/news/">', $two->body, 'page 1 is the clean address');
        self::assertStringContainsString('<title>Industry Updates: AV and live event news, page 2</title>', $two->body);
        self::assertStringStartsWith('Page 2: ', (string) self::meta($two->body, 'name', 'description'));
        self::assertNotSame(self::meta($r->body, 'name', 'description'), self::meta($two->body, 'name', 'description'));
        self::assertSame(NewsController::CACHE, $two->getHeader('Cache-Control'));

        self::assertSame(404, $this->request('GET', '/news/page/3/')->status, 'past the end');
        foreach (['/news/?page=0', '/news/?page=abc', '/news/?page=02', '/news/?page=-1', '/news/page/0/', '/news/page/02/', '/news/page/x/'] as $uri) {
            $bad = $this->request('GET', $uri);
            self::assertSame(404, $bad->status, $uri);
            self::assertNull($bad->getHeader('Location'), $uri);
        }
        foreach (['/news/?page=1' => '/news/', '/news/page/1/' => '/news/', '/news/?page=2' => '/news/page/2/',
            '/news/?page=2&utm_campaign=x' => '/news/page/2/?utm_campaign=x', '/news/?utm_source=a&page=1&utm_medium=b' => '/news/?utm_source=a&utm_medium=b',
            '/news/page/2/?q=&utm_source=a' => '/news/page/2/?utm_source=a', '/news/?q=+&ref=feed' => '/news/?ref=feed'] as $uri => $to) {
            $moved = $this->request('GET', $uri);
            self::assertSame([301, $to], [$moved->status, $moved->getHeader('Location')], $uri . ': with the rest of its query');
        }
        // A page past the end is a 404 wherever it is asked for, never a move to one.
        foreach (['/news/?page=3', '/news/?page=3&utm_source=a', '/news/page/3/?q=', '/news/page/9/'] as $uri) {
            $gone = $this->request('GET', $uri);
            self::assertSame([404, null], [$gone->status, $gone->getHeader('Location')], $uri);
        }
    }

    public function testCategoryPagesListTheirOwnLiveArticles(): void
    {
        $this->makeArticle(['slug' => 'desks-and-mics', 'categories' => ['audio', 'video'], 'published_at' => time() - 120]);
        $this->makeArticle(['slug' => 'radio-mics', 'categories' => ['audio']]);
        $this->makeArticle(['slug' => 'secret-fixture', 'categories' => ['lighting'], 'status' => 'draft']);
        $this->makeArticle(['slug' => 'tomorrows-law', 'categories' => ['legal'], 'published_at' => time() + 86400]);

        $r = $this->request('GET', '/news/audio/');
        self::assertSame(200, $r->status);
        self::assertSame(NewsController::CACHE, $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies());
        $audio = Categories::get('audio');
        self::assertStringContainsString('<title>' . e($audio['title']) . '</title>', $r->body);
        self::assertSame($audio['description'], self::meta($r->body, 'name', 'description'));
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">Audio</h1>', $r->body);
        self::assertStringContainsString(e($audio['intro']), $r->body);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/audio/">', $r->body);
        self::assertSame(['radio-mics', 'desks-and-mics'], self::cards($r->body));
        $crumbs = self::node($r->body, 'BreadcrumbList')['itemListElement'];
        self::assertSame(['Home', 'Industry Updates', 'Audio'], array_column($crumbs, 'name'));
        self::assertSame(self::BASE . '/news/audio/', $crumbs[2]['item']);
        self::assertMatchesRegularExpression('#<main id="main"[^>]*>\s*<section class="c-hero[^"]*"[^>]*>\s*<div class="l-wrap[^"]*"><nav class="c-crumbs" aria-label="Breadcrumb">.*?</nav>\s*<div class="c-hero-grid#s', $r->body);

        // The bar lists All and the categories with live articles, with their counts; the draft's and the
        // scheduled article's categories are not there.
        self::assertSame(1, preg_match('#<ul class="news-cats" role="list">(.*?)</ul>#s', $r->body, $bar));
        preg_match_all('#<a href="(/news/(?:[a-z]+/)?)"([^>]*)>([^<]+) <span class="news-cats-count"><span class="u-visually-hidden">, </span>(\d+)#', $bar[1], $chips, PREG_SET_ORDER);
        self::assertSame([['/news/', 'All', '2'], ['/news/video/', 'Video', '1'], ['/news/audio/', 'Audio', '2']], array_map(static fn (array $c): array => [$c[1], $c[3], $c[4]], $chips));
        self::assertSame(' aria-current="page"', $chips[2][2]);
        self::assertSame('', $chips[0][2]);
        self::assertStringContainsString('<option value="audio" selected>Audio (2)</option>', $r->body, 'the phone form names the same choice');

        $empty = $this->request('GET', '/news/lighting/');
        self::assertSame(200, $empty->status, 'a known category with nothing live');
        self::assertStringContainsString('Nothing in this category yet.', $empty->body);
        self::assertStringNotContainsString('secret-fixture', $empty->body);
        self::assertSame('noindex, follow', self::meta($empty->body, 'name', 'robots'));
        self::assertStringContainsString('<a class="c-btn c-btn--secondary" href="/news/">See every article</a>', $empty->body);
        self::assertSame(404, $this->request('GET', '/news/legal/page/2/')->status);
        self::assertSame(404, $this->request('GET', '/news/rumours/')->status);

        $one = $this->request('GET', '/news/audio/?page=1');
        self::assertSame([301, '/news/audio/'], [$one->status, $one->getHeader('Location')]);
    }

    public function testThePhonesCategoryFormSendsEachCategoryToItsOwnAddress(): void
    {
        $go = $this->request('GET', '/news/?category=streaming');
        self::assertSame([302, '/news/streaming/'], [$go->status, $go->getHeader('Location')]);
        $all = $this->request('GET', '/news/?category=');
        self::assertSame([302, '/news/'], [$all->status, $all->getHeader('Location')]);
        self::assertSame(404, $this->request('GET', '/news/?category=rumours')->status);
    }

    public function testASearchIsPrivateNoindexAndEscaped(): void
    {
        $this->makeArticle(['slug' => 'radio-mic-licences', 'title' => 'Radio mic licences change for the spring']);
        $this->makeArticle(['slug' => 'led-firmware', 'title' => 'LED processors get a firmware update']);
        $r = $this->request('GET', '/news/?q=radio');
        self::assertSame(200, $r->status);
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies());
        self::assertSame('noindex, follow', self::meta($r->body, 'name', 'robots'));
        self::assertSame(['radio-mic-licences'], self::cards($r->body));
        self::assertStringContainsString('<h2 class="news-list-title" id="news-list-title">Results for “radio”</h2>', $r->body);
        self::assertStringContainsString('<p class="news-count">One article, the newest first.</p>', $r->body);
        self::assertStringContainsString('<title>Search: “radio” | Industry Updates</title>', $r->body);
        self::assertStringContainsString('name="q" value="radio"', $r->body);
        $description = (string) self::meta($r->body, 'name', 'description');
        self::assertGreaterThanOrEqual(50, mb_strlen($description));

        $evil = $this->request('GET', '/news/?q=' . rawurlencode('<script>alert(1)</script>'));
        self::assertSame(200, $evil->status);
        self::assertStringNotContainsString('<script>alert(1)', $evil->body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $evil->body);
        self::assertStringContainsString('No articles match that search.', $evil->body);
        self::assertLessThanOrEqual(65, mb_strlen(html_entity_decode((string) preg_replace('#.*<title>(.*?)</title>.*#s', '$1', $evil->body), ENT_QUOTES)));

        foreach (['/news/?q=', '/news/?q=%20%20'] as $uri) {
            $blank = $this->request('GET', $uri);
            self::assertSame([301, '/news/'], [$blank->status, $blank->getHeader('Location')], $uri);
        }
        $long = $this->body('/news/?q=' . str_repeat('a', 150));
        self::assertStringContainsString('name="q" value="' . str_repeat('a', 100) . '"', $long, 'a search is kept to 100 characters');
    }

    public function testASearchPagesWithItsWordsKept(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->makeArticle(['title' => 'Wireless story number ' . $i . ' for the pager', 'published_at' => time() - 60 * $i]);
        }
        $body = $this->body('/news/?q=wireless');
        self::assertCount(12, self::cards($body));
        self::assertStringNotContainsString('news-card--lead', $body, 'a search lists its results evenly');
        self::assertStringContainsString('<a rel="next" href="/news/?q=wireless&amp;page=2">', $body);
        $two = $this->body('/news/?q=wireless&page=2');
        self::assertCount(1, self::cards($two));
        self::assertStringContainsString('<a rel="prev" href="/news/?q=wireless">', $two);
        $redirect = $this->request('GET', '/news/?q=wireless&page=1');
        self::assertSame([301, '/news/?q=wireless'], [$redirect->status, $redirect->getHeader('Location')]);
        $byPath = $this->request('GET', '/news/page/2/?q=wireless');
        self::assertSame([301, '/news/?q=wireless&page=2'], [$byPath->status, $byPath->getHeader('Location')], 'a search pages by its query');
        foreach (['/news/page/2/?utm_source=a&q=wireless' => '/news/?q=wireless&page=2&utm_source=a', '/news/?q=wireless&page=1&utm_source=a' => '/news/?q=wireless&utm_source=a'] as $uri => $to) {
            $moved = $this->request('GET', $uri);
            self::assertSame([301, $to], [$moved->status, $moved->getHeader('Location')], $uri . ': the rest of its query kept');
        }
        $past = $this->request('GET', '/news/page/3/?q=wireless');
        self::assertSame([404, null], [$past->status, $past->getHeader('Location')], 'a search page past its end is a 404, never a move to one');
    }

    /**
     * A database that cannot be read makes each list a retryable 503 that is neither stored nor indexed, and the
     * sitemap and llms.txt, which still answer without the articles, are not stored either: no cache holds the copy
     * without them once the database is back.
     */
    public function testAListThatCannotBeReadSaysSoAndIsNotCached(): void
    {
        $this->makeArticle();
        $working = $this->db;
        $this->db = Database::connect('sqlite::memory:');
        try {
            foreach (['/news/', '/news/video/', '/news/page/2/', '/news/video/page/3/', '/news/?q=sample'] as $uri) {
                $r = $this->request('GET', $uri);
                self::assertSame(503, $r->status, $uri);
                self::assertSame((string) NewsController::RETRY_AFTER, $r->getHeader('Retry-After'), $uri);
                self::assertSame('private, no-store', $r->getHeader('Cache-Control'), $uri);
                self::assertSame('noindex', $r->getHeader('X-Robots-Tag'), $uri);
                self::assertSame('noindex, follow', self::meta($r->body, 'name', 'robots'), $uri . ': the page says so too');
                self::assertStringNotContainsString('rel="canonical"', $r->body, $uri);
                self::assertStringNotContainsString('application/ld+json', $r->body, $uri);
                self::assertSame([], $r->cookies(), $uri);
                self::assertStringContainsString('The articles cannot be shown just now.', $r->body, $uri);
            }
            $sitemap = $this->request('GET', '/sitemap.xml');
            self::assertSame(200, $sitemap->status, 'the sitemap still lists the pages');
            self::assertSame('private, no-store', $sitemap->getHeader('Cache-Control'), 'but is not stored without its articles');
            self::assertStringContainsString('<loc>' . self::BASE . '/news/</loc>' . "\n" . '    <lastmod>' . \Psc\Content\Content::page('/news/')['updated'] . '</lastmod>', $sitemap->body, 'the index keeps its own date');
            $llms = $this->request('GET', '/llms.txt');
            self::assertSame(200, $llms->status);
            self::assertSame('private, no-store', $llms->getHeader('Cache-Control'), 'llms.txt is not stored without its articles');
            self::assertStringContainsString('(' . self::BASE . '/news/): ', $llms->body);
            foreach (['/news/sitemap.xml', '/news/sitemap-news.xml', '/news/sitemap-' . date('Y-m') . '.xml'] as $uri) {
                $news = $this->request('GET', $uri);
                self::assertSame(503, $news->status, $uri . ': asked again later, not recorded empty');
                self::assertSame('private, no-store', $news->getHeader('Cache-Control'), $uri);
                self::assertSame((string) NewsController::RETRY_AFTER, $news->getHeader('Retry-After'), $uri);
            }
        } finally {
            $this->db = $working;
        }
        self::assertSame('public, max-age=3600', $this->request('GET', '/sitemap.xml')->getHeader('Cache-Control'), 'stored again once the articles are read');
        self::assertSame('public, max-age=3600', $this->request('GET', '/llms.txt')->getHeader('Cache-Control'));
        self::assertSame(200, $this->request('GET', '/news/')->status);
    }

    // --------------------------------------------------------------------------------------------- the article

    public function testAnArticleIsLaidOutInTheOrderTheDesignGives(): void
    {
        $posted = time() - 3600;
        $earlier = $this->makeArticle(['slug' => 'first-report', 'title' => 'The first report on the new pay rules', 'categories' => ['legal'], 'published_at' => time() - 7200]);
        $source = 'https://www.gov.example/guidance/pay';
        $id = $this->makeArticle([
            'origin' => 'import',
            'slug' => 'pay-rules-follow-up',
            'title' => 'Late payment rules now reach freelance crew, and the clock starts at the invoice',
            'seo_title' => 'Late payment rules now reach freelance crew',
            'summary' => 'The late payment rules now cover freelance crew. What changed, when it applies and what to put on your next invoice.',
            'categories' => ['legal', 'freelance'],
            'tags' => ['late payment', 'invoices'],
            'body' => "The rules changed this week.\n\n## What changed\n\nRead [the guidance](" . $source . ") and [a trade view](https://press.example/view). Still, this paragraph is long enough to publish the article in full.",
            'impact' => "## For freelancers\n\nPut the terms on the invoice.",
            'sources' => [
                ['url' => $source, 'title' => 'Paying freelancers on time', 'publisher' => 'GOV.EXAMPLE', 'kind' => 'primary'],
                ['url' => 'https://press.example/report', 'title' => 'Trade report', 'publisher' => 'Trade press', 'kind' => 'press'],
            ],
            'confirmed' => [['text' => 'The rules apply from 1 November.', 'url' => $source], ['text' => 'Invoices must state the **terms**.', 'url' => $source]],
            'speculation' => [['text' => 'Some agencies may pay sooner.', 'url' => 'https://press.example/report']],
            'verdict' => 'test',
            'verdict_note' => 'Try the new wording on your next invoice.',
            'follow_up_of' => ['first-report'],
            'published_at' => $posted,
        ]);
        $this->makeArticle(['slug' => 'pay-rules-update', 'title' => 'The pay rules: what happened next', 'categories' => ['legal'], 'follow_up_of' => ['pay-rules-follow-up']]);
        self::assertGreaterThan(0, $earlier);

        $r = $this->request('GET', '/news/legal/pay-rules-follow-up/');
        self::assertSame(200, $r->status);
        self::assertSame(NewsController::CACHE, $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies());
        $b = $r->body;

        $order = ['<nav class="c-crumbs"', '<ul class="news-tags"', '<h1 class="c-hero-title news-story-title"', 'news-story-dek', '<p class="news-meta">', 'news-followup',
            'news-lead-art', 'c-prose news-body', 'id="news-confirmed"', 'id="news-speculation"', 'id="news-impact"', 'news-verdict', 'id="news-sources"', 'id="news-later"', 'news-ainote',
            'id="news-more"', 'id="comments"'];
        $at = array_map(static fn (string $marker): int|false => strpos($b, $marker), $order);
        self::assertNotContains(false, $at, 'every part is there');
        $sorted = $at;
        sort($sorted);
        self::assertSame($sorted, $at, 'in the order of docs/NEWS.md §10');
        self::assertNoHeadingJumps($b, 'the article');
        self::assertMatchesRegularExpression('#<main id="main"[^>]*>\s*<section class="c-hero[^"]*"[^>]*>\s*<div class="l-wrap[^"]*"><nav class="c-crumbs" aria-label="Breadcrumb">.*?</nav>\s*<div class="c-hero-grid#s', $b);

        // The head: the title tag (the SEO title, too long to take the section's name), the share title, the article tags.
        self::assertStringContainsString('<title>Late payment rules now reach freelance crew</title>', $b);
        self::assertSame('Late payment rules now reach freelance crew, and the clock starts at the invoice', self::meta($b, 'property', 'og:title'));
        self::assertSame('article', self::meta($b, 'property', 'og:type'));
        self::assertSame(date(DATE_ATOM, $posted), self::meta($b, 'property', 'article:published_time'));
        self::assertNotNull(self::meta($b, 'property', 'article:modified_time'));
        self::assertSame('Legal', self::meta($b, 'property', 'article:section'));
        self::assertSame(2, substr_count($b, '<meta property="article:tag"'));
        self::assertSame(self::BASE . '/assets/img/og/news-legal.png', self::meta($b, 'property', 'og:image'), 'its category\'s card without a picture');
        self::assertSame('Legal news for live events and AV, from Industry Updates', self::meta($b, 'property', 'og:image:alt'));
        self::assertSame(['1200', '630'], [self::meta($b, 'property', 'og:image:width'), self::meta($b, 'property', 'og:image:height')]);
        self::assertSame('summary_large_image', self::meta($b, 'name', 'twitter:card'));
        self::assertSame(self::BASE . '/news/legal/pay-rules-follow-up/', self::meta($b, 'property', 'og:url'));
        self::assertSame('The late payment rules now cover freelance crew. What changed, when it applies and what to put on your next invoice.', self::meta($b, 'property', 'og:description'));
        self::assertSame('index, follow, max-image-preview:large, max-snippet:-1', self::meta($b, 'name', 'robots'));
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/legal/pay-rules-follow-up/">', $b);
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Industry Updates RSS feed" href="/news/feed.xml">', $b);
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Legal: Industry Updates RSS feed" href="/news/legal/feed.xml">', $b);
        self::assertStringContainsString('<script type="module" src="/assets/js/news.js?v=', $b);

        // The structured data: the NewsArticle on the base graph, every reference resolving.
        $graph = self::graph($b);
        $ids = array_values(array_filter(array_map(static fn (array $n): ?string => $n['@id'] ?? null, $graph)));
        self::assertSame(array_unique($ids), $ids);
        self::assertSame(['Organization', 'Person', 'WebSite', 'WebPage', 'NewsArticle', 'BreadcrumbList'], array_column($graph, '@type'));
        $article = self::node($b, 'NewsArticle');
        self::assertSame(self::BASE . '/news/legal/pay-rules-follow-up/#article', $article['@id']);
        self::assertSame('Late payment rules now reach freelance crew, and the clock starts at the invoice', $article['headline']);
        self::assertSame('The late payment rules now cover freelance crew. What changed, when it applies and what to put on your next invoice.', $article['description']);
        self::assertSame(date(DATE_ATOM, $posted), $article['datePublished']);
        self::assertSame('Legal', $article['articleSection']);
        self::assertSame('late payment, invoices', $article['keywords']);
        self::assertSame(['@id' => self::BASE . '/#organization'], $article['author'], 'an AI-written article is the organisation\'s');
        self::assertSame(['@id' => self::BASE . '/#organization'], $article['publisher']);
        self::assertSame([$source, 'https://press.example/report'], $article['isBasedOn']);
        self::assertSame(['@id' => self::BASE . '/news/legal/pay-rules-follow-up/#webpage'], $article['mainEntityOfPage']);
        self::assertContains($article['mainEntityOfPage']['@id'], $ids);
        self::assertSame('WebPage', self::node($b, 'WebPage')['@type']);
        self::assertSame([self::BASE . '/assets/img/og/news-legal.png'], $article['image'], 'a list of pictures');
        self::assertTrue($article['isAccessibleForFree']);
        self::assertSame('en-GB', $article['inLanguage']);
        self::assertSame((int) $this->db->value('SELECT word_count FROM news_articles WHERE id = ?', [$id]), $article['wordCount']);
        $crumbs = self::node($b, 'BreadcrumbList')['itemListElement'];
        self::assertSame(['Home', 'Industry Updates', 'Legal', 'Late payment rules now reach freelance crew'], array_column($crumbs, 'name'));
        self::assertSame([self::BASE . '/', self::BASE . '/news/', self::BASE . '/news/legal/', self::BASE . '/news/legal/pay-rules-follow-up/'], array_column($crumbs, 'item'));

        // The hero: the categories as tags linking to their hubs, the meta line with the desk's byline.
        self::assertStringContainsString('<li><a class="c-tag" href="/news/legal/">Legal</a></li>', $b);
        self::assertStringContainsString('<li><a class="c-tag" href="/news/freelance/">Freelance</a></li>', $b);
        self::assertStringContainsString('<time datetime="' . date(DATE_ATOM, $posted) . '">' . date('j F Y, H:i', $posted) . '</time>', $b, 'the London date and time');
        self::assertStringContainsString('min read</span>', $b);
        self::assertStringContainsString('<span>By Industry Updates desk</span>', $b);
        self::assertStringNotContainsString('>Updated <', $b, 'not changed since it went live');

        // Every source is a real link with its id for counting, in the body, the facts and the list.
        $links = (new ArticleStore($this->app))->links($id);
        $byUrl = array_column($links, 'id', 'url');
        $tagged = '<a href="' . $source . '" rel="nofollow noopener" target="_blank" data-news-link="' . $byUrl[$source] . '">';
        self::assertStringContainsString($tagged . 'the guidance</a>', $b, 'in the body');
        self::assertStringContainsString('<a href="https://press.example/view" rel="nofollow noopener" target="_blank" data-news-link="' . $byUrl['https://press.example/view'] . '">a trade view</a>', $b);
        self::assertStringContainsString($tagged . 'GOV.EXAMPLE<span class="u-visually-hidden"> (opens in a new tab)</span></a>', $b, 'beside a fact');
        self::assertStringContainsString($tagged . 'Paying freelancers on time<span class="u-visually-hidden"> (opens in a new tab)</span></a>', $b, 'in the sources');
        self::assertSame(1, preg_match('#<ol class="news-sources">(.*?)</ol>#s', $b, $list));
        self::assertSame(2, substr_count($list[1], '<li>'));
        self::assertStringContainsString('<span>GOV.EXAMPLE</span>', $list[1]);
        self::assertStringContainsString('<span>gov.example</span>', $list[1], 'the canonical host');
        self::assertStringContainsString('<span>Primary source</span>', $list[1]);
        self::assertStringContainsString('<span>Press</span>', $list[1]);

        // The facts with their badges, the impact's headings under its own, the verdict, the AI note.
        self::assertSame(2, substr_count($b, '<span class="c-badge c-badge--done">Confirmed</span>'));
        self::assertSame(1, substr_count($b, '<span class="c-badge c-badge--progress">Unconfirmed</span>'));
        self::assertStringContainsString('<p>Invoices must state the <strong>terms</strong>.</p>', $b);
        self::assertStringContainsString('<h2 id="news-impact">What it means for freelancers and corporate events</h2>', $b);
        self::assertStringContainsString('<h3>For freelancers</h3>', $b, 'one level under the section');
        self::assertStringContainsString('<h2>What changed</h2>', $b);
        self::assertMatchesRegularExpression('#<div class="c-callout c-callout--info news-verdict" role="note">\s*<svg[^>]*><use href="\#i-idea"/></svg>\s*<h2 class="c-callout-title"><span class="u-visually-hidden">Our verdict: </span>Worth testing</h2>\s*<p>Try the new wording on your next invoice.</p>#', $b);
        self::assertStringContainsString('Spotted something off? <a href="/contact/">Tell us</a> and we will put it right.', $b);

        // The follow-ups both ways, at their own addresses.
        self::assertMatchesRegularExpression('#news-followup.*?Follow-up to:</strong>\s*<ul>\s*<li><a href="/news/legal/first-report/">The first report on the new pay rules</a></li>#s', $b);
        self::assertMatchesRegularExpression('#<ul class="news-later" role="list">\s*<li><a href="/news/legal/pay-rules-update/">The pay rules: what happened next</a> <time#', $b);

        // It ends with the newest of its primary category, never itself, and the way to that category's hub.
        self::assertSame(1, preg_match('#<section class="news-part news-more" aria-labelledby="news-more">(.*?)</section>#s', $b, $more));
        self::assertStringContainsString('<h2 id="news-more">More in Legal</h2>', $more[1]);
        preg_match_all('#<li>\s*<a href="([^"]+)">#', $more[1], $moreLinks);
        self::assertSame(['/news/legal/pay-rules-update/', '/news/legal/first-report/'], $moreLinks[1]);
        self::assertStringContainsString('<a class="c-btn c-btn--secondary" href="/news/legal/">Every article in Legal</a>', $more[1]);

        // No style attribute and no inline script anywhere in the page.
        self::assertStringNotContainsString(' style="', $b);
        preg_match_all('#<script\b([^>]*)>#', $b, $scripts);
        foreach ($scripts[1] as $attrs) {
            self::assertMatchesRegularExpression('#src="|type="application/ld\+json"#', $attrs, 'scripts are files or structured data');
        }
    }

    public function testTheAuthorIsThePublisherOnlyForHisOwnWords(): void
    {
        $this->makeArticle(['slug' => 'ben-writes', 'byline' => 'Ben Rohleder']);
        $this->makeArticle(['slug' => 'guest-writes', 'byline' => 'A Guest Writer']);
        $this->makeArticle(['slug' => 'ben-with-ai-help', 'byline' => 'Ben Rohleder', 'ai_written' => true]);

        $ben = $this->body('/news/video/ben-writes/');
        self::assertSame(['@id' => self::BASE . '/about/#ben'], self::node($ben, 'NewsArticle')['author']);
        self::assertStringContainsString('<span>By Ben Rohleder</span>', $ben);
        self::assertStringNotContainsString('news-ainote', $ben, 'no AI note on his own words');
        self::assertStringNotContainsString('id="news-speculation"', $ben, 'speculation only if there is any');
        self::assertStringNotContainsString('news-followup', $ben);
        self::assertStringNotContainsString('id="news-later"', $ben);
        self::assertSame(['@id' => self::BASE . '/#organization'], self::node($this->body('/news/video/guest-writes/'), 'NewsArticle')['author']);
        $helped = $this->body('/news/video/ben-with-ai-help/');
        self::assertSame(['@id' => self::BASE . '/#organization'], self::node($helped, 'NewsArticle')['author'], 'written with AI help');
        self::assertStringContainsString('news-ainote', $helped);
    }

    public function testAnArticleChangedAfterItWentLiveSaysUpdated(): void
    {
        $id = $this->makeArticle(['slug' => 'changed-later', 'published_at' => time() - 7200]);
        $changed = time() - 60;
        $this->db->update('news_articles', ['created_at' => time() - 7200, 'updated_at' => $changed], ['id' => $id]);
        $body = $this->body('/news/video/changed-later/');
        self::assertStringContainsString('<span>Updated <time datetime="' . date(DATE_ATOM, $changed) . '">', $body);
        self::assertSame(date(DATE_ATOM, $changed), self::node($body, 'NewsArticle')['dateModified']);
        self::assertSame(date(DATE_ATOM, $changed), self::meta($body, 'property', 'article:modified_time'));

        // An article stored now with an earlier date (an import) has not changed since it went live.
        $yesterday = time() - 86400;
        $this->makeArticle(['slug' => 'dated-earlier', 'published_at' => $yesterday]);
        $dated = $this->body('/news/video/dated-earlier/');
        self::assertStringNotContainsString('>Updated <', $dated);
        self::assertSame(date(DATE_ATOM, $yesterday), self::node($dated, 'NewsArticle')['dateModified']);
    }

    public function testOnlyLiveArticlesHavePages(): void
    {
        $this->makeArticle(['slug' => 'still-a-draft', 'status' => 'draft']);
        $this->makeArticle(['slug' => 'not-yet', 'published_at' => time() + 3600]);
        $id = $this->makeArticle(['slug' => 'taken-down']);
        self::assertSame(200, $this->request('GET', '/news/video/taken-down/')->status);
        (new ArticleStore($this->app))->unpublish($id);
        foreach (['/news/video/still-a-draft/', '/news/video/not-yet/', '/news/video/taken-down/', '/news/taken-down/', '/news/video/no-such-story/', '/news/no-such-story/'] as $uri) {
            $r = $this->request('GET', $uri);
            self::assertSame(404, $r->status, $uri);
            self::assertNull($r->getHeader('Location'), $uri . ': no redirect tells anyone a draft is there');
            self::assertSame('private, no-store', $r->getHeader('Cache-Control'), $uri);
            self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'), $uri);
        }
    }

    public function testArticleImagesComeFromTheMediaRouteWithEveryWidth(): void
    {
        $id = $this->makeArticle(['slug' => 'pictures-included']);
        $lead = $this->image($id, 0, 'A wall of LED panels at a conference', ['caption' => 'The wall at load-in', 'credit' => 'Maker Ltd', 'credit_url' => 'https://maker.example/press']);
        $other = $this->image($id, 1, 'The processor rack', ['widths' => '480,960', 'width' => 900, 'height' => 600]);
        $b = $this->body('/news/video/pictures-included/');

        self::assertMatchesRegularExpression('#<figure class="news-figure">\s*<img src="/news/media/' . $lead . '-960\.webp" srcset="([^"]+)" sizes="[^"]+" width="1600" height="900" alt="A wall of LED panels at a conference" fetchpriority="high" decoding="async">#', $b);
        preg_match('#srcset="([^"]*' . $lead . '[^"]*)"#', $b, $set);
        foreach ([480, 960, 1600] as $w) {
            self::assertStringContainsString('/news/media/' . $lead . '-' . $w . '.webp', $set[1]);
        }
        self::assertStringContainsString('<span>The wall at load-in</span>', $b);
        self::assertStringContainsString('<span class="news-figure-credit">Image: <a href="https://maker.example/press" rel="nofollow noopener" target="_blank">Maker Ltd<span class="u-visually-hidden"> (opens in a new tab)</span></a></span>', $b);
        self::assertStringNotContainsString('news-lead-art', $b, 'a picture stands in for the artwork');
        self::assertMatchesRegularExpression('#<div class="news-gallery">\s*<figure class="news-figure">\s*<img src="/news/media/' . $other . '-960\.webp" srcset="[^"]*' . $other . '-480\.webp 480w[^"]*' . $other . '-960\.webp 900w" sizes="[^"]+" width="900" height="600" alt="The processor rack" loading="lazy"#', $b, 'the widest copy is described by its own width');
        self::assertLessThan(strpos($b, 'id="news-sources"'), strpos($b, 'news-gallery'), 'the other images come before the sources');
        self::assertSame(self::BASE . '/news/media/' . $lead . '-og.jpg', self::meta($b, 'property', 'og:image'));
        self::assertSame('A wall of LED panels at a conference', self::meta($b, 'property', 'og:image:alt'));
        self::assertSame([self::BASE . '/news/media/' . $lead . '-og.jpg', self::BASE . '/news/media/' . $lead . '-1600.webp'], self::node($b, 'NewsArticle')['image'], 'the share card, then the picture at its widest');

        $card = $this->body('/news/');
        self::assertMatchesRegularExpression('#<div class="news-card-media">\s*<img src="/news/media/' . $lead . '-960\.webp" srcset="[^"]+" sizes="[^"]+" width="1600" height="900" alt="" decoding="async">#', $card, 'the card\'s picture is decoration: its title says it');

        $this->makeArticle(['slug' => 'no-pictures', 'categories' => ['streaming']]);
        $plain = $this->body('/news/streaming/no-pictures/');
        self::assertMatchesRegularExpression('#<div class="news-lead-art"><span class="news-art news-art--wide"><svg class="news-art-svg" viewBox="0 0 160 90" aria-hidden="true" focusable="false"><use href="/assets/img/news-art\.svg\?v=[a-f0-9]+\#art-streaming"/></svg></span>#', $plain);
        self::assertStringContainsString('#art-streaming"/>', $this->body('/news/'), 'the card has the artwork too');
    }

    public function testThePreviewShowsAnyArticleUnpublishedAndUncounted(): void
    {
        $store = new ArticleStore($this->app);
        $draft = $this->makeArticle(['slug' => 'a-draft-preview', 'status' => 'draft']);
        $base = $this->image($draft, 0, 'A preview picture');
        $controller = new NewsController($this->app);
        $r = $controller->renderArticle((array) $store->find($draft), true);
        self::assertInstanceOf(Response::class, $r);
        self::assertSame(200, $r->status);
        self::assertFalse($r->hasHeader('Cache-Control'), 'the admin route makes it private');
        self::assertStringContainsString('<strong class="c-callout-title">Preview: not published</strong>', $r->body);
        self::assertStringContainsString('A draft: only administrators can see it.', $r->body);
        self::assertSame('noindex, nofollow', self::meta($r->body, 'name', 'robots'));
        self::assertStringContainsString('src="/portal/admin/news/media/' . $base . '-960.webp"', $r->body);
        self::assertStringNotContainsString('/news/media/' . $base, str_replace('/portal/admin/news/media/', '', $r->body));
        self::assertStringNotContainsString('/assets/js/news.js', $r->body, 'no counting');
        self::assertStringContainsString('Comments appear here once the article is live.', $r->body);
        self::assertStringContainsString('<span>Not published yet</span>', $r->body);

        $soon = time() + 7200;
        $scheduled = $this->makeArticle(['slug' => 'a-scheduled-preview', 'published_at' => $soon]);
        $later = $controller->renderArticle((array) $store->find($scheduled), true);
        self::assertStringContainsString('Scheduled to go live <time datetime="' . date(DATE_ATOM, $soon) . '">', $later->body);

        $live = $this->makeArticle(['slug' => 'a-live-preview']);
        self::assertStringContainsString('Preview: this article is live', $controller->renderArticle((array) $store->find($live), true)->body);
    }

    // ------------------------------------------------------------------------------------------ feed and links

    public function testTheFeedListsTheNewestLiveArticlesWithTrackedSources(): void
    {
        $now = time();
        for ($i = 1; $i <= 31; $i++) {
            $this->makeArticle(['slug' => 'feed-story-' . $i, 'categories' => ['video', 'tips'], 'published_at' => $now - 60 * $i]);
        }
        $this->makeArticle(['slug' => 'feed-draft', 'status' => 'draft']);
        $r = $this->request('GET', '/news/feed.xml');
        self::assertSame(200, $r->status);
        self::assertSame('application/rss+xml; charset=utf-8', $r->getHeader('Content-Type'));
        self::assertSame(NewsController::CACHE, $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies());
        $xml = simplexml_load_string($r->body);
        self::assertNotFalse($xml);
        self::assertSame('2.0', (string) $xml['version']);
        self::assertSame('Industry Updates from Patterns Show Control', (string) $xml->channel->title);
        self::assertSame(self::BASE . '/news/', (string) $xml->channel->link);
        self::assertSame('en-gb', (string) $xml->channel->language);
        self::assertStringContainsString('<atom:link href="' . self::BASE . '/news/feed.xml" rel="self" type="application/rss+xml"/>', $r->body);
        self::assertCount(NewsController::FEED_ITEMS, $xml->channel->item);
        $first = $xml->channel->item[0];
        self::assertSame(self::BASE . '/news/video/feed-story-1/', (string) $first->link, 'the article\'s own address');
        self::assertSame(self::BASE . '/news/feed-story-1/', (string) $first->guid, 'its lasting address, whatever its category');
        self::assertSame('true', (string) $first->guid['isPermaLink']);
        self::assertSame(301, $this->request('GET', '/news/feed-story-1/')->status, 'which leads to the article');
        self::assertSame(date(DATE_RSS, $now - 60), (string) $first->pubDate);
        self::assertSame(['Video', 'Tips'], array_map('strval', iterator_to_array($first->category, false)));
        $link = $this->sourceLink((int) $this->db->value("SELECT id FROM news_articles WHERE slug = 'feed-story-1'"));
        self::assertStringContainsString('<a href="' . self::BASE . '/news/go/' . $link['id'] . '/">Sample source', (string) $first->description, 'sources through the tracked link');
        self::assertStringNotContainsString('feed-story-31', $r->body, 'thirty, the newest');
        self::assertStringNotContainsString('feed-draft', $r->body);
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml"', $this->body('/news/video/feed-story-2/'));
    }

    public function testGoSendsToTheStoredAddressAndCountsByTheRules(): void
    {
        $this->bootApp(['app' => ['trusted_proxy_header' => 'HTTP_X_TEST_CLIENT', 'trusted_proxies' => ['203.0.113.7']]]);
        $id = $this->makeArticle(['slug' => 'with-a-source']);
        $link = $this->sourceLink($id);
        $go = '/news/go/' . $link['id'] . '/';
        $from = fn (string $ip, string $method = 'GET', string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130'): Response
            => $this->request($method, $go, [], ['user-agent' => $agent], null, true, [], ['HTTP_X_TEST_CLIENT' => $ip]);

        $r = $from('198.51.100.1');
        self::assertSame(302, $r->status);
        self::assertSame((string) $link['url'], $r->getHeader('Location'));
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'));
        self::assertSame('noindex, nofollow', $r->getHeader('X-Robots-Tag'));
        self::assertSame([], $r->cookies());
        self::assertSame(1, $this->clicks((int) $link['id']));
        self::assertSame([['day' => gmdate('Y-m-d'), 'clicks' => 1]], array_map(static fn (array $row): array => ['day' => (string) $row['day'], 'clicks' => (int) $row['clicks']], $this->db->all('SELECT day, clicks FROM news_link_clicks WHERE link_id = ?', [(int) $link['id']])));

        self::assertSame(302, $from('198.51.100.1')->status, 'a repeat still goes');
        self::assertSame(1, $this->clicks((int) $link['id']), 'but is not counted within ten minutes');
        $from('198.51.100.2');
        self::assertSame(2, $this->clicks((int) $link['id']), 'another address counts');
        self::assertSame(2, (int) $this->db->value('SELECT clicks FROM news_link_clicks WHERE link_id = ?', [(int) $link['id']]));
        foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4', 'Mozilla/5.0 HeadlessChrome/130', 'python-requests/2.31', 'Slackbot-LinkExpanding 1.0', 'facebookexternalhit/1.1 Preview', ''] as $n => $agent) {
            self::assertSame(302, $from('198.51.100.' . (10 + $n), 'GET', $agent)->status, $agent);
        }
        self::assertSame(2, $this->clicks((int) $link['id']), 'robots are not counted');
        self::assertSame(302, $from('198.51.100.30', 'HEAD')->status);
        self::assertSame(2, $this->clicks((int) $link['id']), 'nor is a HEAD');

        self::assertSame(404, $this->request('GET', '/news/go/999999/')->status);
        $draft = $this->makeArticle(['status' => 'draft']);
        self::assertSame(404, $this->request('GET', '/news/go/' . $this->sourceLink($draft)['id'] . '/')->status, 'a link of an article that is not live');
        (new ArticleStore($this->app))->unpublish($id);
        $gone = $from('198.51.100.40');
        self::assertSame(404, $gone->status, 'taken down');
        self::assertSame(2, $this->clicks((int) $link['id']));
    }

    /**
     * Only a visit counts: a browser's document navigation, from any site (a feed reader's click comes from
     * another), or a request without the fetch headers. An image, a script, a frame or a fetch naming the address on
     * some other page is sent on and never counted.
     */
    public function testGoCountsOnlyAVisitAndAlwaysSendsOn(): void
    {
        $this->bootApp(['app' => ['trusted_proxy_header' => 'HTTP_X_TEST_CLIENT', 'trusted_proxies' => ['203.0.113.7']]]);
        $link = $this->sourceLink($this->makeArticle(['slug' => 'with-a-source']));
        $go = '/news/go/' . $link['id'] . '/';
        $n = 0;
        $from = function (array $headers) use ($go, &$n): Response {
            return $this->request('GET', $go, [], $headers + ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130', 'referer' => 'https://elsewhere.example/page'], null, true, [], ['HTTP_X_TEST_CLIENT' => '198.51.100.' . (++$n)]);
        };
        foreach ([
            'an image' => ['sec-fetch-dest' => 'image', 'sec-fetch-mode' => 'no-cors', 'sec-fetch-site' => 'cross-site'],
            'a script' => ['sec-fetch-dest' => 'script', 'sec-fetch-mode' => 'no-cors', 'sec-fetch-site' => 'cross-site'],
            'a frame' => ['sec-fetch-dest' => 'iframe', 'sec-fetch-mode' => 'navigate', 'sec-fetch-site' => 'cross-site'],
            'a fetch' => ['sec-fetch-dest' => 'empty', 'sec-fetch-mode' => 'cors', 'sec-fetch-site' => 'cross-site'],
            'a document fetched, not visited' => ['sec-fetch-dest' => 'document', 'sec-fetch-mode' => 'no-cors'],
        ] as $what => $headers) {
            $r = $from($headers);
            self::assertSame([302, (string) $link['url']], [$r->status, $r->getHeader('Location')], $what . ' is still sent on');
            self::assertSame(0, $this->clicks((int) $link['id']), $what . ' is not counted');
        }
        self::assertSame(302, $from(['sec-fetch-dest' => 'document', 'sec-fetch-mode' => 'navigate', 'sec-fetch-site' => 'cross-site', 'sec-fetch-user' => '?1'])->status);
        self::assertSame(1, $this->clicks((int) $link['id']), 'a click from another site (a feed reader) counts');
        self::assertSame(302, $from(['Sec-Fetch-Dest' => 'Document', 'Sec-Fetch-Mode' => 'Navigate'])->status);
        self::assertSame(2, $this->clicks((int) $link['id']), 'the headers in any case');
        self::assertSame(302, $from([])->status);
        self::assertSame(3, $this->clicks((int) $link['id']), 'a browser without the fetch headers counts');
    }

    public function testTheClickEndpointCountsSameOriginFetchesOnly(): void
    {
        $id = $this->makeArticle(['slug' => 'clicked']);
        $link = (int) $this->sourceLink($id)['id'];
        $uri = '/api/news/links/' . $link . '/click';
        $agent = ['user-agent' => 'Mozilla/5.0 (Macintosh) Safari/605'];

        $r = $this->request('POST', $uri, [], self::API + $agent);
        self::assertSame(200, $r->status);
        self::assertSame(['counted' => true], json_decode($r->body, true));
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies(), 'no session');
        self::assertSame(1, $this->clicks($link));
        self::assertSame(['counted' => false], json_decode($this->request('POST', $uri, [], self::API + $agent)->body, true), 'once per address in ten minutes');
        self::assertSame(1, $this->clicks($link));

        self::assertSame(403, $this->request('POST', $uri, [], ['Sec-Fetch-Site' => 'same-origin'] + $agent)->status, 'without the fetch header');
        self::assertSame(403, $this->request('POST', $uri, [], ['X-Requested-With' => 'fetch', 'Sec-Fetch-Site' => 'cross-site'] + $agent)->status, 'from another site');
        self::assertSame(405, $this->request('GET', $uri, [], self::API)->status);
        self::assertSame(404, $this->request('POST', '/api/news/links/999999/click', [], self::API + $agent)->status);
        $draft = (int) $this->sourceLink($this->makeArticle(['status' => 'draft']))['id'];
        self::assertSame(404, $this->request('POST', '/api/news/links/' . $draft . '/click', [], self::API + $agent)->status);
        self::assertSame(0, $this->clicks($draft));

        $bot = $this->makeArticle();
        $botLink = (int) $this->sourceLink($bot)['id'];
        self::assertSame(['counted' => false], json_decode($this->request('POST', '/api/news/links/' . $botLink . '/click', [], self::API + ['user-agent' => 'Bingbot/2.0'])->body, true));
        self::assertSame(0, $this->clicks($botLink));
    }

    /** No news page starts a session or sets a cookie, even for a signed-in administrator, and none differs for one. */
    public function testNoNewsPageSetsACookieEvenForASignedInAdministrator(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->makeArticle(['slug' => 'cookie-story-' . $i, 'published_at' => time() - 60 * $i]);
        }
        $link = $this->sourceLink((int) $this->db->value("SELECT id FROM news_articles WHERE slug = 'cookie-story-1'"));
        $paths = ['/news/', '/news/page/2/', '/news/?q=story', '/news/video/', '/news/video/cookie-story-1/', '/news/cookie-story-1/', '/news/feed.xml', '/news/video/feed.xml', '/news/go/' . $link['id'] . '/',
            '/news/?category=video', '/news/category/video/', '/news/sitemap.xml', '/news/sitemap-news.xml', '/news/sitemap-' . date('Y-m', time() - 60) . '.xml'];
        $anonymous = [];
        foreach ($paths as $path) {
            $anonymous[$path] = $this->request('GET', $path)->body;
        }
        $this->signInAdmin();
        self::assertNotSame([], $this->jar, 'the browser holds the session');
        foreach ($paths as $path) {
            $r = $this->request('GET', $path);
            self::assertContains($r->status, [200, 301, 302], $path);
            self::assertSame([], $r->cookies(), $path . ' sets no cookie');
            self::assertSame(self::withoutStamps($anonymous[$path]), self::withoutStamps($r->body), $path . ' is the same for everyone');
        }
    }

    // ------------------------------------------------------------------------------- sitemap, robots, llms, health

    /**
     * The site's sitemap lists the hub and each category hub with a live article, each with its newest article's
     * time, and never an article (the section's own sitemaps list those), so it stays small however many there are.
     */
    public function testTheSitemapListsTheHubsWithLiveArticlesAndNoArticle(): void
    {
        $first = $this->makeArticle(['slug' => 'in-the-map', 'categories' => ['video'], 'published_at' => time() - 7200]);
        $newest = time() - 300;
        $this->db->update('news_articles', ['created_at' => time() - 7200, 'updated_at' => $newest], ['id' => $first]);
        $later = $this->makeArticle(['slug' => 'also-in-the-map', 'categories' => ['audio', 'freelance'], 'published_at' => time() - 3600]);
        $this->db->update('news_articles', ['created_at' => time() - 3600, 'updated_at' => time() - 3500], ['id' => $later]);
        $this->makeArticle(['slug' => 'a-draft-not-in-the-map', 'categories' => ['lighting'], 'status' => 'draft']);
        $this->makeArticle(['slug' => 'scheduled-not-in-the-map', 'categories' => ['legal'], 'published_at' => time() + 600]);

        $xml = simplexml_load_string($this->body('/sitemap.xml'));
        self::assertNotFalse($xml);
        self::assertSame(['' => 'http://www.sitemaps.org/schemas/sitemap/0.9'], $xml->getDocNamespaces());
        $map = [];
        foreach ($xml->url as $u) {
            $map[(string) $u->loc] = (string) $u->lastmod;
        }
        foreach (['/news/', '/news/video/', '/news/audio/', '/news/freelance/'] as $path) {
            self::assertArrayHasKey(self::BASE . $path, $map, $path);
        }
        foreach (['/news/lighting/', '/news/legal/', '/news/tips/', '/news/category/video/'] as $path) {
            self::assertArrayNotHasKey(self::BASE . $path, $map, $path);
        }
        foreach (array_keys($map) as $loc) {
            self::assertNull(Paths::parse((string) parse_url($loc, PHP_URL_PATH)), $loc . ': no article in the site\'s sitemap');
        }
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $newest), $map[self::BASE . '/news/video/'], 'its article was updated after it went live');
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', time() - 3600), $map[self::BASE . '/news/freelance/'], 'a change within minutes of going live is part of it');
        $since = (int) strtotime(\Psc\Content\Content::page('/news/')['updated'] . ' 00:00:00 UTC');
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', max($newest, $since)), $map[self::BASE . '/news/'], 'the hub changes with its newest article');
        $r = $this->request('GET', '/sitemap.xml');
        self::assertSame([SeoController::SITEMAP_CACHE, 'application/xml; charset=utf-8'], [$r->getHeader('Cache-Control'), $r->getHeader('Content-Type')]);
    }

    public function testRobotsNamesBothSitemapsAndKeepsCrawlersOffTheTrackedLinks(): void
    {
        $robots = $this->request('GET', '/robots.txt');
        self::assertSame('public, max-age=3600', $robots->getHeader('Cache-Control'));
        self::assertStringContainsString("Disallow: /api/\nDisallow: /news/go/\n", $robots->body);
        preg_match_all('/^Sitemap: (.+)$/m', $robots->body, $m);
        self::assertSame([self::BASE . '/sitemap.xml', self::BASE . '/news/sitemap.xml'], $m[1]);
    }

    public function testLlmsTxtListsTheTenNewestArticles(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->makeArticle(['slug' => 'llms-story-' . $i, 'title' => 'Story ' . $i . ' for the answer engines', 'published_at' => time() - 60 * $i]);
        }
        $this->makeArticle(['slug' => 'llms-draft', 'status' => 'draft']);
        $llms = $this->body('/llms.txt');
        self::assertSame(1, preg_match('/^## Industry Updates\n\n(.*?)(?:\n\n|\z)/ms', $llms, $m));
        $lines = explode("\n", trim($m[1]));
        self::assertSame('- [Industry Updates](' . self::BASE . '/news/): ' . \Psc\Content\Content::page('/news/')['description'], $lines[0]);
        self::assertCount(11, $lines, 'the section and its ten newest articles');
        self::assertStringStartsWith('- [Story 1 for the answer engines](' . self::BASE . '/news/video/llms-story-1/): ', $lines[1]);
        self::assertStringNotContainsString('llms-story-11', $llms);
        self::assertSame('public, max-age=3600', $this->request('GET', '/llms.txt')->getHeader('Cache-Control'));
        self::assertStringNotContainsString('llms-draft', $llms);
    }

    public function testHealthReportsTheDeskAndImagesWithoutFailingOnThem(): void
    {
        $body = fn (): array => json_decode($this->request('GET', '/health')->body, true, 512, JSON_THROW_ON_ERROR);
        $h = $body();
        self::assertSame('ok', $h['status']);
        self::assertSame(['storage' => true, 'vendor' => true, 'database' => true, 'config_shadowed' => false, 'schema' => true], $h['checks'], 'the checks are unchanged');
        self::assertSame('off', $h['news'], 'no key: the desk is off');
        self::assertIsBool($h['images']);
        self::assertSame(function_exists('imagewebp') && function_exists('imagejpeg') && function_exists('getimagesize'), $h['images']);

        $this->bootApp(['news' => ['api_key' => 'sk-news-test']]);
        self::assertSame('stale', $body()['news'], 'a key and no tick yet: the cron has not run');
        $settings = new Settings($this->app);
        $settings->heartbeat(['imported' => 0]);
        self::assertSame('ok', $body()['news']);
        $this->db->update('news_settings', ['value' => json_encode(['t' => time() - SeoController::NEWS_STALE_SECONDS - 60])], ['name' => 'heartbeat']);
        $stale = $this->request('GET', '/health');
        self::assertSame(200, $stale->status, 'a stale desk is reported, not failed');
        self::assertSame(['status' => 'ok', 'news' => 'stale'], array_intersect_key(json_decode($stale->body, true), ['status' => 1, 'news' => 1]));
        // A session still inside its time keeps the desk alive, as the desk page judges it (Settings::cronState()).
        self::assertSame(SeoController::NEWS_STALE_SECONDS, Settings::TICK_STALE);
        $runs = new \Psc\News\Runs($this->app);
        $run = (int) $runs->queueManual(null);
        self::assertTrue($runs->start($run, time() + 600));
        self::assertSame('ok', $body()['news'], 'a session running inside its time');
        $this->db->update('news_runs', ['deadline_at' => time() - \Psc\News\Runs::REAP_AFTER - 60], ['id' => $run]);
        self::assertSame('stale', $body()['news'], 'one past the time the reaper fails it');
        self::assertSame(['enabled'], $settings->save(['enabled' => false], null)['changed']);
        self::assertSame('off', $body()['news'], 'paused');

        $this->bootApp(['chat' => ['api_key' => 'sk-chat-test']]);
        self::assertSame('stale', $body()['news'], 'the assistant\'s key serves the desk too');
    }

    // ------------------------------------------------------------------------------------------- page fences

    public function testEveryNewsPageHasOneHeadingTreeAndTitlesSearchEnginesShowWhole(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->makeArticle(['slug' => 'fence-' . $i, 'categories' => ['showcalling', 'interactive'], 'published_at' => time() - 60 * $i]);
        }
        foreach (['/news/', '/news/page/2/', '/news/?q=sample', '/news/showcalling/', '/news/interactive/page/2/', '/news/legal/', '/news/showcalling/fence-1/'] as $uri) {
            $b = $this->body($uri);
            self::assertNoHeadingJumps($b, $uri);
            self::assertSame(1, preg_match('#<title>(.*?)</title>#', $b, $t));
            self::assertLessThanOrEqual(65, mb_strlen(html_entity_decode($t[1], ENT_QUOTES)), $uri . ': ' . $t[1]);
            $description = (string) self::meta($b, 'name', 'description');
            self::assertGreaterThanOrEqual(50, mb_strlen($description), $uri);
            self::assertLessThanOrEqual(160, mb_strlen($description), $uri);
            self::assertStringNotContainsString(' style="', $b, $uri);
        }
    }

    /**
     * The lead card spans two rows from 64rem, beside two cards that may be far taller than it. Its picture keeps the
     * cards' 16:9 under a cap and never takes the height they add, so its headline, standfirst and date follow the
     * picture together (checked at 1440, 1280, 1024 and 390 wide in a browser; this holds the rules that do it).
     */
    public function testTheLeadCardsPictureKeepsItsShapeBesideTallerCards(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(PSC_ROOT . '/public/assets/css/pages/news.css'));
        self::assertSame(1, preg_match('#^\s*\.news-card-media \{([^{}]*)#m', $css, $card));
        self::assertMatchesRegularExpression('#aspect-ratio:\s*16 / 9#', $card[1], 'every card\'s picture is 16:9');
        self::assertGreaterThan(0, preg_match_all('#\.news-card--lead \.news-card-media\s*\{([^{}]*)\}#', $css, $lead));
        $rules = implode(';', $lead[1]);
        self::assertStringNotContainsString('aspect-ratio', $rules, 'the lead\'s too');
        self::assertDoesNotMatchRegularExpression('#min-height|flex:\s*[1-9]|flex-grow:\s*[1-9]#', $rules, 'never stretched by the cards beside it');
        self::assertMatchesRegularExpression('#flex:\s*none#', $rules);
        self::assertMatchesRegularExpression('#max-height:\s*min\([^)]*vh\)#', $rules, 'capped below a screen\'s height');
        self::assertMatchesRegularExpression('#\.news-card--lead \.news-card-meta\s*\{\s*margin-top:\s*0;?\s*\}#', $css, 'its date follows its standfirst');
    }

    public function testTheHeaderAndFooterLeadToTheSection(): void
    {
        $nav = array_column(\Psc\Content\Content::get('site')['nav'], 'label');
        self::assertSame(['Ecosystem', 'Products', 'Workflows', 'Licensing', 'News', 'Support'], $nav);
        self::assertStringContainsString('<a class="c-nav-link" href="/news/" aria-current="page">News</a>', $this->body('/news/'));
        $this->makeArticle(['slug' => 'highlighted-story']);
        self::assertStringContainsString('<a class="c-nav-link" href="/news/" aria-current="true">News</a>', $this->body('/news/video/highlighted-story/'), 'the section stays lit on its pages');
        self::assertStringContainsString('<a href="/news/">Industry Updates</a>', $this->body('/'), 'the footer link');
    }
}
