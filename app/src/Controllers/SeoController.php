<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\App;
use Psc\Chat\AnthropicGateway;
use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\Announcer;
use Psc\News\Digest\Links;
use Psc\News\ArticleStore;
use Psc\News\Images;
use Psc\News\Paths;
use Psc\News\Runs;
use Psc\News\Settings;

/**
 * sitemap.xml, robots.txt, llms.txt, the web manifest, security.txt and a health check, built from content/ and,
 * for Industry Updates, from the live articles (read in a try, so each file still answers when the database cannot,
 * then never stored, so the copy without the articles does not outlive the outage in a cache).
 *
 * The site's sitemap lists its pages, the news hub and each category hub that has a live article, never an article,
 * so it stays small however many there are. The articles have the section's own (Paths::sitemap()): an index of
 * one sitemap per London month with live articles, each listing that month's articles with their lead images, and
 * a news sitemap of the articles that went live in the last NEWS_SITEMAP_HOURS. Those answer 503 with Retry-After,
 * never stored, while the articles cannot be read.
 */
final class SeoController extends Controller
{
    /** The desk's last tick older than this, with the desk on and a key set, means its cron has stopped (docs/NEWS.md §6.1). */
    public const NEWS_STALE_SECONDS = Settings::TICK_STALE;

    /** The newest articles llms.txt lists. */
    public const LLMS_ARTICLES = 10;

    /** The site's sitemap, the section's index and its monthly sitemaps: an hour in any cache. */
    public const SITEMAP_CACHE = 'public, max-age=3600';

    /** The news sitemap changes with every article that goes live, so it is kept no longer than a news page. */
    public const NEWS_SITEMAP_CACHE = NewsController::CACHE;

    /** How far back the news sitemap reaches: the two days a news search engine reads it for. */
    public const NEWS_SITEMAP_HOURS = 48;

    public const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    public const IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';
    public const NEWS_NS = 'http://www.google.com/schemas/sitemap-news/0.9';

    public function sitemap(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $hubs = self::newsHubs($this->app);
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="' . self::SITEMAP_NS . '">'];
        foreach (Content::pages() as $path => $page) {
            if (!empty($page['noindex'])) {
                continue;
            }
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . self::x($base . $path) . '</loc>';
            // The news hub changes with every article that goes live, so its time is the newest live article's.
            if ($path === Paths::index() && $hubs !== null && $hubs !== []) {
                $since = !empty($page['updated']) ? (int) strtotime($page['updated'] . ' 00:00:00 UTC') : 0;
                $xml[] = '    <lastmod>' . self::time(max(max($hubs), $since)) . '</lastmod>';
            } elseif (!empty($page['updated'])) {
                $xml[] = '    <lastmod>' . self::x((string) $page['updated']) . '</lastmod>';
            }
            $xml[] = '  </url>';
        }
        foreach ($hubs ?? [] as $key => $modified) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . self::x($base . Paths::category($key)) . '</loc>';
            $xml[] = '    <lastmod>' . self::time($modified) . '</lastmod>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';
        // Without the hubs the map is incomplete, so it is not stored anywhere to outlive the outage.
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/xml')
            ->header('Cache-Control', $hubs !== null ? self::SITEMAP_CACHE : 'private, no-store');
    }

    /**
     * /news/sitemap.xml: a sitemap index of the month sitemaps with live articles, the newest month first, each with
     * the newest change among its articles, and the news sitemap.
     */
    public function newsSitemap(Request $request): Response
    {
        $base = $this->app->baseUrl();
        try {
            $store = new ArticleStore($this->app);
            $months = $store->months();
            $recent = $store->liveSince(time() - self::NEWS_SITEMAP_HOURS * 3600);
        } catch (\Throwable $e) {
            return $this->sitemapUnavailable($e);
        }
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<sitemapindex xmlns="' . self::SITEMAP_NS . '">'];
        foreach ($months as $month => $modified) {
            $xml[] = '  <sitemap>';
            $xml[] = '    <loc>' . self::x($base . Paths::sitemapMonth($month)) . '</loc>';
            $xml[] = '    <lastmod>' . self::time($modified) . '</lastmod>';
            $xml[] = '  </sitemap>';
        }
        $xml[] = '  <sitemap>';
        $xml[] = '    <loc>' . self::x($base . Paths::sitemapNews()) . '</loc>';
        if ($recent !== []) {
            $xml[] = '    <lastmod>' . self::time(max(array_map([ArticleStore::class, 'lastChange'], $recent))) . '</lastmod>';
        }
        $xml[] = '  </sitemap>';
        $xml[] = '</sitemapindex>';
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/xml')->header('Cache-Control', self::SITEMAP_CACHE);
    }

    /**
     * /news/sitemap-{yyyy}-{mm}.xml: the live articles that went live in that London month, the newest first, each with
     * its last change and its lead image at its widest (the image sitemap's entry). A month without any is a 404.
     */
    public function newsSitemapMonth(Request $request): Response
    {
        $base = $this->app->baseUrl();
        try {
            $articles = (new ArticleStore($this->app))->liveInMonth($request->attr('month'));
        } catch (\Throwable $e) {
            return $this->sitemapUnavailable($e);
        }
        if ($articles === []) {
            return $this->notFound();
        }
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="' . self::SITEMAP_NS . '" xmlns:image="' . self::IMAGE_NS . '">'];
        foreach ($articles as $a) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . self::x($base . Paths::article($a)) . '</loc>';
            $xml[] = '    <lastmod>' . self::time(ArticleStore::lastChange($a)) . '</lastmod>';
            $picture = is_array($a['image'] ?? null) ? (new NewsController($this->app))->picture($a['image']) : null;
            if ($picture !== null) {
                $xml[] = '    <image:image>';
                $xml[] = '      <image:loc>' . self::x($base . $picture['largest']) . '</image:loc>';
                $xml[] = '    </image:image>';
            }
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/xml')->header('Cache-Control', self::SITEMAP_CACHE);
    }

    /**
     * /news/sitemap-news.xml: a news sitemap of the live articles that went live in the last NEWS_SITEMAP_HOURS, the
     * newest first: each with the publication (the site's name, as its Organization has it), its language, the time it
     * went live and its headline.
     */
    public function newsSitemapRecent(Request $request): Response
    {
        $base = $this->app->baseUrl();
        try {
            $articles = (new ArticleStore($this->app))->liveSince(time() - self::NEWS_SITEMAP_HOURS * 3600);
        } catch (\Throwable $e) {
            return $this->sitemapUnavailable($e);
        }
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="' . self::SITEMAP_NS . '" xmlns:news="' . self::NEWS_NS . '">'];
        foreach ($articles as $a) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . self::x($base . Paths::article($a)) . '</loc>';
            $xml[] = '    <news:news>';
            $xml[] = '      <news:publication>';
            $xml[] = '        <news:name>' . self::x($this->app->name()) . '</news:name>';
            $xml[] = '        <news:language>en</news:language>';
            $xml[] = '      </news:publication>';
            $xml[] = '      <news:publication_date>' . self::time((int) $a['published_at']) . '</news:publication_date>';
            $xml[] = '      <news:title>' . self::x((string) $a['title']) . '</news:title>';
            $xml[] = '    </news:news>';
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/xml')->header('Cache-Control', self::NEWS_SITEMAP_CACHE);
    }

    /**
     * The robots tokens of crawlers that gather pages to train models, not to answer anyone: OpenAI's, Anthropic's,
     * Google's and Apple's training tokens (Google-Extended and Applebot-Extended are tokens only, never crawlers, and
     * leave Google Search and Apple's search alone, though Google-Extended also covers grounding in Gemini's apps),
     * Common Crawl's, Meta's and ByteDance's. content/site.php 'block_training' asks them to stay away.
     */
    public const TRAINING_AGENTS = ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent', 'Bytespider'];

    /**
     * The robots tokens of AI search and answer engines: the crawlers that index pages for ChatGPT search, Claude's
     * search, Perplexity and Meta AI's citations, and the fetchers that read a page because a person asked about it.
     * Welcome unless content/site.php 'ai_search' is false.
     */
    public const AI_SEARCH_AGENTS = ['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'meta-webindexer', 'meta-externalfetcher'];

    /**
     * robots.txt. Everyone is welcome but on the API and the tracked links (a crawler that matches a group of its own
     * reads only that group, so no other group is written while every crawler reads the same rules). Two of the
     * owner's switches in content/site.php each add a group that keeps its crawlers off the whole site:
     * 'block_training' for the model-training crawlers (false when the key is missing; the site ships it true, the
     * owner's choice), and 'ai_search' (true by default: welcome) for the AI search and answer engines. Search engines
     * are never in either.
     */
    public function robots(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $site = Content::get('site');
        // The portal is not disallowed: crawlers must be able to fetch it to read its noindex.
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            // The tracked way out to an article's sources; the articles link to the sources themselves.
            'Disallow: /news/go/',
            '',
        ];
        $blocked = [];
        if (!empty($site['block_training'])) {
            $blocked = self::TRAINING_AGENTS;
        }
        if (array_key_exists('ai_search', $site) && !$site['ai_search']) {
            $blocked = [...$blocked, ...self::AI_SEARCH_AGENTS];
        }
        if ($blocked !== []) {
            foreach ($blocked as $bot) {
                $lines[] = 'User-agent: ' . $bot;
            }
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        $lines[] = 'Sitemap: ' . $base . Paths::sitemap();
        return Response::text(implode("\n", $lines) . "\n")->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * /{key}.txt, the IndexNow key file (docs/NEWS.md §5): the key alone, as UTF-8 text, for the key the tick's pings
     * carry (Settings::indexNowKey()), so an engine can check they come from this site. Any other name, or none made
     * yet, or IndexNow switched off, is a 404; the key is never made here. It is public by design: it proves only that
     * a ping came from whoever controls the site.
     */
    public function indexNowKey(Request $request): Response
    {
        try {
            $key = Announcer::indexNowOn($this->app) ? (new Settings($this->app))->indexNowKey(false) : null;
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'indexnow key');
            return Response::text("The key cannot be read just now.\n", 503)
                ->header('Retry-After', (string) NewsController::RETRY_AFTER)
                ->header('Cache-Control', 'private, no-store');
        }
        if ($key === null || !hash_equals($key, $request->attr('key'))) {
            return $this->notFound();
        }
        return Response::text($key)->header('Cache-Control', 'public, max-age=3600')->header('X-Robots-Tag', 'noindex');
    }

    /** llms.txt: a plain map of the site for AI assistants and answer engines (llmstxt.org format). */
    public function llms(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $site = Content::get('site');
        $out = ['# ' . $this->app->name(), ''];
        $out[] = '> ' . (string) ($site['summary'] ?? 'Show control software for live events.');
        $out[] = '';
        if (!empty($site['llms_intro'])) {
            $out[] = (string) $site['llms_intro'];
            $out[] = '';
        }
        $out[] = '## Products';
        $out[] = '';
        foreach (Content::products() as $key => $p) {
            $out[] = '- [' . $p['name'] . '](' . $base . '/' . $key . '/): ' . ($p['summary'] ?? $p['tagline'] ?? '');
            $lic = Content::licensing($key);
            if ($lic !== null) {
                $out[] = '- [' . $p['name'] . ' licensing](' . $base . '/' . $key . '/licensing/): ' . self::licensingNote($lic);
            }
        }
        $out[] = '';
        $sections = ['Ecosystem' => ['ecosystem'], 'Workflows' => ['workflows'], 'Support' => ['support', 'downloads', 'contact'], 'About' => ['about', 'legal']];
        foreach ($sections as $title => $keys) {
            $lines = [];
            foreach (Content::pages() as $path => $page) {
                if (!empty($page['noindex']) || !in_array($page['section'] ?? '', $keys, true)) {
                    continue;
                }
                $lines[] = '- [' . ($page['crumb'] ?? $page['title']) . '](' . $base . $path . '): ' . $page['description'];
            }
            if ($lines !== []) {
                $out[] = '## ' . $title;
                $out[] = '';
                array_push($out, ...$lines);
                $out[] = '';
            }
        }
        $complete = true;
        $news = Content::page(Paths::index());
        if ($news !== null) {
            $out[] = '## ' . (string) (Content::get('news')['name'] ?? 'Industry Updates');
            $out[] = '';
            $out[] = '- [' . ($news['crumb'] ?? $news['title']) . '](' . $base . Paths::index() . '): ' . $news['description'];
            $standards = Content::page(Paths::standards());
            if ($standards !== null) {
                $out[] = '- [' . ($standards['crumb'] ?? $standards['title']) . '](' . $base . Paths::standards() . '): ' . $standards['description'];
            }
            // The weekly digest's signup page (docs/NEWS.md §14.11); its confirm and unsubscribe pages are never listed.
            $digest = Content::page(Links::SIGNUP);
            if ($digest !== null) {
                $out[] = '- [' . ($digest['crumb'] ?? $digest['title']) . '](' . $base . Links::SIGNUP . '): ' . $digest['description'];
            }
            try {
                foreach ((new ArticleStore($this->app))->live(null, null, 1, self::LLMS_ARTICLES)['items'] as $a) {
                    $out[] = '- [' . self::oneLine((string) $a['title']) . '](' . $base . Paths::article($a) . '): ' . self::oneLine((string) ($a['summary'] ?: $a['dek']));
                }
            } catch (\Throwable $e) {
                $this->app->log->exception($e, 'llms news');
                $complete = false;
            }
            $out[] = '';
        }
        // Without the articles the file is incomplete, so it is not stored anywhere to outlive the outage.
        return Response::text(implode("\n", $out))->header('Cache-Control', $complete ? 'public, max-age=3600' : 'private, no-store');
    }

    /**
     * What a product's licensing page holds, for llms.txt: its own note, or by its pricing status. Only a page with
     * prices on it is said to have prices.
     *
     * @param array<string, mixed> $licensing
     */
    public static function licensingNote(array $licensing): string
    {
        if (is_string($licensing['llms_note'] ?? null) && $licensing['llms_note'] !== '') {
            return $licensing['llms_note'];
        }
        return ($licensing['pricing_status'] ?? 'live') === 'live'
            ? 'tiers, prices and licence terms'
            : 'how licensing will work and how to register interest';
    }

    public function manifest(Request $request): Response
    {
        $data = [
            'name' => $this->app->name(),
            'short_name' => 'Patterns',
            'description' => 'Show control software for live events.',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
            // The dark theme's page colour (--bg in site.css), the same as the layout's theme-color meta.
            'background_color' => '#090C10',
            'theme_color' => '#090C10',
            'icons' => [
                ['src' => '/assets/img/brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/assets/img/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/assets/img/brand/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** Days ahead security.txt's Expires stands: always far beyond its one-day cache life, within RFC 9116's year. */
    public const SECURITY_TXT_DAYS = 180;

    /**
     * security.txt (RFC 9116). Its Contact is the contact page's https URI, which RFC 9116 allows, so the file
     * publishes no email address for crawlers to collect. It names no Policy: that field is for a vulnerability
     * disclosure policy, which the site does not publish, and a privacy notice is not one.
     */
    public function security(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $lines = [
            'Contact: ' . $base . '/contact/',
            'Expires: ' . gmdate('Y-m-d\T00:00:00\Z', time() + self::SECURITY_TXT_DAYS * 86400),
            'Preferred-Languages: en',
            'Canonical: ' . $base . '/.well-known/security.txt',
        ];
        return Response::text(implode("\n", $lines) . "\n")->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * A health check for uptime monitors: booleans only, nothing sensitive. The site is healthy only when every
     * part it is configured to use works: storage and the database always (reachable and holding every migration
     * this release ships), the assistant's SDK once the assistant is switched on with a key. config_shadowed is
     * true when a config/local.php sits in the web root unread, because the configuration outside it wins (a
     * change uploaded there has no effect).
     */
    public function health(Request $request): Response
    {
        $cfg = $this->app->config;
        $checks = [
            'storage' => is_writable($this->app->storage->root()),
            'vendor' => AnthropicGateway::available(),
        ];
        try {
            $this->app->db()->value('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'health');
            $checks['database'] = false;
        }
        $checks['config_shadowed'] = $cfg->shadowed();
        $checks['schema'] = $checks['database'] && $this->app->migrator()->isCurrent();
        $ok = self::healthy($checks, $cfg->bool('chat.enabled', true) && $cfg->string('chat.api_key') !== '');
        // The AI desk and image support are reported, never failed: the site serves its pages either way.
        return Response::json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'news' => self::newsState($this->app, $checks['schema']),
            'images' => self::imagesSupported($this->app),
            'build' => self::build(),
        ], $ok ? 200 : 503)->header('X-Robots-Tag', 'noindex');
    }

    /**
     * The AI desk as an uptime monitor sees it: off while it has no key or is paused, ok while the cron is ticking as
     * the desk page judges it (Settings::cronState(): the last tick under NEWS_STALE_SECONDS old, or a session still
     * inside its time), and stale otherwise (the cron line has stopped, or the database cannot say).
     */
    public static function newsState(App $app, bool $database): string
    {
        if (Settings::apiKey($app) === '') {
            return 'off';
        }
        if (!$database) {
            return 'stale';
        }
        try {
            $settings = new Settings($app);
            if ($settings->get('enabled') !== true) {
                return 'off';
            }
            return $settings->cronState((new Runs($app))->running()) === 'ok' ? 'ok' : 'stale';
        } catch (\Throwable $e) {
            $app->log->exception($e, 'health news');
            return 'stale';
        }
    }

    /**
     * Whether article images can be stored: the Images class's own answer once it is installed, otherwise the same
     * test (GD's WebP and JPEG writers and getimagesize).
     */
    public static function imagesSupported(App $app): bool
    {
        if (class_exists(Images::class) && method_exists(Images::class, 'supported')) {
            $method = new \ReflectionMethod(Images::class, 'supported');
            return (bool) ($method->isStatic() ? $method->invoke(null) : $method->invoke(new Images($app)));
        }
        return function_exists('imagewebp') && function_exists('imagejpeg') && function_exists('getimagesize');
    }

    /**
     * The category hubs the site's sitemap adds: each category with a live article, with the newest last change among
     * them (ArticleStore::lastChange()). Null when the database cannot be read, which is not the same as no live
     * articles ([]).
     *
     * @return array<string, int>|null key => Unix time
     */
    public static function newsHubs(App $app): ?array
    {
        try {
            return (new ArticleStore($app))->categoryTimes();
        } catch (\Throwable $e) {
            $app->log->exception($e, 'sitemap news');
            return null;
        }
    }

    /** A news sitemap while the articles cannot be read: 503, to be asked again, never stored and never indexed. */
    private function sitemapUnavailable(\Throwable $e): Response
    {
        $this->app->log->exception($e, 'news sitemap');
        return Response::text("The articles cannot be read just now.\n", 503)
            ->header('Retry-After', (string) NewsController::RETRY_AFTER)
            ->header('Cache-Control', 'private, no-store');
    }

    /** Text for an XML element or attribute. */
    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A W3C date and time in UTC, as sitemaps give them. */
    private static function time(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /** Text on one line, for a list item in llms.txt. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The build this server runs: the VERSION file the release build writes (the commit it was built from), or "dev"
     * in a checkout. The deploy job waits for it before flushing the CDN, so the cache never refills with the old site.
     */
    public static function build(): string
    {
        $file = PSC_ROOT . '/VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file, false, null, 0, 64)) : '';
        return preg_match('/^[A-Za-z0-9._-]{1,40}$/', $version) === 1 ? $version : 'dev';
    }

    /**
     * Whether the checks add up to a working site. A shadowed configuration file is reported, not failed: the site
     * runs on the configuration it found.
     *
     * @param array{storage: bool, vendor: bool, database: bool, schema?: bool} $checks
     */
    public static function healthy(array $checks, bool $assistantConfigured): bool
    {
        return $checks['storage'] && $checks['database'] && ($checks['schema'] ?? true)
            && (!$assistantConfigured || $checks['vendor']);
    }
}
