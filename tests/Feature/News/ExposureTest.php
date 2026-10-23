<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\Controllers\NewsController;
use Psc\Db\Database;
use Psc\News\Announcer;
use Psc\News\ArticleStore;
use Psc\News\Latest;
use Psc\News\Paths;
use Psc\News\Pipeline;
use Psc\News\Settings;
use Psc\Tests\Fakes\FakeNewsModel;
use Psc\Tests\Fakes\FakePingTransport;
use Psc\Tests\TestCase;

/**
 * How Industry Updates reaches people beyond its own pages (docs/NEWS.md §5, §6.1, §10): the tick's IndexNow and
 * WebSub announcements (each address once, what went live, changed or went, with the hubs and feeds it touched; a
 * failure retried with backoff; each channel on its own; never from a server that is not production), the key file,
 * the feeds' pictures, ttl, taxonomy and hub, the follow row on the hubs, the share row on an article, and the
 * latest-articles file the home and product pages read without the database. The pings go through a fake transport:
 * no test reaches the network.
 */
final class ExposureTest extends TestCase
{
    private const BASE = 'https://patternsshowcontrol.test';
    private const INDEXNOW = 'api.indexnow.org';
    private const HUB = 'pubsubhubbub.appspot.com';

    private FakePingTransport $pings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pings = new FakePingTransport();
        Announcer::useTransport($this->pings);
    }

    /** @return array<string, array<string, mixed>> */
    private function announce(?int $now = null): array
    {
        return (new Announcer($this->app))->run($now);
    }

    /** @return list<string> the addresses of the last IndexNow post */
    private function lastUrls(): array
    {
        $posts = $this->pings->to(self::INDEXNOW);
        self::assertNotSame([], $posts, 'IndexNow was told');
        return json_decode(end($posts)['body'], true, 512, JSON_THROW_ON_ERROR)['urlList'];
    }

    /** @return list<string> the feeds the last hub ping named */
    private function lastTopics(): array
    {
        $posts = $this->pings->to(self::HUB);
        self::assertNotSame([], $posts, 'the hub was told');
        $topics = [];
        foreach (explode('&', end($posts)['body']) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($name === 'hub.url') {
                $topics[] = rawurldecode($value);
            }
        }
        return $topics;
    }

    private function path(int $id): string
    {
        return Paths::article((array) (new ArticleStore($this->app))->find($id));
    }

    // ------------------------------------------------------------------------------------------------ IndexNow

    public function testTheFirstAnnouncementNamesEveryLiveArticleWithItsHubsAndFeeds(): void
    {
        $a = $this->makeArticle(['categories' => ['video', 'tips'], 'published_at' => time() - 120]);
        $b = $this->makeArticle(['categories' => ['legal'], 'published_at' => time() - 60]);
        $this->makeArticle(['categories' => ['audio'], 'published_at' => time() + 3600]);
        $this->makeArticle(['categories' => ['lighting'], 'status' => 'draft']);

        $report = $this->announce();
        self::assertSame(['sent', 200, 10], [$report['indexnow']['status'], $report['indexnow']['http'], $report['indexnow']['urls']]);
        $posts = $this->pings->to(self::INDEXNOW);
        self::assertCount(1, $posts, 'one batch');
        self::assertSame('https://api.indexnow.org/indexnow', $posts[0]['url']);
        self::assertSame('application/json; charset=utf-8', $posts[0]['type']);
        $body = json_decode($posts[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $key = (string) (new Settings($this->app))->indexNowKey(false);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key, 'hexadecimal, so both readings of the key rules allow it');
        self::assertSame(['host', 'key', 'keyLocation', 'urlList'], array_keys($body));
        self::assertSame(['patternsshowcontrol.test', $key, self::BASE . '/' . $key . '.txt'], [$body['host'], $body['key'], $body['keyLocation']]);
        self::assertSame([
            self::BASE . $this->path($a),
            self::BASE . $this->path($b),
            self::BASE . '/news/', self::BASE . '/news/video/', self::BASE . '/news/legal/', self::BASE . '/news/tips/',
            self::BASE . '/news/feed.xml', self::BASE . '/news/video/feed.xml', self::BASE . '/news/legal/feed.xml', self::BASE . '/news/tips/feed.xml',
        ], $body['urlList'], 'the live articles only, then the hubs and feeds they touched');

        // The key file answers for that key, so an engine can check the ping came from the site.
        $file = $this->request('GET', '/' . $key . '.txt');
        self::assertSame([200, $key, 'text/plain; charset=utf-8'], [$file->status, $file->body, $file->getHeader('Content-Type')]);
        self::assertSame('public, max-age=3600', $file->getHeader('Cache-Control'));
        self::assertSame([], $file->cookies());

        // Told once: nothing changed, nothing is sent.
        $again = $this->announce();
        self::assertSame(['idle', 'idle'], [$again['indexnow']['status'], $again['websub']['status']]);
        self::assertCount(1, $this->pings->to(self::INDEXNOW));
        self::assertSame($key, (new Settings($this->app))->indexNowKey(), 'the key is kept');
        $rows = $this->db->all("SELECT path, state, article_id FROM news_pings WHERE channel = 'indexnow' AND article_id IS NOT NULL ORDER BY path");
        self::assertSame([[$this->path($b), 'live', $b], [$this->path($a), 'live', $a]], array_map(static fn (array $r): array => [$r['path'], $r['state'], (int) $r['article_id']], $rows));
    }

    public function testAScheduledArticleIsAnnouncedOnceItsTimeHasPassed(): void
    {
        $this->makeArticle(['categories' => ['video']]);
        $later = $this->makeArticle(['categories' => ['streaming'], 'published_at' => time() + 1800]);
        $this->announce();
        self::assertNotContains(self::BASE . $this->path($later), $this->lastUrls(), 'not before its time');

        $this->db->exec('UPDATE news_articles SET published_at = ? WHERE id = ?', [time() - 5, $later]);
        self::assertSame('sent', $this->announce()['indexnow']['status']);
        self::assertSame([self::BASE . $this->path($later), self::BASE . '/news/', self::BASE . '/news/streaming/', self::BASE . '/news/feed.xml', self::BASE . '/news/streaming/feed.xml'], $this->lastUrls());
        self::assertSame([self::BASE . '/news/feed.xml', self::BASE . '/news/streaming/feed.xml'], $this->lastTopics());
    }

    public function testAChangeACorrectionATakedownADeletionAndAMoveAreEachAnnouncedOnce(): void
    {
        $now = time();
        $changed = $this->makeArticle(['categories' => ['video'], 'published_at' => $now - 7200]);
        $corrected = $this->makeArticle(['categories' => ['audio'], 'published_at' => $now - 7200]);
        $down = $this->makeArticle(['categories' => ['lighting'], 'published_at' => $now - 7200]);
        $deleted = $this->makeArticle(['categories' => ['staging'], 'published_at' => $now - 7200]);
        $moved = $this->makeArticle(['categories' => ['tips'], 'published_at' => $now - 7200]);
        $this->db->exec('UPDATE news_articles SET created_at = ?, updated_at = ?', [$now - 7200, $now - 7200]);
        $this->announce();
        $store = new ArticleStore($this->app);
        $paths = [];
        foreach ([$changed, $corrected, $down, $deleted, $moved] as $id) {
            $paths[$id] = $this->path($id);
        }

        // A change that counts (more than ten minutes after going live) is announced again, once.
        $this->db->exec('UPDATE news_articles SET updated_at = ? WHERE id = ?', [$now, $changed]);
        $this->announce();
        self::assertSame([self::BASE . $paths[$changed], self::BASE . '/news/', self::BASE . '/news/video/', self::BASE . '/news/feed.xml', self::BASE . '/news/video/feed.xml'], $this->lastUrls());
        self::assertSame('idle', $this->announce()['indexnow']['status']);

        // So is a correction.
        $this->db->exec('UPDATE news_articles SET corrected_at = ? WHERE id = ?', [$now + 1, $corrected]);
        $this->announce();
        self::assertContains(self::BASE . $paths[$corrected], $this->lastUrls());

        // A takedown and a deletion announce the address that went, once (the engines find its 404 and 410).
        self::assertTrue($store->unpublish($down));
        $store->delete($deleted);
        $this->announce();
        $urls = $this->lastUrls();
        self::assertContains(self::BASE . $paths[$down], $urls);
        self::assertContains(self::BASE . $paths[$deleted], $urls);
        self::assertContains(self::BASE . '/news/lighting/', $urls, 'its hub, from the address it had');
        self::assertContains(self::BASE . '/news/staging/feed.xml', $urls);
        self::assertSame(410, $this->request('GET', $paths[$deleted])->status);
        self::assertSame(['gone', 'gone'], array_map('strval', array_column($this->db->all("SELECT state FROM news_pings WHERE channel = 'indexnow' AND article_id IN (?, ?) ORDER BY article_id", [$down, $deleted]), 'state')));
        self::assertSame('idle', $this->announce()['indexnow']['status'], 'a gone address is told once');

        // A new primary category moves the article: the new address is announced, and so is the old one (a 301 now).
        $this->db->exec("UPDATE news_article_categories SET category = 'freelance' WHERE article_id = ?", [$moved]);
        $this->announce();
        $urls = $this->lastUrls();
        self::assertContains(self::BASE . '/news/freelance/' . basename($paths[$moved]) . '/', $urls);
        self::assertContains(self::BASE . $paths[$moved], $urls);
        self::assertSame(301, $this->request('GET', $paths[$moved])->status);

        // Brought back, the taken-down article is announced as live again.
        self::assertTrue($store->publish($down));
        $this->announce();
        self::assertContains(self::BASE . $paths[$down], $this->lastUrls());
    }

    public function testAFailureRecordsNothingAndIsRetriedWithBackoff(): void
    {
        $id = $this->makeArticle();
        $now = time();
        $this->pings->answers = [500, 204];
        $report = $this->announce($now);
        self::assertSame(['failed', 500, $now + Announcer::RETRY_FIRST], [$report['indexnow']['status'], $report['indexnow']['http'], $report['indexnow']['retry_at']]);
        self::assertSame('sent', $report['websub']['status'], 'the hub is a channel of its own');
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM news_pings WHERE channel = 'indexnow'"), 'nothing marked as sent');
        self::assertGreaterThan(0, (int) $this->db->value("SELECT COUNT(*) FROM news_pings WHERE channel = 'websub'"));

        // Before its time it waits; the hub, told already, has nothing to say.
        $waiting = $this->announce($now + 300);
        self::assertSame(['waiting', 'idle'], [$waiting['indexnow']['status'], $waiting['websub']['status']]);
        self::assertCount(1, $this->pings->to(self::INDEXNOW));

        // Each failure in a row doubles the wait (a refused connection is one too), up to a day.
        $this->pings->answers = [429, 0];
        self::assertSame($now + 601 + 2 * Announcer::RETRY_FIRST, $this->announce($now + 601)['indexnow']['retry_at']);
        self::assertSame($now + 601 + 1200 + 4 * Announcer::RETRY_FIRST, $this->announce($now + 601 + 1200)['indexnow']['retry_at']);
        self::assertSame(3, (new Announcer($this->app))->state('indexnow')['failures']);

        // Then it goes through, and the address it carried is marked.
        $done = $this->announce($now + 601 + 1200 + 2400);
        self::assertSame(['sent', 200], [$done['indexnow']['status'], $done['indexnow']['http']]);
        self::assertContains(self::BASE . $this->path($id), $this->lastUrls());
        $state = (new Announcer($this->app))->state('indexnow');
        self::assertSame([0, 0, 'sent'], [$state['failures'], $state['retry_at'], $state['last_status']]);

        $logged = '';
        foreach (glob($this->storage . '/logs/*.log') ?: [] as $file) {
            $logged .= (string) file_get_contents($file);
        }
        self::assertStringContainsString('"msg":"news announce failed"', $logged);
        self::assertStringNotContainsString((string) (new Settings($this->app))->indexNowKey(false), $logged, 'the log names no key');
    }

    public function testEachChannelSwitchesOffAndNothingLeavesAServerThatIsNotProduction(): void
    {
        $this->bootApp(['news' => ['indexnow' => false]]);
        $this->makeArticle();
        $report = $this->announce();
        self::assertSame(['off', 'sent'], [$report['indexnow']['status'], $report['websub']['status']]);
        self::assertSame([], $this->pings->to(self::INDEXNOW));
        self::assertNull((new Settings($this->app))->indexNowKey(false), 'no key is made for a channel that is off');

        $this->bootApp(['news' => ['websub_hub' => '']]);
        $this->makeArticle();
        $this->pings->posts = [];
        $report = $this->announce();
        self::assertSame(['sent', 'off'], [$report['indexnow']['status'], $report['websub']['status']]);
        self::assertSame([], $this->pings->to(self::HUB));
        $feed = $this->request('GET', '/news/feed.xml');
        self::assertStringNotContainsString('rel="hub"', $feed->body, 'the feed declares no hub');
        self::assertNull($feed->getHeader('Link'));

        // A hub written without its slash is the same address, not a channel turned off; one that is not https is
        // off, and the desk says why.
        $this->bootApp(['news' => ['websub_hub' => 'https://pubsubhubbub.appspot.com']]);
        self::assertSame('https://pubsubhubbub.appspot.com/', Announcer::hub($this->app));
        self::assertStringContainsString('<atom:link href="https://pubsubhubbub.appspot.com/" rel="hub"/>', $this->request('GET', '/news/feed.xml')->body);
        $this->bootApp(['news' => ['websub_hub' => 'http://hub.example/', 'indexnow_endpoint' => 'api.indexnow.org/indexnow']]);
        self::assertSame(['', ''], [Announcer::hub($this->app), Announcer::endpoint($this->app)]);
        $this->signInAdmin();
        $desk = $this->request('GET', '/portal/admin/news/desk/')->body;
        self::assertStringContainsString('Off (news.websub_hub is not an https address).', $desk);
        self::assertStringContainsString('Off (news.indexnow_endpoint is not an https address).', $desk);
        $this->bootApp(['news' => ['websub_hub' => '', 'indexnow' => false]]);
        $this->signInAdmin();
        $desk = $this->request('GET', '/portal/admin/news/desk/')->body;
        self::assertStringContainsString('Off (news.websub_hub is empty).', $desk);
        self::assertStringContainsString('Off (news.indexnow is false).', $desk);

        // In tests and on a development server the real transport is never built.
        Announcer::useTransport(null);
        $this->bootApp();
        $this->makeArticle();
        $report = $this->announce();
        self::assertSame(['off', 'not_production', 'off', 'not_production'], [$report['indexnow']['status'], $report['indexnow']['reason'], $report['websub']['status'], $report['websub']['reason']]);
    }

    public function testTheDeskPageSaysWhatTheTickLastToldEachChannel(): void
    {
        $this->signInAdmin();
        $before = $this->request('GET', '/portal/admin/news/desk/')->body;
        self::assertStringContainsString('<dt>Search engines (IndexNow)</dt>', $before);
        self::assertStringContainsString('Nothing told yet. Only the live site tells it anything.', $before);

        $this->makeArticle();
        $this->announce();
        $told = $this->request('GET', '/portal/admin/news/desk/')->body;
        self::assertStringContainsString('<dt>Feed readers (WebSub hub)</dt>', $told);
        self::assertSame(1, preg_match('#<dt>Search engines \(IndexNow\)</dt>\s*<dd>Last told [^<]+ \(5 addresses\)\.</dd>#', $told));

        $this->makeArticle(['categories' => ['audio']]);
        $this->pings->status = 503;
        $this->announce();
        $failing = $this->request('GET', '/portal/admin/news/desk/')->body;
        self::assertSame(1, preg_match('#<dt>Search engines \(IndexNow\)</dt>\s*<dd><span class="pt-badge pt-badge--declined">Failing</span> Not answering \(HTTP 503 at [^)]+\)\. Tries again from #', $failing));
    }

    public function testTheKeyFileAnswersOnlyItsOwnName(): void
    {
        $guess = str_repeat('ab', 16);
        $none = $this->request('GET', '/' . $guess . '.txt');
        self::assertSame(404, $none->status, 'no key is made by asking for one');
        self::assertNull((new Settings($this->app))->indexNowKey(false));

        $key = (string) (new Settings($this->app))->indexNowKey();
        self::assertSame(200, $this->request('GET', '/' . $key . '.txt')->status);
        self::assertSame(404, $this->request('GET', '/' . $guess . '.txt')->status);
        self::assertNull($this->app->router->match('GET', '/' . strtoupper($key) . '.TXT'), 'lower-case hex only');

        $this->bootApp(['news' => ['indexnow' => false]]);
        $this->db->insert('news_settings', ['name' => Settings::INDEXNOW_KEY, 'value' => json_encode($key), 'updated_by' => null, 'updated_at' => time()]);
        self::assertSame(404, $this->request('GET', '/' . $key . '.txt')->status, 'switched off, the file goes too');
    }

    public function testATickAnnouncesAfterItsSessionAndWritesTheLatestFile(): void
    {
        $this->bootApp(['news' => ['api_key' => 'sk-test', 'import_stagger' => false]]);
        $at = new \DateTimeImmutable('2026-10-22 12:00', new \DateTimeZone('Europe/London'));
        $report = (new Pipeline($this->app))->tick($at, new FakeNewsModel());
        self::assertSame([], $report['errors']);
        self::assertGreaterThan(0, $report['imported']);
        self::assertTrue($report['latest']);
        self::assertSame(['sent', 'sent'], [$report['announced']['indexnow']['status'], $report['announced']['websub']['status']]);
        $live = (new ArticleStore($this->app))->liveAddresses();
        self::assertCount($report['imported'], $live);
        foreach ($live as $a) {
            self::assertContains(self::BASE . $a['path'], $this->lastUrls(), 'every imported article that went live');
        }
        self::assertFileExists($this->storage . '/cache/news-latest.json');

        $next = (new Pipeline($this->app))->tick($at->modify('+15 minutes'), new FakeNewsModel());
        self::assertSame(['idle', 'idle', false], [$next['announced']['indexnow']['status'], $next['announced']['websub']['status'], $next['latest']]);
    }

    // -------------------------------------------------------------------------------------------------- feeds

    public function testTheFeedsCarryPicturesTheirTaxonomyATtlTheChannelPictureAndTheHub(): void
    {
        $plain = $this->makeArticle(['categories' => ['legal', 'freelance'], 'published_at' => time() - 120]);
        $pictured = $this->makeArticle(['categories' => ['video'], 'published_at' => time() - 60]);
        $base = bin2hex(random_bytes(16));
        $this->db->insert('news_images', [
            'article_id' => $pictured, 'position' => 0, 'base' => $base, 'widths' => '480,960,1600', 'width' => 1600, 'height' => 900,
            'alt' => 'The wall at load-in', 'caption' => null, 'credit' => 'Maker press kit', 'credit_url' => null, 'original_name' => 'wall.jpg', 'bytes' => 1000, 'created_by' => null, 'created_at' => time(),
        ]);
        $dir = $this->app->storage->dir(ArticleStore::IMAGE_DIR);
        file_put_contents($dir . '/' . $base . '-og.jpg', str_repeat('j', 2345));

        $r = $this->request('GET', '/news/feed.xml');
        self::assertSame(200, $r->status);
        self::assertSame('<https://pubsubhubbub.appspot.com/>; rel="hub", <' . self::BASE . '/news/feed.xml>; rel="self"', $r->getHeader('Link'));
        $xml = simplexml_load_string($r->body);
        self::assertNotFalse($xml);
        $channel = $xml->channel;
        self::assertSame('60', (string) $channel->ttl);
        self::assertSame([self::BASE . '/assets/img/brand/icon-144.png', (string) $channel->title, (string) $channel->link, '144', '144'],
            [(string) $channel->image->url, (string) $channel->image->title, (string) $channel->image->link, (string) $channel->image->width, (string) $channel->image->height]);
        $size = getimagesize(PSC_ROOT . '/public/assets/img/brand/icon-144.png');
        self::assertSame([144, 144, IMAGETYPE_PNG], [$size[0] ?? 0, $size[1] ?? 0, $size[2] ?? 0], 'the channel picture is the size the feed says, within RSS\'s 144');
        $links = [];
        foreach ($channel->children('http://www.w3.org/2005/Atom')->link as $link) {
            $links[(string) $link->attributes()->rel] = (string) $link->attributes()->href;
        }
        self::assertSame(['self' => self::BASE . '/news/feed.xml', 'hub' => 'https://pubsubhubbub.appspot.com/'], $links);

        $items = [];
        foreach ($channel->item as $item) {
            $items[basename((string) $item->guid)] = $item;
        }
        $slugOf = fn (int $id): string => (string) $this->db->value('SELECT slug FROM news_articles WHERE id = ?', [$id]);
        $withPicture = $items[$slugOf($pictured)];
        self::assertCount(1, $withPicture->enclosure, 'one enclosure an item');
        self::assertSame([self::BASE . '/news/media/' . $base . '-og.jpg', '2345', 'image/jpeg'],
            [(string) $withPicture->enclosure['url'], (string) $withPicture->enclosure['length'], (string) $withPicture->enclosure['type']]);
        $media = $withPicture->children('http://search.yahoo.com/mrss/')->content;
        self::assertSame([self::BASE . '/news/media/' . $base . '-og.jpg', 'image/jpeg', 'image', '1200', '630', '2345'],
            [(string) $media->attributes()->url, (string) $media->attributes()->type, (string) $media->attributes()->medium, (string) $media->attributes()->width, (string) $media->attributes()->height, (string) $media->attributes()->fileSize]);
        self::assertSame(['The wall at load-in', 'Maker press kit'], [(string) $media->children('http://search.yahoo.com/mrss/')->description, (string) $media->children('http://search.yahoo.com/mrss/')->credit]);

        // Without a picture of its own, an item carries its category's card.
        $card = PSC_ROOT . '/public/assets/img/og/news-legal.png';
        $withoutPicture = $items[$slugOf($plain)];
        self::assertSame([self::BASE . '/assets/img/og/news-legal.png', (string) filesize($card), 'image/png'],
            [(string) $withoutPicture->enclosure['url'], (string) $withoutPicture->enclosure['length'], (string) $withoutPicture->enclosure['type']]);
        self::assertSame(['Legal', 'Freelance'], array_map('strval', iterator_to_array($withoutPicture->category, false)));
        foreach ($withoutPicture->category as $category) {
            self::assertSame(self::BASE . '/news/', (string) $category['domain'], 'the section\'s categories, named as one taxonomy');
        }

        // A reader that sends back the feed's ETag is told it is unchanged, until it changes.
        $etag = (string) $r->getHeader('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
        self::assertNotNull($r->getHeader('Last-Modified'));
        $same = $this->request('GET', '/news/feed.xml', [], ['If-None-Match' => $etag]);
        self::assertSame([304, '', $etag], [$same->status, $same->body, $same->getHeader('ETag')]);
        // No shared cache keeps a feed, so the hub, fetching it the moment it is told, never reads one from before.
        foreach ([$r, $same] as $answer) {
            self::assertSame(NewsController::FEED_CACHE, $answer->getHeader('Cache-Control'));
        }
        self::assertStringStartsWith('private,', NewsController::FEED_CACHE);
        self::assertStringNotContainsString('s-maxage', NewsController::FEED_CACHE);
        $this->makeArticle(['categories' => ['video']]);
        self::assertSame(200, $this->request('GET', '/news/feed.xml', [], ['If-None-Match' => $etag])->status);

        $category = $this->request('GET', '/news/video/feed.xml');
        self::assertSame('<https://pubsubhubbub.appspot.com/>; rel="hub", <' . self::BASE . '/news/video/feed.xml>; rel="self"', $category->getHeader('Link'), 'each feed is its own topic');
    }

    // ------------------------------------------------------------------------------------- follow and share

    public function testTheHubsHaveAFollowRowOfPlainLinks(): void
    {
        $this->makeArticle(['categories' => ['legal']]);
        $hub = $this->request('GET', '/news/')->body;
        $feed = rawurlencode(self::BASE . '/news/feed.xml');
        self::assertStringContainsString('<a href="/news/feed.xml" type="application/rss+xml">', $hub);
        self::assertStringContainsString('<a href="https://feedly.com/i/subscription/feed%2F' . $feed . '" target="_blank" rel="nofollow noopener">', $hub);
        self::assertStringContainsString('<a href="https://www.inoreader.com/?add_feed=' . $feed . '" target="_blank" rel="nofollow noopener">', $hub);
        self::assertStringContainsString('<a href="https://www.google.com/preferences/source?q=patternsshowcontrol.test" target="_blank" rel="nofollow noopener">', $hub);

        $legal = $this->request('GET', '/news/legal/')->body;
        self::assertStringContainsString('<a href="/news/legal/feed.xml" type="application/rss+xml">', $legal, 'a category hub follows its own feed');
        // The feed link's name is the feed's title, said once, ending with the words it shows.
        foreach (['/news/feed.xml' => [$hub, 'Industry Updates RSS feed'], '/news/legal/feed.xml' => [$legal, 'Legal: Industry Updates RSS feed']] as $href => [$body, $name]) {
            self::assertSame(1, preg_match('#<a href="' . preg_quote($href, '#') . '" type="application/rss\+xml">(.*?)</a>#s', $body, $m));
            self::assertSame($name, trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#<svg.*?</svg>#s', '', $m[1])))), $href);
        }
        self::assertStringContainsString('feed%2F' . rawurlencode(self::BASE . '/news/legal/feed.xml'), $legal);
        self::assertSame(0, preg_match('#<script[^>]+src="https?://#', $legal), 'no script from anyone else');
    }

    public function testAnArticleEndsWithShareLinksAndACopyButton(): void
    {
        $id = $this->makeArticle(['title' => 'Stage timers & cue lights get a field test at last', 'origin' => 'ai', 'categories' => ['showcalling']]);
        $path = $this->path($id);
        $r = $this->request('GET', $path);
        self::assertSame(200, $r->status);
        $url = self::BASE . $path;
        $title = 'Stage timers & cue lights get a field test at last';
        foreach ([
            'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($url),
            'https://x.com/intent/tweet?text=' . rawurlencode($title) . '&url=' . rawurlencode($url),
            'https://bsky.app/intent/compose?text=' . rawurlencode($title . ' ' . $url),
            'https://wa.me/?text=' . rawurlencode($title . ' ' . $url),
        ] as $href) {
            self::assertStringContainsString('<a href="' . e($href) . '" target="_blank" rel="nofollow noopener"><span class="u-visually-hidden">Share on </span>', $r->body);
        }
        self::assertStringContainsString('<li class="news-js-only"><button type="button" data-copy="' . e($url) . '">', $r->body);
        // In the article's order: the AI note (with the way to the standards), the share row, then More in.
        $note = strpos($r->body, 'news-ainote');
        $share = strpos($r->body, 'news-share');
        $more = strpos($r->body, 'id="news-more"');
        self::assertTrue($note !== false && $share !== false && $more !== false && $note < $share && $share < $more);
        self::assertStringContainsString('<a href="/news/standards/">How Industry Updates is made</a>', $r->body);

        $preview = (new \Psc\Controllers\NewsController($this->app))->renderArticle((array) (new ArticleStore($this->app))->find($id), true)->body;
        self::assertStringNotContainsString('news-share', $preview, 'no share row in the preview');
    }

    // ----------------------------------------------------------------------------------------- the strips

    public function testTheLatestFileHoldsTheNewestOfEachListAndIsWrittenOnlyWhenItChanges(): void
    {
        $now = time();
        $video = [];
        for ($i = 1; $i <= 4; $i++) {
            $video[$i] = $this->makeArticle(['categories' => ['video'], 'published_at' => $now - 600 * $i]);
        }
        $legal = $this->makeArticle(['categories' => ['legal', 'video'], 'published_at' => $now - 3000]);
        $this->makeArticle(['categories' => ['audio'], 'published_at' => $now + 600]);
        $this->makeArticle(['categories' => ['audio'], 'status' => 'draft']);

        self::assertTrue(Latest::refresh($this->app));
        self::assertFalse(Latest::refresh($this->app), 'unchanged, it is not written again');
        $lists = Latest::read($this->app);
        self::assertNotNull($lists);
        self::assertSame(['all', 'video', 'legal'], array_keys($lists), 'only the lists that have live articles');
        self::assertSame([$this->path($video[1]), $this->path($video[2]), $this->path($video[3])], array_column($lists['all'], 'path'));
        self::assertSame(array_column($lists['all'], 'path'), array_column($lists['video'], 'path'));
        self::assertSame([['path' => $this->path($legal), 'category' => 'legal', 'at' => $now - 3000]], array_map(static fn (array $i): array => ['path' => $i['path'], 'category' => $i['category'], 'at' => $i['at']], $lists['legal']));
        self::assertSame([$this->path($video[1]), $this->path($video[2]), $this->path($video[3])], array_column(Latest::forCategories($this->app, ['legal', 'video']), 'path'), 'each once, the newest first');

        // A damaged file shows nothing rather than something wrong.
        file_put_contents($this->storage . '/cache/news-latest.json', '{"lists":{"all":[{"title":"Elsewhere","path":"https://evil.example/","at":1,"category":"video"}]}}');
        self::assertSame([], Latest::forCategories($this->app, []));
        file_put_contents($this->storage . '/cache/news-latest.json', 'not json');
        self::assertNull(Latest::read($this->app));
    }

    public function testTheHomeAndProductPagesShowTheirStripFromTheFileAlone(): void
    {
        self::assertStringNotContainsString('news-latest', $this->request('GET', '/')->body, 'no file, no strip');
        $now = time();
        $video = $this->makeArticle(['title' => 'A switcher firmware worth testing on a mixed wall', 'categories' => ['video'], 'published_at' => $now - 100]);
        $legal = $this->makeArticle(['title' => 'A licensing change that lands on freelance crew', 'categories' => ['legal'], 'published_at' => $now - 200]);
        $cue = $this->makeArticle(['title' => 'A cue light standard that show callers can use', 'categories' => ['showcalling'], 'published_at' => $now - 300]);
        $this->makeArticle(['title' => 'An older story the strips have no room for', 'categories' => ['video'], 'published_at' => $now - 400]);
        Latest::refresh($this->app);
        $paths = array_map(fn (int $id): string => $this->path($id), [$video, $legal, $cue]);

        // The public pages read the file and nothing else: with a database that holds no tables at all they still
        // draw the strip, and stay the same for every visitor.
        $this->db = Database::connect('sqlite::memory:');
        $home = $this->request('GET', '/');
        self::assertSame(200, $home->status);
        self::assertStringStartsWith('public', (string) $home->getHeader('Cache-Control'));
        self::assertSame([], $home->cookies());
        self::assertStringContainsString('<h2 id="news-latest-title">Latest from the news desk</h2>', $home->body);
        foreach ($paths as $path) {
            self::assertStringContainsString('<h3 class="news-latest-title"><a href="' . $path . '">', $home->body);
        }
        self::assertStringNotContainsString('An older story the strips have no room for', $home->body, 'three at most');
        self::assertStringContainsString('/assets/css/pages/news-latest.css', $home->body);
        self::assertSame(1, preg_match('#<section class="l-section l-section--tight news-latest"#', $home->body));

        $patterns = $this->request('GET', '/patterns/')->body;
        self::assertStringContainsString('A switcher firmware worth testing on a mixed wall', $patterns);
        self::assertStringContainsString('An older story the strips have no room for', $patterns, 'its video, streaming and automation');
        self::assertStringNotContainsString('A licensing change that lands on freelance crew', $patterns);
        self::assertStringContainsString('<a href="/news/video/">Video news</a>', $patterns, 'the strip leads to the hubs it draws from');
        foreach (['/news/streaming/', '/news/automation/'] as $hub) {
            self::assertStringNotContainsString('<a href="' . $hub . '">', $patterns, 'never to a hub with nothing in it');
        }
        self::assertStringContainsString('The newest on video, streaming and automation, the areas Patterns works in.', $patterns);
        $agm = $this->request('GET', '/agmessage/')->body;
        self::assertStringContainsString('A licensing change that lands on freelance crew', $agm);
        self::assertStringNotContainsString('A switcher firmware worth testing on a mixed wall', $agm);
        self::assertStringContainsString('<a href="/news/legal/">Legal news</a>', $agm);
        self::assertStringNotContainsString('<a href="/news/ai/">', $agm);
        self::assertStringContainsString('The newest on production, legal and AI, the areas AGMessage works in.', $agm, 'a name in capitals keeps them');
        self::assertStringContainsString('A cue light standard that show callers can use', $this->request('GET', '/stagewatch/')->body);
        $playmap = $this->request('GET', '/playmap/')->body;
        self::assertStringContainsString('A switcher firmware worth testing on a mixed wall', $playmap, 'its interactive, video and lighting');
        self::assertStringNotContainsString('A cue light standard that show callers can use', $playmap);
    }

    public function testAnAdministratorsTakedownLeavesTheStripAtOnce(): void
    {
        $id = $this->makeArticle(['title' => 'An article an administrator takes down', 'categories' => ['video']]);
        Latest::refresh($this->app);
        self::assertStringContainsString('An article an administrator takes down', $this->request('GET', '/')->body);
        $this->signInAdmin();
        $r = $this->post('/portal/admin/news/' . $id . '/', ['action' => 'unpublish']);
        self::assertSame(303, $r->status);
        self::assertSame('draft', $this->db->value('SELECT status FROM news_articles WHERE id = ?', [$id]));
        self::assertStringNotContainsString('An article an administrator takes down', $this->request('GET', '/')->body, 'gone from the strip with the page');
    }
}
