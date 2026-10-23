<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * Every Industry Updates address, made in one place (docs/NEWS.md §5), so the pages, the feeds, the sitemaps, the
 * emails, the desk's prompts, the validator, the admin pages and the tests all name a page the same way. The section
 * is one silo: /news/ is its hub, each category's /news/{key}/ is a hub of its own, and an article lives under its
 * primary category at /news/{key}/{slug}/. Lists page by path (/news/page/2/, /news/{key}/page/2/), so every page of a
 * list has an address of its own.
 *
 * A slug is unique across the site and never changes once its article has been live, so an article is found by its
 * slug alone: any other category in its path, or none (/news/{slug}/, the lasting address the feeds use as an id),
 * answers 301 to where its primary category puts it. Slugs are lower case ASCII words joined by hyphens, at most
 * SLUG_MAX characters, cut at a word, with no stop word at either end, and never a word the routes use (RESERVED) or
 * a category key: those get a number. All static and pure.
 */
final class Paths
{
    /** The section's hub. */
    public const ROOT = '/news/';

    /** The longest slug, its number (-2, -3 ...) included. */
    public const SLUG_MAX = 60;

    /** Words a slug never starts or ends with. */
    public const STOP_WORDS = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'at', 'by', 'is', 'are', 'from'];

    /**
     * Path segments the routes use under /news/ or under a category (pages, the comment page, the old category
     * pages, the tracked links, the media, the feeds, the sitemaps and the editorial standards page): never a slug,
     * and never a category key.
     */
    public const RESERVED = ['page', 'comment', 'category', 'go', 'media', 'feed', 'sitemap', 'standards'];

    /** /news/, or /news/page/{n}/ from the second page on. */
    public static function index(int $page = 1): string
    {
        return self::ROOT . ($page > 1 ? 'page/' . $page . '/' : '');
    }

    /** A category's hub, /news/{key}/, or /news/{key}/page/{n}/ from its second page on. */
    public static function category(string $key, int $page = 1): string
    {
        return self::ROOT . $key . '/' . ($page > 1 ? 'page/' . $page . '/' : '');
    }

    /**
     * An article's address: /news/{its primary category}/{slug}/. An article without a known category (a draft that
     * has none yet) has only its lasting address, /news/{slug}/.
     *
     * @param array<string, mixed> $row an article row with slug and category (the primary, as ArticleStore gives it)
     */
    public static function article(array $row): string
    {
        $category = (string) ($row['category'] ?? '');
        return Categories::has($category) ? self::ROOT . $category . '/' . $row['slug'] . '/' : self::permalink($row);
    }

    /**
     * The address an article keeps whatever its category: /news/{slug}/, which answers 301 to article(). The feeds
     * use it as each item's id, so a change of category never shows an article twice.
     *
     * @param array<string, mixed> $row
     */
    public static function permalink(array $row): string
    {
        return self::ROOT . $row['slug'] . '/';
    }

    /**
     * The comment page of an article, for readers without scripting: article() . 'comment/'.
     *
     * @param array<string, mixed> $row
     */
    public static function comment(array $row): string
    {
        return self::article($row) . 'comment/';
    }

    /** The RSS feed: every category's at /news/feed.xml, one category's at /news/{key}/feed.xml. */
    public static function feed(?string $key = null): string
    {
        return self::ROOT . ($key !== null && $key !== '' ? $key . '/' : '') . 'feed.xml';
    }

    /** The editorial standards page: how the section is made, who answers for it and how it is corrected. */
    public static function standards(): string
    {
        return self::ROOT . 'standards/';
    }

    /** The tracked way out to a source, which counts the click and goes to the stored address. */
    public static function go(int $linkId): string
    {
        return self::ROOT . 'go/' . $linkId . '/';
    }

    /** An article image's file, from the public media route or, with $admin, the administrators'. */
    public static function media(string $file, bool $admin = false): string
    {
        return ($admin ? '/portal/admin/news/media/' : self::ROOT . 'media/') . $file;
    }

    /** The section's sitemap index: one sitemap per London month with live articles, and the news sitemap. */
    public static function sitemap(): string
    {
        return self::ROOT . 'sitemap.xml';
    }

    /** One London month's sitemap; $month is YYYY-MM. */
    public static function sitemapMonth(string $month): string
    {
        return self::ROOT . 'sitemap-' . $month . '.xml';
    }

    /** The news sitemap: the articles that went live in the last two days. */
    public static function sitemapNews(): string
    {
        return self::ROOT . 'sitemap-news.xml';
    }

    /** The category keys as one alternation, for a route's {category} placeholder. */
    public static function categoryPattern(): string
    {
        return implode('|', Categories::KEYS);
    }

    /** Whether $slug is a word the routes use or a category key, which no article may have. */
    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true) || Categories::has($slug);
    }

    /**
     * The article an address on this site names: /news/{key}/{slug}/, /news/{slug}/ (the lasting address), or either's
     * comment page. Null for anything else, a hub, a list page or a reserved word among them.
     *
     * @return array{category: string|null, slug: string, comment: bool}|null
     */
    public static function parse(string $path): ?array
    {
        if (preg_match('#^/news/(?:(' . self::categoryPattern() . ')/)?([a-z0-9-]+)/(comment/)?$#', $path, $m) !== 1 || self::isReserved($m[2])) {
            return null;
        }
        return ['category' => $m[1] !== '' ? $m[1] : null, 'slug' => $m[2], 'comment' => ($m[3] ?? '') !== ''];
    }

    /**
     * The article a link on this site names (parse()), read as a person types it: the path in any case and with or
     * without its last slash (/news/Some-Story is /news/some-story/), from a site-relative address or one on
     * $baseUrl's host, its query and fragment aside. Null for an address elsewhere or one that names no article.
     *
     * @return array{category: string|null, slug: string, comment: bool}|null
     */
    public static function named(string $href, string $baseUrl = ''): ?array
    {
        if (!Urls::isOwn($href, $baseUrl)) {
            return null;
        }
        $path = strtolower((string) parse_url(trim($href), PHP_URL_PATH));
        if ($path !== '' && !str_ends_with($path, '/') && !str_contains((string) strrchr($path, '/'), '.')) {
            $path .= '/';
        }
        return self::parse($path);
    }

    /**
     * A slug from a title: ASCII words in lower case joined by hyphens (Prose::slug()), without the stop words at
     * either end, at most SLUG_MAX characters cut at a word. Empty when nothing is left.
     */
    public static function slug(string $text): string
    {
        $words = array_values(array_filter(explode('-', Prose::slug($text, 1000)), static fn (string $w): bool => $w !== ''));
        $words = self::trimStopWords($words);
        $out = [];
        foreach ($words as $word) {
            if (strlen(implode('-', [...$out, $word])) > self::SLUG_MAX) {
                break;
            }
            $out[] = $word;
        }
        if ($out === [] && $words !== []) {
            return substr($words[0], 0, self::SLUG_MAX);
        }
        return implode('-', self::trimStopWords($out));
    }

    /**
     * The $n-th address for a base slug: the base itself, then base-2, base-3 ..., the base cut at a word (and its
     * stop words trimmed again) so the number still fits within SLUG_MAX.
     */
    public static function numbered(string $base, int $n): string
    {
        if ($n <= 1) {
            return $base;
        }
        $suffix = '-' . $n;
        $room = self::SLUG_MAX - strlen($suffix);
        if (strlen($base) > $room) {
            $cut = substr($base, 0, $room + 1);
            $at = strrpos($cut, '-');
            $base = $at !== false && $at > 0 ? substr($cut, 0, $at) : substr($base, 0, $room);
            $base = implode('-', self::trimStopWords(explode('-', trim($base, '-')))) ?: substr($base, 0, $room);
        }
        return $base . $suffix;
    }

    /** Whether $slug is one of the addresses numbered() makes for $base (the base itself or a numbered one). */
    public static function isNumberedFrom(string $slug, string $base): bool
    {
        if ($slug === $base) {
            return true;
        }
        return preg_match('/-([1-9]\d{0,5})$/', $slug, $m) === 1 && self::numbered($base, (int) $m[1]) === $slug;
    }

    /**
     * @param list<string> $words
     * @return list<string>
     */
    private static function trimStopWords(array $words): array
    {
        while ($words !== [] && in_array($words[0], self::STOP_WORDS, true)) {
            array_shift($words);
        }
        while ($words !== [] && in_array($words[count($words) - 1], self::STOP_WORDS, true)) {
            array_pop($words);
        }
        return array_values($words);
    }
}
