<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\App;
use Psc\Config;
use Psc\Controllers\Admin\AdminNewsCommentController;
use Psc\Controllers\Admin\AdminNewsController;
use Psc\Controllers\Admin\AdminNewsDeskController;
use Psc\Controllers\NewsCommentController;
use Psc\Controllers\NewsController;
use Psc\Controllers\NewsMediaController;
use Psc\Controllers\PageController;
use Psc\Controllers\SeoController;
use Psc\News\Urls;
use Psc\Tests\TestCase;

/** What the rest of Industry Updates is built on: the route table, the index page, the share title, the database check. */
final class FoundationTest extends TestCase
{
    /** @return list<array{string, string, string, string, list<string>}> method, a path, controller, action, middleware: docs/NEWS.md §5 in order */
    private static function routes(): array
    {
        $ad = ['session', 'auth', 'admin', 'nostore'];
        $adPost = ['session', 'csrf', 'auth', 'admin', 'nostore'];
        $file = str_repeat('a1', 16) . '-960.webp';
        return [
            ['GET', '/news/', NewsController::class, 'index', []],
            ['GET', '/news/page/2/', NewsController::class, 'index', []],
            ['GET', '/news/feed.xml', NewsController::class, 'feed', []],
            ['GET', '/news/sitemap.xml', SeoController::class, 'newsSitemap', []],
            ['GET', '/news/sitemap-news.xml', SeoController::class, 'newsSitemapRecent', []],
            ['GET', '/news/sitemap-2026-10.xml', SeoController::class, 'newsSitemapMonth', []],
            ['GET', '/news/category/video/', NewsController::class, 'oldCategory', []],
            ['GET', '/news/go/12/', NewsController::class, 'go', ['nostore']],
            ['GET', '/news/media/' . $file, NewsMediaController::class, 'public', []],
            ['GET', '/news/standards/', PageController::class, 'show', []],
            ['GET', '/news/video/', NewsController::class, 'category', []],
            ['GET', '/news/video/page/2/', NewsController::class, 'category', []],
            ['GET', '/news/video/feed.xml', NewsController::class, 'feed', []],
            ['GET', '/news/video/a-story/comment/', NewsCommentController::class, 'show', ['news-address', 'session', 'auth', 'nostore']],
            ['POST', '/news/video/a-story/comment/', NewsCommentController::class, 'submit', ['news-address', 'session', 'csrf', 'auth', 'nostore']],
            ['GET', '/news/video/a-story/', NewsController::class, 'show', []],
            ['GET', '/news/a-story/comment/', NewsCommentController::class, 'show', ['news-address', 'session', 'auth', 'nostore']],
            ['POST', '/news/a-story/comment/', NewsCommentController::class, 'submit', ['news-address', 'session', 'csrf', 'auth', 'nostore']],
            ['GET', '/news/a-story/', NewsController::class, 'show', []],
            ['GET', '/api/news/12/state', NewsCommentController::class, 'state', ['api', 'session', 'nostore']],
            ['POST', '/api/news/12/comments', NewsCommentController::class, 'create', ['api', 'session', 'auth', 'nostore']],
            ['POST', '/api/news/comments/3/delete', NewsCommentController::class, 'delete', ['api', 'session', 'auth', 'nostore']],
            ['POST', '/api/news/comments/3/report', NewsCommentController::class, 'report', ['api', 'session', 'auth', 'nostore']],
            ['POST', '/api/news/links/4/click', NewsController::class, 'click', ['api', 'nostore']],
            ['GET', '/portal/admin/news/', AdminNewsController::class, 'index', $ad],
            ['GET', '/portal/admin/news/new/', AdminNewsController::class, 'create', $ad],
            ['POST', '/portal/admin/news/', AdminNewsController::class, 'store', $adPost],
            ['GET', '/portal/admin/news/12/', AdminNewsController::class, 'edit', $ad],
            ['POST', '/portal/admin/news/12/', AdminNewsController::class, 'update', $adPost],
            ['POST', '/portal/admin/news/12/images/', AdminNewsController::class, 'images', $adPost],
            ['GET', '/portal/admin/news/12/preview/', AdminNewsController::class, 'preview', $ad],
            ['GET', '/portal/admin/news/media/' . $file, NewsMediaController::class, 'admin', $ad],
            ['GET', '/portal/admin/news/comments/', AdminNewsCommentController::class, 'index', $ad],
            ['POST', '/portal/admin/news/comments/3/', AdminNewsCommentController::class, 'update', $adPost],
            ['GET', '/portal/admin/news/desk/', AdminNewsDeskController::class, 'index', $ad],
            ['POST', '/portal/admin/news/desk/', AdminNewsDeskController::class, 'control', $adPost],
            ['GET', '/portal/admin/news/desk/settings/', AdminNewsDeskController::class, 'settings', $ad],
            ['POST', '/portal/admin/news/desk/settings/', AdminNewsDeskController::class, 'saveSettings', $adPost],
            ['GET', '/portal/admin/news/desk/calendar/', AdminNewsDeskController::class, 'calendar', $ad],
            ['POST', '/portal/admin/news/desk/calendar/', AdminNewsDeskController::class, 'saveCalendar', $adPost],
            ['GET', '/portal/admin/news/desk/prompt/', AdminNewsDeskController::class, 'prompt', $ad],
            ['POST', '/portal/admin/news/desk/prompt/', AdminNewsDeskController::class, 'savePrompt', $adPost],
            ['GET', '/portal/admin/news/desk/runs/7/', AdminNewsDeskController::class, 'run', $ad],
        ];
    }

    public function testEveryNewsRouteReachesItsHandlerWithItsMiddleware(): void
    {
        foreach (self::routes() as [$method, $path, $class, $action, $middleware]) {
            $match = $this->app->router->match($method, $path);
            self::assertIsArray($match['route'] ?? null, $method . ' ' . $path);
            self::assertSame([$class, $action], $match['route']['handler'], $method . ' ' . $path);
            self::assertSame($middleware, $match['route']['middleware'], $method . ' ' . $path);
            self::assertTrue(method_exists($class, $action), $class . '::' . $action);
        }
        self::assertSame(1, count(array_keys($this->app->router->staticGetPaths(), '/news/', true)), 'the registry loop leaves /news/ to its controller');
        self::assertSame(1, count(array_keys($this->app->router->staticGetPaths(), '/news/standards/', true)), 'and the standards page to its place among the section\'s');
        $key = $this->app->router->match('GET', '/' . str_repeat('0f', 16) . '.txt');
        self::assertSame([[SeoController::class, 'indexNowKey'], []], [$key['route']['handler'] ?? null, $key['route']['middleware'] ?? null], 'the IndexNow key file, with no session');
        self::assertNull($this->app->router->match('GET', '/news/category/Video/'), 'category keys are lower case');
        self::assertNull($this->app->router->match('GET', '/news/media/' . str_repeat('a', 32) . '-960.png'));
        self::assertSame(['redirect' => '/news/a-story/'], $this->app->router->match('GET', '/news/a-story'));
        self::assertSame(['redirect' => '/news/video/a-story/'], $this->app->router->match('GET', '/news/video/a-story'));
        // The placeholders take only what they name: a category key, a page from 2 (1 and the rest are a slug's or
        // nothing's), a real month; everything else falls through to an article's slug, never to a hub.
        self::assertSame(['category' => 'video'], $this->app->router->match('GET', '/news/video/')['params'] ?? null);
        self::assertSame(['slug' => 'rumours'], $this->app->router->match('GET', '/news/rumours/')['params'] ?? null);
        self::assertSame(['category' => 'ai', 'page' => '12'], $this->app->router->match('GET', '/news/ai/page/12/')['params'] ?? null);
        self::assertSame(['category' => 'video', 'slug' => 'page'], $this->app->router->match('GET', '/news/video/page/')['params'] ?? null, 'a reserved word, which no slug is');
        self::assertNull($this->app->router->match('GET', '/news/page/02/'));
        self::assertNull($this->app->router->match('GET', '/news/sitemap-2026-13.xml'));
        self::assertNull($this->app->router->match('GET', '/news/rumours/feed.xml'));
    }

    public function testTheIndexIsTheSameForEveryVisitor(): void
    {
        $r = $this->request('GET', '/news/');
        self::assertSame(200, $r->status);
        self::assertSame('public, max-age=120, stale-while-revalidate=60', $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies());
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">Industry Updates</h1>', $r->body);
        self::assertStringContainsString('<title>Industry Updates: AV and live event news</title>', $r->body);
        self::assertStringContainsString('Nothing published yet.', $r->body, 'the empty state');
        self::assertStringContainsString('<link rel="canonical" href="https://patternsshowcontrol.test/news/">', $r->body);
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $r->body, $m));
        $types = array_column(json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'], '@type');
        self::assertSame(['Organization', 'Person', 'WebSite', 'CollectionPage', 'BreadcrumbList'], $types);

        $this->signInAdmin();
        $signedIn = $this->request('GET', '/news/');
        self::assertSame([], $signedIn->cookies(), 'no cookie, even with an administrator\'s session');
        self::assertSame('public, max-age=120, stale-while-revalidate=60', $signedIn->getHeader('Cache-Control'));
        self::assertSame(self::withoutFormTimes($r->body), self::withoutFormTimes($signedIn->body));
    }

    public function testTheIndexListsLiveArticlesOnly(): void
    {
        $this->makeArticle(['title' => 'A live story about consoles', 'slug' => 'live-story']);
        $this->makeArticle(['title' => 'A draft about a secret product', 'status' => 'draft']);
        $this->makeArticle(['title' => 'A scheduled story for tomorrow', 'published_at' => time() + 86400]);
        $body = $this->request('GET', '/news/')->body;
        self::assertStringContainsString('<a href="/news/video/live-story/">A live story about consoles</a>', $body);
        self::assertStringNotContainsString('secret product', $body);
        self::assertStringNotContainsString('scheduled story', $body);
        self::assertStringNotContainsString('Nothing published yet.', $body);
    }

    public function testTheShareTitleAndNoindexFollow(): void
    {
        $seo = $this->app->seo('Short title | Industry Updates', 'A description that is long enough for the test.', '/news/a-story/');
        $seo->ogTitle = 'The full headline of the story, which is longer than the title tag';
        $seo->add(['@type' => 'Thing']);
        $head = $seo->toHead();
        self::assertStringContainsString('<title>Short title | Industry Updates</title>', $head);
        self::assertStringContainsString('<meta property="og:title" content="The full headline of the story, which is longer than the title tag">', $head);
        self::assertStringContainsString('<meta name="twitter:title" content="The full headline of the story, which is longer than the title tag">', $head);
        self::assertStringContainsString('<meta property="og:image:alt" content="Short title | Industry Updates">', $head);

        $seo->noindexFollow();
        $head = $seo->toHead();
        self::assertSame('noindex, follow', $seo->robots);
        self::assertFalse($seo->isIndexable());
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $head);
        self::assertStringNotContainsString('rel="canonical"', $head);
        self::assertStringNotContainsString('application/ld+json', $head);
        $plain = $this->app->seo('A title', 'A description that is long enough for the test.', '/');
        self::assertStringContainsString('<meta property="og:title" content="A title">', $plain->toHead(), 'without one, the title');
    }

    /**
     * news_links.host holds any host an address can have: MySQL refuses a longer value in strict mode, and the
     * SQLite suite would never notice, so the column is read from the migration and set against Urls' own limit.
     */
    public function testTheLinkHostColumnHoldsEveryHostUrlsAccepts(): void
    {
        $mysql = (string) file_get_contents(PSC_ROOT . '/database/migrations/mysql/0004_news.sql');
        self::assertSame(1, preg_match('/CREATE TABLE news_links \((.*?)\n\) ENGINE/s', $mysql, $table));
        self::assertSame(1, preg_match('/^\s*host VARCHAR\((\d+)\) NOT NULL,$/m', $table[1], $column));
        self::assertGreaterThanOrEqual(Urls::HOST_MAX, (int) $column[1]);
        $sqlite = (string) file_get_contents(PSC_ROOT . '/database/migrations/sqlite/0004_news.sql');
        self::assertSame(1, preg_match('/CREATE TABLE news_links \((.*?)\n\);/s', $sqlite, $table));
        self::assertMatchesRegularExpression('/^\s*host TEXT NOT NULL,$/m', $table[1]);

        $longest = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 53), 'example']);
        self::assertSame($longest, Urls::host('https://' . $longest . '/x'));
        self::assertNull(Urls::host('https://' . 'e' . $longest . '/x'), 'one character more is not a host name');
    }

    /**
     * A long model call can outlast MySQL's idle timeout; dbAlive() hands back a working connection to the same
     * database. SQLite is never reconnected: the tests' database lives only in its connection.
     */
    public function testDbAliveReconnectsMysqlAndNeverSqlite(): void
    {
        $dsn = (string) getenv('PSC_TEST_DB_DSN');
        if (!str_starts_with($dsn, 'mysql:')) {
            self::assertSame($this->app->db(), $this->app->dbAlive());
            self::assertSame('1', (string) $this->app->dbAlive()->value('SELECT 1'));
            return;
        }
        $own = App::boot(Config::fromArray(PSC_ROOT, [
            'app' => ['url' => 'https://patternsshowcontrol.test', 'env' => 'testing', 'secret' => str_repeat('ab', 32)],
            'storage' => ['path' => $this->storage],
            'db' => ['dsn' => $dsn, 'user' => (string) getenv('PSC_TEST_DB_USER'), 'password' => (string) getenv('PSC_TEST_DB_PASSWORD'), 'auto_migrate' => false],
        ]));
        try {
            $first = $own->db();
            self::assertSame($first, $own->dbAlive(), 'a working connection is kept');
            $thread = (int) $first->value('SELECT CONNECTION_ID()');
            try {
                $first->exec('KILL ' . $thread);
            } catch (\PDOException $e) {
                // Killing its own connection interrupts the statement that did it.
                self::assertNotSame('', $e->getMessage());
            }
            $second = $own->dbAlive();
            self::assertNotSame($first, $second, 'a new connection');
            self::assertSame('1', (string) $second->value('SELECT 1'));
            self::assertGreaterThan(0, (int) $second->value('SELECT COUNT(*) FROM schema_migrations'), 'to the same database');
        } finally {
            $this->app = App::boot($this->app->config);
            $this->app->useDatabase($this->db);
        }
    }
}
