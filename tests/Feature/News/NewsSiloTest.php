<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\Controllers\NewsController;
use Psc\Controllers\SeoController;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\News\Draft;
use Psc\Tests\TestCase;

/**
 * Industry Updates as one silo (docs/NEWS.md §5, §10): an article at /news/{its primary category}/{slug}/ and every
 * other address of it moving there for good, the comment page with it, the old category addresses, the category
 * hubs with their pages, heads, feeds and the way across, the slugs clear of the routes, and the section's own
 * sitemaps (an index of London months and a news sitemap). Statuses, headers and parsed XML first, words second.
 */
final class NewsSiloTest extends TestCase
{
    private const BASE = 'https://patternsshowcontrol.test';

    private function body(string $uri): string
    {
        $r = $this->request('GET', $uri);
        self::assertSame(200, $r->status, $uri);
        return $r->body;
    }

    private static function meta(string $body, string $attr, string $name): ?string
    {
        return preg_match('#<meta ' . $attr . '="' . preg_quote($name, '#') . '" content="([^"]*)">#', $body, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    /** @return array<string, mixed> the page's first JSON-LD node of the type */
    private static function node(string $body, string $type): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m));
        foreach (json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'] as $n) {
            if (($n['@type'] ?? '') === $type) {
                return $n;
            }
        }
        self::fail('No ' . $type);
    }

    private static function assertMoved(string $to, \Psc\Http\Response $r, string $what, int $status = 301): void
    {
        self::assertSame([$status, $to], [$r->status, $r->getHeader('Location')], $what);
        self::assertSame([], $r->cookies(), $what . ': no session for a move');
    }

    private static function assertGone(\Psc\Http\Response $r, string $what): void
    {
        self::assertSame(404, $r->status, $what);
        self::assertNull($r->getHeader('Location'), $what . ': no guess');
    }

    /** An image row for the article by the stored naming scheme; returns its base. */
    private function image(int $articleId, string $widths = '480,960,1600'): string
    {
        $base = bin2hex(random_bytes(16));
        $this->db->insert('news_images', [
            'article_id' => $articleId, 'position' => 0, 'base' => $base, 'widths' => $widths, 'width' => 1600, 'height' => 900,
            'alt' => 'A picture', 'caption' => null, 'credit' => null, 'credit_url' => null, 'original_name' => 'p.jpg', 'bytes' => 1000, 'created_by' => null, 'created_at' => time(),
        ]);
        return $base;
    }

    /** @return \SimpleXMLElement the parsed body, its namespaces checked against $namespaces (prefix => URI) */
    private function xml(string $uri, array $namespaces): \SimpleXMLElement
    {
        $r = $this->request('GET', $uri);
        self::assertSame(200, $r->status, $uri);
        self::assertSame('application/xml; charset=utf-8', $r->getHeader('Content-Type'), $uri);
        self::assertSame([], $r->cookies(), $uri);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($r->body), $uri . ': well-formed');
        $xml = simplexml_load_string($r->body);
        self::assertNotFalse($xml);
        self::assertSame($namespaces, $xml->getDocNamespaces(), $uri . ': its namespaces');
        return $xml;
    }

    // -------------------------------------------------------------------------------------------- the addresses

    public function testAnArticleLivesUnderItsPrimaryCategoryAndEveryOtherAddressMovesThere(): void
    {
        $id = $this->makeArticle(['slug' => 'genlock-firmware', 'categories' => ['video', 'technology']]);
        $r = $this->request('GET', '/news/video/genlock-firmware/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/video/genlock-firmware/">', $r->body);

        self::assertMoved('/news/video/genlock-firmware/', $this->request('GET', '/news/technology/genlock-firmware/'), 'another of its categories');
        self::assertMoved('/news/video/genlock-firmware/', $this->request('GET', '/news/legal/genlock-firmware/'), 'a category it is not in');
        self::assertMoved('/news/video/genlock-firmware/', $this->request('GET', '/news/genlock-firmware/'), 'its lasting address');
        self::assertMoved('/news/video/genlock-firmware/?utm_source=mail', $this->request('GET', '/news/genlock-firmware/?utm_source=mail'), 'with its query');
        self::assertMoved('/news/video/genlock-firmware/', $this->request('GET', '/news/video/genlock-firmware'), 'without its slash');

        // A new primary category moves the article, and its old address leads there; the slug stays.
        $store = new ArticleStore($this->app);
        $draft = $store->draft($id);
        self::assertInstanceOf(Draft::class, $draft);
        $draft->categories = ['technology', 'video'];
        self::assertSame(['categories'], $store->update($id, $draft, null));
        self::assertSame(200, $this->request('GET', '/news/technology/genlock-firmware/')->status);
        self::assertMoved('/news/technology/genlock-firmware/', $this->request('GET', '/news/video/genlock-firmware/'), 'the old category');
        self::assertSame('genlock-firmware', $store->find($id)['slug']);

        // An unknown slug, or one that is not live, is a plain 404 at every address: no redirect says it is there.
        $this->makeArticle(['slug' => 'unpublished-draft', 'status' => 'draft']);
        $this->makeArticle(['slug' => 'scheduled-story', 'published_at' => time() + 3600]);
        foreach (['/news/video/no-such-story/', '/news/no-such-story/', '/news/technology/unpublished-draft/', '/news/unpublished-draft/', '/news/video/scheduled-story/', '/news/scheduled-story/'] as $uri) {
            self::assertGone($this->request('GET', $uri), $uri);
        }
    }

    public function testTheCommentPageMovesWithItsArticleBeforeAnySessionStarts(): void
    {
        $id = $this->makeArticle(['slug' => 'radio-spectrum', 'categories' => ['audio', 'legal']]);
        $page = '/news/audio/radio-spectrum/comment/';
        self::assertMoved($page, $this->request('GET', '/news/legal/radio-spectrum/comment/'), 'another category, signed out');
        self::assertMoved($page, $this->request('GET', '/news/radio-spectrum/comment/'), 'its lasting address');
        self::assertSame(302, $this->request('GET', $page)->status, 'its own address asks a visitor to sign in');
        self::assertSame('/portal/login/?next=' . rawurlencode('/news/video/no-such-story/comment/'), $this->request('GET', '/news/video/no-such-story/comment/')->getHeader('Location'), 'nothing to move: sign in first');

        $this->signInClient();
        self::assertSame(200, $this->request('GET', $page)->status);
        // A form drawn before the category changed is sent again at the new address (308), never lost.
        $stale = $this->post('/news/legal/radio-spectrum/comment/', ['action' => 'post', 'body' => 'Posted from an old page']);
        self::assertSame([308, $page], [$stale->status, $stale->getHeader('Location')]);
        $posted = $this->post($page, ['action' => 'post', 'body' => 'Posted from an old page']);
        self::assertSame(303, $posted->status);
        self::assertStringStartsWith($page . '#comment-', (string) $posted->getHeader('Location'));
        self::assertGone($this->request('GET', '/news/video/no-such-story/comment/'), 'signed in, an unknown slug');

        // Taken down, the page is only its commenter's, and only they are told where it is.
        $mine = (int) $this->db->value('SELECT id FROM news_comments WHERE article_id = ?', [$id]);
        self::assertGreaterThan(0, $mine);
        (new ArticleStore($this->app))->unpublish($id);
        self::assertMoved($page, $this->request('GET', '/news/legal/radio-spectrum/comment/'), 'to its commenter');
        self::assertSame(200, $this->request('GET', $page)->status);
        $this->jar = [];
        $_SESSION = [];
        $this->signInClient('someone@example.test');
        self::assertGone($this->request('GET', '/news/legal/radio-spectrum/comment/'), 'to anyone else');
        self::assertGone($this->request('GET', $page), 'to anyone else');
    }

    /**
     * The old category addresses move to their hubs, a page that exists to its page, with the rest of the query; a
     * page past the end is a 404 there, never a move to one.
     */
    public function testTheOldCategoryAddressesMoveToTheirHubs(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->makeArticle(['slug' => 'audio-story-' . $i, 'categories' => ['audio'], 'published_at' => time() - 60 * $i]);
        }
        self::assertMoved('/news/audio/', $this->request('GET', '/news/category/audio/'), 'a hub');
        self::assertMoved('/news/audio/page/2/', $this->request('GET', '/news/category/audio/?page=2'), 'a page of it');
        self::assertMoved('/news/audio/', $this->request('GET', '/news/category/audio/?page=x'), 'a page that is not one');
        self::assertMoved('/news/audio/?utm_source=newsletter', $this->request('GET', '/news/category/audio/?utm_source=newsletter'), 'its campaign tags kept');
        self::assertMoved('/news/audio/page/2/?utm_source=newsletter&utm_medium=email', $this->request('GET', '/news/category/audio/?utm_source=newsletter&page=2&utm_medium=email'), 'all but the page');
        self::assertSame(200, $this->request('GET', '/news/audio/page/2/?utm_source=newsletter&utm_medium=email')->status);
        foreach (['/news/category/audio/?page=3', '/news/category/audio/?page=5&utm_source=newsletter', '/news/category/video/?page=2'] as $uri) {
            $past = $this->request('GET', $uri);
            self::assertSame([404, null], [$past->status, $past->getHeader('Location')], $uri . ': past the end');
        }
        self::assertGone($this->request('GET', '/news/category/rumours/'), 'an unknown key');
    }

    /**
     * Capitals are moved to lower case only for a page with nothing in its path a route reads as a value: a slug or a
     * category key in capitals is a 404, never a hop to a guess.
     */
    public function testCapitalsNeverMoveToAnArticleOrAHub(): void
    {
        $this->makeArticle(['slug' => 'genlock-firmware']);
        self::assertMoved('/news/', $this->request('GET', '/News/'), 'the hub');
        foreach (['/news/NOPE/', '/news/Video/', '/news/video/Genlock-Firmware/', '/News/video/genlock-firmware/', '/news/Some-Typo/comment/'] as $uri) {
            self::assertGone($this->request('GET', $uri), $uri);
        }
    }

    /**
     * A link to another article is drawn at the address that article has when the page is drawn, however it was
     * written (the old flat form, another category, no slash, capitals, this site's host, its comment page); one to an
     * article that is not live keeps only its words. Nothing is rewritten in the stored body.
     */
    public function testLinksToOtherArticlesAreDrawnAtTheirAddressOfTheDay(): void
    {
        $target = $this->makeArticle(['slug' => 'target-story', 'categories' => ['video']]);
        $this->makeArticle(['slug' => 'draft-story', 'status' => 'draft']);
        $body = 'See [flat](/news/target-story/), [old category](/news/legal/target-story/), [no slash](/news/target-story), [capitals](/news/Target-Story/),'
            . ' [our host](' . self::BASE . '/news/video/target-story/comment/#comments), [a draft](/news/draft-story/), [nobody](/news/no-such-story),'
            . ' [a hub](/news/audio/) and [a guide](/workflows/).' . str_repeat(' Words that make the body long enough to publish under the rules.', 4);
        $this->makeArticle([
            'slug' => 'linking-story',
            'categories' => ['audio'],
            'body' => $body,
            'impact' => 'Read [the earlier one](/news/target-story/) first.',
            'confirmed' => [['text' => 'It was [reported](/news/Target-Story) here.', 'url' => 'https://example.com/news/story-3']],
            'sources' => [['url' => 'https://example.com/news/story-3', 'title' => 'Sample source', 'publisher' => 'Example News', 'kind' => 'press']],
        ]);
        $stored = (string) $this->db->value("SELECT body FROM news_articles WHERE slug = 'linking-story'");
        self::assertSame($body, $stored, 'stored as written');

        $expect = static function (string $at, string $page): void {
            foreach (['flat', 'old category', 'no slash', 'capitals'] as $words) {
                self::assertStringContainsString('<a href="' . $at . '">' . $words . '</a>', $page, $words);
            }
            self::assertStringContainsString('<a href="' . $at . 'comment/#comments">our host</a>', $page);
            self::assertStringContainsString('<a href="' . $at . '">the earlier one</a>', $page, 'in the impact');
            self::assertStringContainsString('<a href="' . $at . '">reported</a>', $page, 'in an item');
            self::assertStringContainsString(' a draft, nobody, ', $page, 'not live: its words alone');
            self::assertStringContainsString('<a href="/news/audio/">a hub</a>', $page);
            self::assertStringContainsString('<a href="/workflows/">a guide</a>', $page);
            self::assertStringNotContainsString('draft-story', $page);
        };
        $expect('/news/video/target-story/', $this->body('/news/audio/linking-story/'));

        // A new main category moves the article, and every link to it moves with it on the next drawing.
        $store = new ArticleStore($this->app);
        $draft = $store->draft($target);
        self::assertInstanceOf(Draft::class, $draft);
        $draft->categories = ['technology', 'video'];
        self::assertSame(['categories'], $store->update($target, $draft, null));
        $expect('/news/technology/target-story/', $this->body('/news/audio/linking-story/'));
        self::assertSame($body, (string) $this->db->value("SELECT body FROM news_articles WHERE slug = 'linking-story'"));

        // Taken down, it is linked no more.
        $store->unpublish($target);
        $page = $this->body('/news/audio/linking-story/');
        self::assertStringNotContainsString('target-story', $page);
        self::assertStringContainsString('See flat, old category, no slash, capitals, our host, a draft, nobody,', $page);
    }

    /**
     * A deleted article's slug, live or not, is never given to another article, and its addresses (any category, the
     * lasting one, the comment page) answer 410 and are not indexed, before any session starts.
     */
    public function testADeletedArticlesSlugIsNeverGivenAgainAndItsAddressesAreGone(): void
    {
        $store = new ArticleStore($this->app);
        $live = $this->makeArticle(['slug' => 'genlock-firmware', 'categories' => ['video']]);
        $draft = $this->makeArticle(['slug' => 'console-rumour', 'status' => 'draft']);
        self::assertNotNull($store->delete($live));
        self::assertNotNull($store->delete($draft));
        self::assertTrue($store->isRetired('genlock-firmware'));
        self::assertTrue($store->isRetired('Console-Rumour'));
        self::assertFalse($store->isRetired('genlock'));

        $next = $this->makeArticle(['slug' => 'genlock-firmware', 'categories' => ['legal']]);
        self::assertSame('genlock-firmware-2', $store->find($next)['slug'], 'never the deleted one\'s');
        self::assertSame('console-rumour-2', $store->find($this->makeArticle(['slug' => 'console-rumour', 'status' => 'draft']))['slug'], 'a draft\'s too');
        self::assertSame('genlock-firmware-3', $store->slugFor('genlock-firmware'));

        foreach (['/news/video/genlock-firmware/', '/news/legal/genlock-firmware/', '/news/genlock-firmware/', '/news/genlock-firmware/comment/', '/news/video/genlock-firmware/comment/', '/news/console-rumour/'] as $uri) {
            $r = $this->request('GET', $uri);
            self::assertSame(410, $r->status, $uri);
            self::assertNull($r->getHeader('Location'), $uri . ': it leads nowhere else');
            self::assertSame('noindex', $r->getHeader('X-Robots-Tag'), $uri);
            self::assertStringContainsString('<meta name="robots" content="noindex', $r->body, $uri);
            self::assertSame([], $r->cookies(), $uri . ': no session');
        }
        self::assertSame(410, $this->post('/news/genlock-firmware/comment/', ['action' => 'post', 'body' => 'Late'])->status);
        self::assertSame(404, $this->request('GET', '/news/never-was-a-story/')->status, 'a slug nobody had is a plain 404');
        self::assertSame(200, $this->request('GET', '/news/legal/genlock-firmware-2/')->status);
    }

    // ---------------------------------------------------------------------------------------------------- slugs

    public function testNoSlugIsAWordTheRoutesUseAndEachPageStillAnswers(): void
    {
        foreach (['Video' => 'video-2', 'Page' => 'page-2', 'Comment' => 'comment-2', 'Category' => 'category-2', 'Sitemap' => 'sitemap-2'] as $title => $slug) {
            $id = $this->makeArticle(['title' => $title . ' is the whole headline here', 'slug' => $title, 'categories' => ['audio']]);
            self::assertSame($slug, (new ArticleStore($this->app))->find($id)['slug'], $title);
            self::assertSame(200, $this->request('GET', '/news/audio/' . $slug . '/')->status, $slug);
        }
        self::assertSame(200, $this->request('GET', '/news/video/')->status, 'the hub is still the hub');
        self::assertGone($this->request('GET', '/news/audio/page/'), 'page is no slug');
        self::assertGone($this->request('GET', '/news/page/'), 'nor at the top');
    }

    public function testASlugComesFromTheSeoTitleShortAndWithoutStopWordsAtItsEnds(): void
    {
        $id = $this->makeArticle([
            'title' => 'The regulator moves the radio mic booking deadline earlier for the spring season, and freelancers have to act now',
            'seo_title' => 'The radio mic booking deadline moves to February',
            'categories' => ['audio'],
        ]);
        $slug = (string) (new ArticleStore($this->app))->find($id)['slug'];
        self::assertSame('radio-mic-booking-deadline-moves-to-february', $slug);
        self::assertSame(200, $this->request('GET', '/news/audio/' . $slug . '/')->status);
        $plain = $this->makeArticle(['title' => 'A rumour about a console from the trade show floor and a', 'categories' => ['audio']]);
        self::assertSame('rumour-about-a-console-from-the-trade-show-floor', (new ArticleStore($this->app))->find($plain)['slug'], 'the headline, its stop words trimmed at the ends only');
    }

    // ------------------------------------------------------------------------------------------------- the hubs

    public function testACategoryHubHasItsOwnHeadAndTheWayAcrossTheSilo(): void
    {
        $this->makeArticle(['slug' => 'desk-firmware', 'categories' => ['audio'], 'published_at' => time() - 120]);
        $this->makeArticle(['slug' => 'radio-mics-and-cameras', 'categories' => ['audio', 'video']]);
        $this->makeArticle(['slug' => 'licence-law', 'categories' => ['legal']]);
        $this->makeArticle(['slug' => 'a-secret-fixture', 'categories' => ['lighting'], 'status' => 'draft']);

        $r = $this->request('GET', '/news/audio/');
        self::assertSame(200, $r->status);
        self::assertSame(NewsController::CACHE, $r->getHeader('Cache-Control'));
        $b = $r->body;
        $audio = Categories::get('audio');
        self::assertStringContainsString('<title>' . e($audio['title']) . '</title>', $b);
        self::assertLessThanOrEqual(60, mb_strlen($audio['title']));
        self::assertSame($audio['description'], self::meta($b, 'name', 'description'));
        self::assertStringContainsString(e($audio['intro']), $b);
        self::assertSame('index, follow, max-image-preview:large, max-snippet:-1', self::meta($b, 'name', 'robots'));
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/audio/">', $b);
        self::assertSame(self::BASE . '/assets/img/og/news-audio.png', self::meta($b, 'property', 'og:image'), 'its own share card');
        self::assertSame(Categories::headline('audio') . ', from Industry Updates', self::meta($b, 'property', 'og:image:alt'));
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Audio: Industry Updates RSS feed" href="/news/audio/feed.xml">', $b);
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Industry Updates RSS feed" href="/news/feed.xml">', $b);

        $page = self::node($b, 'CollectionPage');
        self::assertSame(self::BASE . '/news/audio/#webpage', $page['@id']);
        self::assertSame(['@id' => self::BASE . '/news/audio/#list'], $page['mainEntity']);
        self::assertSame([self::BASE . '/news/audio/radio-mics-and-cameras/', self::BASE . '/news/audio/desk-firmware/'], array_column(self::node($b, 'ItemList')['itemListElement'], 'url'));
        self::assertSame(['Home', 'Industry Updates', 'Audio'], array_column(self::node($b, 'BreadcrumbList')['itemListElement'], 'name'));

        // The way across: the hub, and the other categories that have live articles.
        self::assertSame(1, preg_match('#<nav class="news-silo" aria-labelledby="news-silo-title">(.*?)</nav>#s', $b, $silo));
        preg_match_all('#<a href="([^"]+)">([^<]+)</a>#', $silo[1], $links, PREG_SET_ORDER);
        self::assertSame([['/news/', 'Every article'], ['/news/video/', 'Video'], ['/news/legal/', 'Legal']], array_map(static fn (array $l): array => [$l[1], $l[2]], $links));
        self::assertStringContainsString('More Industry Updates', $silo[1]);

        // The hub links to every category hub that has articles, and to no other.
        $index = $this->body('/news/');
        self::assertSame(1, preg_match('#<nav class="news-silo" aria-labelledby="news-silo-title">(.*?)</nav>#s', $index, $hubSilo));
        preg_match_all('#<a href="([^"]+)">#', $hubSilo[1], $hubs);
        self::assertSame(['/news/video/', '/news/audio/', '/news/legal/'], $hubs[1]);
        self::assertStringNotContainsString('/news/lighting/', $index);
    }

    public function testACategoryHubPagesByPathAndEachPageNamesItself(): void
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->makeArticle(['slug' => 'audio-story-' . $i, 'categories' => ['audio'], 'published_at' => time() - 60 * $i]);
        }
        $first = $this->body('/news/audio/');
        self::assertStringContainsString('<a rel="next" href="/news/audio/page/2/">', $first);
        $two = $this->request('GET', '/news/audio/page/2/');
        self::assertSame(200, $two->status);
        self::assertSame(NewsController::CACHE, $two->getHeader('Cache-Control'));
        self::assertStringContainsString('<title>Audio news, page 2 | Industry Updates</title>', $two->body);
        self::assertSame('Page 2: ' . Categories::get('audio')['description'], self::meta($two->body, 'name', 'description'));
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/news/audio/page/2/">', $two->body);
        self::assertSame('index, follow, max-image-preview:large, max-snippet:-1', self::meta($two->body, 'name', 'robots'));
        self::assertStringContainsString('<a rel="prev" href="/news/audio/">', $two->body);
        self::assertSame([self::BASE . '/', self::BASE . '/news/', self::BASE . '/news/audio/', self::BASE . '/news/audio/page/2/'], array_column(self::node($two->body, 'BreadcrumbList')['itemListElement'], 'item'));
        self::assertSame([self::BASE . '/news/audio/audio-story-13/'], array_column(self::node($two->body, 'ItemList')['itemListElement'], 'url'));

        self::assertMoved('/news/audio/', $this->request('GET', '/news/audio/page/1/'), 'page 1 is the hub');
        self::assertMoved('/news/audio/page/2/', $this->request('GET', '/news/audio/?page=2'), 'a page number in the query');
        self::assertMoved('/news/audio/page/2/?utm_campaign=x', $this->request('GET', '/news/audio/?page=2&utm_campaign=x'), 'with the rest of its query');
        self::assertMoved('/news/audio/?utm_campaign=x', $this->request('GET', '/news/audio/page/1/?utm_campaign=x'), 'page 1 too');
        self::assertGone($this->request('GET', '/news/audio/page/3/'), 'past the end');
        self::assertGone($this->request('GET', '/news/audio/?page=3'), 'past the end, asked by the query: no move to a 404');
        self::assertGone($this->request('GET', '/news/audio/?page=0'), 'not a page');
    }

    public function testACategoryFeedListsOnlyItsOwnArticles(): void
    {
        $this->makeArticle(['slug' => 'mixing-desks', 'categories' => ['audio', 'video'], 'published_at' => time() - 60]);
        $this->makeArticle(['slug' => 'video-only', 'categories' => ['video']]);
        $this->makeArticle(['slug' => 'audio-draft', 'categories' => ['audio'], 'status' => 'draft']);
        $r = $this->request('GET', '/news/audio/feed.xml');
        self::assertSame(200, $r->status);
        self::assertSame('application/rss+xml; charset=utf-8', $r->getHeader('Content-Type'));
        self::assertSame(NewsController::FEED_CACHE, $r->getHeader('Cache-Control'));
        $xml = simplexml_load_string($r->body);
        self::assertNotFalse($xml);
        self::assertSame('Industry Updates from Patterns Show Control: Audio', (string) $xml->channel->title);
        self::assertSame(self::BASE . '/news/audio/', (string) $xml->channel->link);
        self::assertSame(Categories::get('audio')['description'], (string) $xml->channel->description);
        self::assertStringContainsString('<atom:link href="' . self::BASE . '/news/audio/feed.xml" rel="self" type="application/rss+xml"/>', $r->body);
        self::assertCount(1, $xml->channel->item);
        self::assertSame(self::BASE . '/news/audio/mixing-desks/', (string) $xml->channel->item[0]->link, 'at its primary category');
        self::assertSame(self::BASE . '/news/mixing-desks/', (string) $xml->channel->item[0]->guid);
        self::assertSame(2, substr_count($this->body('/news/feed.xml'), '<item>'), 'the section\'s feed has both');
        self::assertGone($this->request('GET', '/news/rumours/feed.xml'), 'an unknown category');
    }

    public function testAnArticleWithoutAPictureSharesItsCategorysCard(): void
    {
        $this->makeArticle(['slug' => 'tips-without-pictures', 'categories' => ['tips', 'audio']]);
        $b = $this->body('/news/tips/tips-without-pictures/');
        self::assertSame(self::BASE . '/assets/img/og/news-tips.png', self::meta($b, 'property', 'og:image'));
        self::assertSame([self::BASE . '/assets/img/og/news-tips.png'], self::node($b, 'NewsArticle')['image']);
        foreach (Categories::KEYS as $key) {
            self::assertFileExists(PSC_ROOT . '/public' . Categories::shareCard($key), $key);
        }
    }

    /** "More in" lists up to four of the newest live articles of the primary category, never the article itself. */
    public function testAnArticleEndsWithTheNewestOfItsCategory(): void
    {
        $alone = $this->makeArticle(['slug' => 'only-one-in-legal', 'categories' => ['legal']]);
        self::assertGreaterThan(0, $alone);
        $b = $this->body('/news/legal/only-one-in-legal/');
        self::assertStringContainsString('<h2 id="news-more">More in Legal</h2>', $b);
        self::assertStringNotContainsString('news-more-list', $b, 'nothing else to list');
        self::assertStringContainsString('href="/news/legal/">Every article in Legal</a>', $b);

        for ($i = 1; $i <= 6; $i++) {
            $this->makeArticle(['slug' => 'video-story-' . $i, 'categories' => ['video', 'legal'], 'published_at' => time() - 60 * $i]);
        }
        $this->makeArticle(['slug' => 'video-draft', 'categories' => ['video'], 'status' => 'draft']);
        $this->makeArticle(['slug' => 'legal-first', 'categories' => ['legal', 'video'], 'published_at' => time() - 30]);
        $b = $this->body('/news/video/video-story-2/');
        self::assertSame(1, preg_match('#<ul class="news-more-list" role="list">(.*?)</ul>#s', $b, $more));
        preg_match_all('#<a href="([^"]+)">#', $more[1], $links);
        self::assertSame(['/news/legal/legal-first/', '/news/video/video-story-1/', '/news/video/video-story-3/', '/news/video/video-story-4/'], $links[1], 'the newest four, itself left out, each at its own address');
        self::assertStringContainsString('href="/news/video/">Every article in Video</a>', $b);
    }

    // ----------------------------------------------------------------------------------------------- sitemaps

    /**
     * /news/sitemap.xml indexes one sitemap per London month with live articles, the newest first, each with its
     * newest change, then the news sitemap. A month's sitemap lists that month's articles (London's month: 23:30 on
     * 31 March in UTC is April in London) with their lead pictures; a month without articles is a 404.
     */
    public function testTheSectionsSitemapsSplitTheArticlesByLondonMonth(): void
    {
        $march = (int) gmmktime(12, 0, 0, 3, 15, 2025);
        $lateMarchUtc = (int) gmmktime(23, 30, 0, 3, 31, 2025);
        $april = (int) gmmktime(9, 0, 0, 4, 20, 2025);
        $inMarch = $this->makeArticle(['slug' => 'march-story', 'published_at' => $march]);
        $inApril = $this->makeArticle(['slug' => 'april-in-london', 'categories' => ['audio'], 'published_at' => $lateMarchUtc]);
        $this->makeArticle(['slug' => 'later-in-april', 'published_at' => $april]);
        $recent = time() - 600;
        $this->makeArticle(['slug' => 'just-now', 'categories' => ['legal'], 'published_at' => $recent]);
        $this->makeArticle(['slug' => 'march-draft', 'status' => 'draft', 'published_at' => $march + 60]);
        $this->makeArticle(['slug' => 'not-yet', 'published_at' => time() + 3600]);
        $changed = $march + 86400;
        $this->db->update('news_articles', ['created_at' => $march, 'updated_at' => $changed], ['id' => $inMarch]);
        $this->db->update('news_articles', ['created_at' => $lateMarchUtc, 'updated_at' => $lateMarchUtc], ['id' => $inApril]);
        $lead = $this->image($inApril);

        $index = $this->xml('/news/sitemap.xml', ['' => SeoController::SITEMAP_NS]);
        self::assertSame('sitemapindex', $index->getName());
        $now = (new \DateTimeImmutable('@' . $recent))->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m');
        $entries = [];
        foreach ($index->sitemap as $s) {
            $entries[(string) $s->loc] = (string) $s->lastmod;
        }
        self::assertSame([self::BASE . '/news/sitemap-' . $now . '.xml', self::BASE . '/news/sitemap-2025-04.xml', self::BASE . '/news/sitemap-2025-03.xml', self::BASE . '/news/sitemap-news.xml'], array_keys($entries));
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $changed), $entries[self::BASE . '/news/sitemap-2025-03.xml'], 'the month\'s newest change');
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $april), $entries[self::BASE . '/news/sitemap-2025-04.xml']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $recent), $entries[self::BASE . '/news/sitemap-news.xml']);
        self::assertSame(SeoController::SITEMAP_CACHE, $this->request('GET', '/news/sitemap.xml')->getHeader('Cache-Control'));

        $aprilMap = $this->xml('/news/sitemap-2025-04.xml', ['' => SeoController::SITEMAP_NS, 'image' => SeoController::IMAGE_NS]);
        self::assertSame('urlset', $aprilMap->getName());
        $urls = [];
        foreach ($aprilMap->url as $u) {
            $images = $u->children(SeoController::IMAGE_NS);
            $urls[(string) $u->loc] = [(string) $u->lastmod, isset($images->image) ? (string) $images->image->loc : null];
        }
        self::assertSame([
            self::BASE . '/news/video/later-in-april/' => [gmdate('Y-m-d\TH:i:s\Z', $april), null],
            self::BASE . '/news/audio/april-in-london/' => [gmdate('Y-m-d\TH:i:s\Z', $lateMarchUtc), self::BASE . '/news/media/' . $lead . '-1600.webp'],
        ], $urls, 'London\'s April, the newest first, the lead picture at its widest');
        self::assertSame(SeoController::SITEMAP_CACHE, $this->request('GET', '/news/sitemap-2025-04.xml')->getHeader('Cache-Control'));

        $marchMap = $this->xml('/news/sitemap-2025-03.xml', ['' => SeoController::SITEMAP_NS, 'image' => SeoController::IMAGE_NS]);
        self::assertSame([self::BASE . '/news/video/march-story/'], array_map('strval', iterator_to_array($marchMap->url->loc ?? [], false)), 'no draft');
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $changed), (string) $marchMap->url[0]->lastmod, 'its update after it went live');

        self::assertGone($this->request('GET', '/news/sitemap-2025-02.xml'), 'a month without articles');
        self::assertGone($this->request('GET', '/news/sitemap-2025-13.xml'), 'no such month');
    }

    /** The news sitemap lists what went live in the last 48 hours, with the publication, language, time and headline. */
    public function testTheNewsSitemapHoldsTheLastTwoDays(): void
    {
        // One clock reading for the stored times and the expected ones, so a second ticking over mid-test changes nothing.
        $now = time();
        $this->makeArticle(['slug' => 'hour-old-story', 'title' => 'Consoles & comms: an hour ago', 'categories' => ['audio'], 'published_at' => $now - 3600]);
        $this->makeArticle(['slug' => 'almost-two-days', 'published_at' => $now - 47 * 3600]);
        $this->makeArticle(['slug' => 'just-too-old', 'published_at' => $now - 49 * 3600]);
        $this->makeArticle(['slug' => 'recent-draft', 'status' => 'draft']);
        $this->makeArticle(['slug' => 'scheduled-soon', 'published_at' => $now + 600]);

        $r = $this->request('GET', '/news/sitemap-news.xml');
        self::assertSame(SeoController::NEWS_SITEMAP_CACHE, $r->getHeader('Cache-Control'));
        $xml = $this->xml('/news/sitemap-news.xml', ['' => SeoController::SITEMAP_NS, 'news' => SeoController::NEWS_NS]);
        $items = [];
        foreach ($xml->url as $u) {
            $news = $u->children(SeoController::NEWS_NS)->news;
            $items[(string) $u->loc] = [
                (string) $news->publication->name,
                (string) $news->publication->language,
                (string) $news->publication_date,
                (string) $news->title,
            ];
        }
        self::assertSame([
            self::BASE . '/news/audio/hour-old-story/' => ['Patterns Show Control', 'en', gmdate('Y-m-d\TH:i:s\Z', $now - 3600), 'Consoles & comms: an hour ago'],
            self::BASE . '/news/video/almost-two-days/' => ['Patterns Show Control', 'en', gmdate('Y-m-d\TH:i:s\Z', $now - 47 * 3600), 'Sample story number 2 from the test suite'],
        ], $items);
        self::assertStringContainsString('<news:title>Consoles &amp; comms: an hour ago</news:title>', $r->body, 'escaped');
    }

    public function testEveryArticleIsInExactlyOneMonthSitemapAndNoneInTheSitesOwn(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeArticle(['slug' => 'story-' . $i, 'categories' => [Categories::KEYS[$i]], 'published_at' => time() - 86400 * 20 * $i]);
        }
        $listed = [];
        foreach ($this->xml('/news/sitemap.xml', ['' => SeoController::SITEMAP_NS])->sitemap as $s) {
            $loc = (string) $s->loc;
            if (str_ends_with($loc, '/sitemap-news.xml')) {
                continue;
            }
            foreach ($this->xml((string) parse_url($loc, PHP_URL_PATH), ['' => SeoController::SITEMAP_NS, 'image' => SeoController::IMAGE_NS])->url as $u) {
                $listed[] = (string) $u->loc;
            }
        }
        sort($listed);
        $expected = [];
        for ($i = 1; $i <= 5; $i++) {
            $expected[] = self::BASE . '/news/' . Categories::KEYS[$i] . '/story-' . $i . '/';
        }
        sort($expected);
        self::assertSame($expected, $listed);
        $site = $this->body('/sitemap.xml');
        foreach ($expected as $loc) {
            self::assertStringNotContainsString($loc, $site);
        }
    }
}
