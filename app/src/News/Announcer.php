<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;

/**
 * Tells search engines and feed readers what changed in Industry Updates (docs/NEWS.md §6.1), once per tick, after
 * the session: every article address that went live since the last announcement (a scheduled one included, once its
 * time has passed), every one that changed since (its version is ArticleStore::lastChange(), so a correction counts),
 * and every one that went (taken down, deleted, or moved by a new primary category), with the hubs and feeds they
 * touched.
 *
 * Two channels, each with its own record in news_pings, so one failing never makes the other repeat itself:
 * - indexnow: one POST to the IndexNow endpoint (news.indexnow_endpoint), which shares it with every engine that takes
 *   part (Bing, Yandex, Seznam, Naver, Yep and others; not Google), with the site's key (Settings::indexNowKey(),
 *   served at /{key}.txt) and the addresses: the articles, the hub, their category hubs and the feeds.
 * - websub: one publish ping to the hub (news.websub_hub) naming /news/feed.xml and each touched category's feed,
 *   which the feeds declare as their hub, so a reader that subscribed hears at once instead of polling.
 *
 * An address is announced again only when it changes, so nothing is sent twice. A failure is logged and recorded,
 * nothing is marked as sent, and the channel waits before it tries again: the next tick after the first failure, then
 * twice as long each time, a day at most. Pings go out only in production, through HttpPingTransport, or through the
 * transport a test sets (useTransport()); a development server or a test never reaches the network.
 */
final class Announcer
{
    public const CHANNELS = ['indexnow', 'websub'];

    /** The articles one announcement names at most; the rest go at the next tick (IndexNow takes 10,000 addresses). */
    public const MAX_ARTICLES = 500;

    /** The wait after a first failure (the next tick, which is 15 minutes on), doubled for each failure since, to RETRY_MAX. */
    public const RETRY_FIRST = 600;
    public const RETRY_MAX = 86400;

    public const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    public const WEBSUB_HUB = 'https://pubsubhubbub.appspot.com/';

    private static ?PingTransport $transport = null;

    private readonly Settings $settings;
    private readonly ArticleStore $store;

    public function __construct(private readonly App $app)
    {
        $this->settings = new Settings($app);
        $this->store = new ArticleStore($app);
    }

    /** The transport the next pings go through; null puts the real one back (tests reset it). */
    public static function useTransport(?PingTransport $transport): void
    {
        self::$transport = $transport;
    }

    /** Whether the tick tells IndexNow (news.indexnow, on by default). */
    public static function indexNowOn(App $app): bool
    {
        return $app->config->bool('news.indexnow', true);
    }

    /** The IndexNow endpoint (news.indexnow_endpoint), an https address; '' when it is not one. */
    public static function endpoint(App $app): string
    {
        return self::https($app->config->string('news.indexnow_endpoint', self::INDEXNOW_ENDPOINT));
    }

    /** The WebSub hub the feeds declare and the tick pings (news.websub_hub); '' turns WebSub off. */
    public static function hub(App $app): string
    {
        return self::https($app->config->string('news.websub_hub', self::WEBSUB_HUB));
    }

    /**
     * One tick's announcements: each channel's status (off, idle, waiting, sent or failed), the addresses it named
     * and, when one was sent, the HTTP status that came back.
     *
     * @return array<string, array<string, mixed>>
     */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $live = null;
        $out = [];
        foreach (self::CHANNELS as $channel) {
            try {
                $out[$channel] = $this->channel($channel, $now, $live);
            } catch (\Throwable $e) {
                $this->app->log->exception($e, 'news announce ' . $channel);
                $out[$channel] = ['status' => 'failed', 'urls' => 0, 'http' => 0];
            }
        }
        return $out;
    }

    /**
     * The state a channel keeps between ticks: failures in a row, when it may try again, and what it last did.
     *
     * @return array{failures: int, retry_at: int, sent_at: int, last_at: int, last_status: string, last_http: int, last_urls: int}
     */
    public function state(string $channel): array
    {
        $value = $this->settings->internal('announce_' . $channel);
        $value = is_array($value) ? $value : [];
        return [
            'failures' => (int) ($value['failures'] ?? 0),
            'retry_at' => (int) ($value['retry_at'] ?? 0),
            'sent_at' => (int) ($value['sent_at'] ?? 0),
            'last_at' => (int) ($value['last_at'] ?? 0),
            'last_status' => (string) ($value['last_status'] ?? ''),
            'last_http' => (int) ($value['last_http'] ?? 0),
            'last_urls' => (int) ($value['last_urls'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------------------------------------- internals

    /** The transport for this server: the one a test set, else the real one in production only, else none. */
    private function transport(): ?PingTransport
    {
        if (self::$transport !== null) {
            return self::$transport;
        }
        if (!$this->app->config->isProduction()) {
            return null;
        }
        return new HttpPingTransport($this->app->name() . ' (+' . $this->app->baseUrl() . '/news/)');
    }

    /**
     * @param list<array{id: int, path: string, version: int, categories: list<string>}>|null $live read once a tick, for both channels
     * @return array<string, mixed>
     */
    private function channel(string $channel, int $now, ?array &$live): array
    {
        $on = $channel === 'indexnow' ? self::indexNowOn($this->app) && self::endpoint($this->app) !== '' : self::hub($this->app) !== '';
        if (!$on) {
            return ['status' => 'off', 'urls' => 0];
        }
        $transport = $this->transport();
        if ($transport === null) {
            return ['status' => 'off', 'reason' => 'not_production', 'urls' => 0];
        }
        $state = $this->state($channel);
        if ($state['retry_at'] > $now) {
            return ['status' => 'waiting', 'retry_at' => $state['retry_at'], 'urls' => 0];
        }
        $live ??= $this->store->liveAddresses();
        $changes = $this->changes($channel, $live, $now);
        if ($changes === []) {
            return ['status' => 'idle', 'urls' => 0];
        }
        [$hubs, $feeds] = self::touched($changes);
        $base = $this->app->baseUrl();
        if ($channel === 'indexnow') {
            $paths = [...array_keys($changes), ...$hubs, ...$feeds];
            $key = (string) $this->settings->indexNowKey();
            $body = json_encode([
                'host' => (string) parse_url($base, PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => $base . '/' . $key . '.txt',
                'urlList' => array_map(static fn (string $p): string => $base . $p, $paths),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $http = $transport->post(self::endpoint($this->app), $body, 'application/json; charset=utf-8');
            $extra = [...$hubs, ...$feeds];
        } else {
            $paths = $feeds;
            $body = 'hub.mode=publish' . implode('', array_map(static fn (string $p): string => '&hub.url=' . rawurlencode($base . $p), $feeds));
            $http = $transport->post(self::hub($this->app), $body, 'application/x-www-form-urlencoded');
            $extra = $feeds;
        }
        $count = count($paths);
        // 200 and 202 from IndexNow (202: the key is still being checked), 204 from a hub: received, nothing more.
        if ($http >= 200 && $http < 300) {
            $this->record($channel, $changes, $extra, $now);
            $this->settings->putInternal('announce_' . $channel, ['failures' => 0, 'retry_at' => 0, 'sent_at' => $now, 'last_at' => $now, 'last_status' => 'sent', 'last_http' => $http, 'last_urls' => $count]);
            $this->app->log->info('news announce', ['channel' => $channel, 'status' => 'sent', 'http' => $http, 'urls' => $count, 'articles' => count($changes)]);
            return ['status' => 'sent', 'http' => $http, 'urls' => $count];
        }
        $failures = $state['failures'] + 1;
        $retry = $now + min(self::RETRY_MAX, self::RETRY_FIRST * 2 ** min(20, $failures - 1));
        $this->settings->putInternal('announce_' . $channel, ['failures' => $failures, 'retry_at' => $retry, 'sent_at' => $state['sent_at'], 'last_at' => $now, 'last_status' => 'failed', 'last_http' => $http, 'last_urls' => $count]);
        $this->app->log->warning('news announce failed', ['channel' => $channel, 'http' => $http, 'urls' => $count, 'failures' => $failures, 'retry_at' => gmdate('c', $retry)]);
        return ['status' => 'failed', 'http' => $http, 'urls' => $count, 'retry_at' => $retry];
    }

    /**
     * What this channel has not been told yet: each live article whose address it has no record of, recorded as
     * gone, or recorded at an older version; and each address it was told was live that no live article has now
     * (taken down, deleted, or moved by a change of primary category), as gone. The oldest changes first, at most
     * MAX_ARTICLES.
     *
     * @param list<array{id: int, path: string, version: int, categories: list<string>}> $live
     * @return array<string, array{article: int, state: string, version: int, categories: list<string>}> path =>
     */
    private function changes(string $channel, array $live, int $now): array
    {
        $records = [];
        foreach ($this->app->db()->all('SELECT path, article_id, state, version FROM news_pings WHERE channel = ?', [$channel]) as $r) {
            $records[(string) $r['path']] = $r;
        }
        $changes = [];
        $here = [];
        foreach ($live as $a) {
            $here[$a['path']] = true;
            $r = $records[$a['path']] ?? null;
            if ($r === null || $r['state'] !== 'live' || (int) $r['version'] < $a['version'] || (int) ($r['article_id'] ?? 0) !== $a['id']) {
                $changes[$a['path']] = ['article' => $a['id'], 'state' => 'live', 'version' => $a['version'], 'categories' => $a['categories']];
            }
        }
        foreach ($records as $path => $r) {
            if ($r['article_id'] === null || $r['state'] !== 'live' || isset($here[$path])) {
                continue;
            }
            $at = Paths::parse($path);
            $changes[$path] = ['article' => (int) $r['article_id'], 'state' => 'gone', 'version' => $now, 'categories' => ($at['category'] ?? null) !== null ? [(string) $at['category']] : []];
        }
        uasort($changes, static fn (array $a, array $b): int => $a['version'] <=> $b['version']);
        return array_slice($changes, 0, self::MAX_ARTICLES, true);
    }

    /**
     * The hubs and feeds the changes touched: the section's own, and each category's of every article named.
     *
     * @param array<string, array{categories: list<string>}> $changes
     * @return array{0: list<string>, 1: list<string>} hub paths, feed paths
     */
    private static function touched(array $changes): array
    {
        $keys = [];
        foreach ($changes as $c) {
            foreach ($c['categories'] as $k) {
                $keys[$k] = true;
            }
        }
        $keys = array_values(array_filter(Categories::KEYS, static fn (string $k): bool => isset($keys[$k])));
        return [
            [Paths::index(), ...array_map(static fn (string $k): string => Paths::category($k), $keys)],
            [Paths::feed(), ...array_map(static fn (string $k): string => Paths::feed($k), $keys)],
        ];
    }

    /**
     * Marks what was sent, in one transaction: each article address with its state and version, and the hubs and
     * feeds with the time (no article, so they are never taken for a page that went).
     *
     * @param array<string, array{article: int, state: string, version: int}> $changes
     * @param list<string> $extra
     */
    private function record(string $channel, array $changes, array $extra, int $now): void
    {
        $rows = [];
        foreach ($changes as $path => $c) {
            $rows[$path] = ['article_id' => $c['article'], 'state' => $c['state'], 'version' => $c['version']];
        }
        foreach ($extra as $path) {
            $rows[$path] ??= ['article_id' => null, 'state' => 'live', 'version' => $now];
        }
        $this->app->db()->transaction(static function (Database $db) use ($channel, $rows, $now): void {
            foreach ($rows as $path => $row) {
                $db->exec('DELETE FROM news_pings WHERE channel = ? AND path = ?', [$channel, $path]);
                $db->insert('news_pings', ['channel' => $channel, 'path' => $path] + $row + ['pinged_at' => $now]);
            }
        });
    }

    /**
     * An https address, or '' for anything else. A host written without its path is the same address as its root
     * (RFC 3986 §6.2.3), so it is given its slash rather than turning the channel off.
     */
    private static function https(string $url): string
    {
        $url = trim($url);
        if (preg_match('#^https://[a-z0-9.-]+(?::\d+)?(/[^\s]*)?$#i', $url, $m) !== 1) {
            return '';
        }
        return ($m[1] ?? '') === '' ? $url . '/' : $url;
    }
}
