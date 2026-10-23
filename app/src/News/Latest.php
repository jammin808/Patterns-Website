<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Content\Content;

/**
 * The newest Industry Updates articles for the site's other pages (docs/NEWS.md §10, "Latest from Industry Updates"):
 * a small file in the storage cache, news-latest.json, holding the PER newest live articles overall and in each
 * category, each with its headline, address, the time it went live and its primary category.
 *
 * The tick writes it (refresh()) whenever the live set has changed, so a scheduled article appears once its time has
 * passed, and the admin's article actions write it at once, so a takedown leaves the strips with the page. The home
 * and product pages only read it (read(), forCategories()): no database query and no session, so they stay the same
 * for every visitor and cacheable. A missing or unreadable file shows nothing.
 */
final class Latest
{
    /** The file, under storage. */
    public const FILE = 'cache/news-latest.json';

    /** Articles kept per list, and what each strip shows. */
    public const PER = 3;

    /**
     * Writes the file from the live articles when what it would hold differs from what it holds; true when it wrote.
     * Written to a temporary file and renamed over the old one, so a reader never sees half of it.
     */
    public static function refresh(App $app): bool
    {
        $store = new ArticleStore($app);
        $lists = ['all' => self::items($store->newest(null, self::PER))];
        foreach (Categories::KEYS as $key) {
            $items = self::items($store->newest($key, self::PER));
            if ($items !== []) {
                $lists[$key] = $items;
            }
        }
        $json = json_encode(['lists' => $lists], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = $app->storage->path(self::FILE);
        if (is_file($path) && (string) @file_get_contents($path) === $json) {
            return false;
        }
        $dir = $app->storage->dir('cache');
        $tmp = $dir . '/news-latest.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('The latest-articles file could not be written.');
        }
        return true;
    }

    /**
     * The file's lists: 'all', and each category key with live articles. Null when there is no file or it cannot be
     * read; an item that is not whole is left out.
     *
     * @return array<string, list<array{title: string, path: string, at: int, category: string}>>|null
     */
    public static function read(App $app): ?array
    {
        try {
            $path = $app->storage->path(self::FILE);
            $raw = is_file($path) ? @file_get_contents($path, false, null, 0, 262144) : false;
        } catch (\Throwable) {
            return null;
        }
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_array($data['lists'] ?? null)) {
            return null;
        }
        $out = [];
        foreach ($data['lists'] as $key => $items) {
            if (!is_string($key) || ($key !== 'all' && !Categories::has($key)) || !is_array($items)) {
                continue;
            }
            $out[$key] = array_values(array_filter(array_map(self::item(...), $items)));
        }
        return $out;
    }

    /**
     * Up to $n of the newest articles in any of $keys (each once, the newest first), or overall when $keys is empty.
     *
     * @param list<string> $keys category keys
     * @return list<array{title: string, path: string, at: int, category: string}>
     */
    public static function forCategories(App $app, array $keys, int $n = self::PER): array
    {
        $lists = self::read($app);
        if ($lists === null) {
            return [];
        }
        if ($keys === []) {
            return array_slice($lists['all'] ?? [], 0, $n);
        }
        $seen = [];
        foreach ($keys as $key) {
            foreach ($lists[$key] ?? [] as $item) {
                $seen[$item['path']] ??= $item;
            }
        }
        $items = array_values($seen);
        usort($items, static fn (array $a, array $b): int => [$b['at'], $a['path']] <=> [$a['at'], $b['path']]);
        return array_slice($items, 0, $n);
    }

    /**
     * The categories a product's strip draws from (content/news.php 'products'), the known ones only.
     *
     * @return list<string>
     */
    public static function productCategories(string $product): array
    {
        $map = Content::get('news')['products'] ?? [];
        $keys = is_array($map) && is_array($map[$product] ?? null) ? $map[$product] : [];
        return array_values(array_filter(array_map('strval', $keys), Categories::has(...)));
    }

    /**
     * @param list<array{id: int, slug: string, title: string, published_at: int, category: string}> $rows
     * @return list<array{title: string, path: string, at: int, category: string}>
     */
    private static function items(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'title' => $r['title'],
            'path' => Paths::article($r),
            'at' => $r['published_at'],
            'category' => $r['category'],
        ], $rows);
    }

    /** @return array{title: string, path: string, at: int, category: string}|null */
    private static function item(mixed $item): ?array
    {
        if (!is_array($item) || !is_string($item['title'] ?? null) || !is_string($item['path'] ?? null) || !is_int($item['at'] ?? null)) {
            return null;
        }
        // Only an address in the section, so a damaged file can never send a reader elsewhere.
        if ($item['title'] === '' || preg_match('#^/news/[a-z0-9-]+/(?:[a-z0-9-]+/)?$#', $item['path']) !== 1) {
            return null;
        }
        $category = is_string($item['category'] ?? null) && Categories::has($item['category']) ? $item['category'] : '';
        return ['title' => $item['title'], 'path' => $item['path'], 'at' => $item['at'], 'category' => $category];
    }
}
