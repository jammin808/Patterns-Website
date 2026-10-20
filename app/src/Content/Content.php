<?php

declare(strict_types=1);

namespace Psc\Content;

/**
 * The site's words and facts as PHP data under content/: one source for pages, navigation, structured data, the
 * sitemap and the support assistant's knowledge. Files are read once per request.
 *
 *   content/site.php            brand, navigation, footer
 *   content/pages.php           the page registry (path → template, title, description, sitemap, breadcrumbs)
 *   content/products/<key>.php  one product each (patterns, stagewatch, agmessage, playmap)
 *   content/licensing/<key>.php each product's tiers and comparison matrix
 *   content/workflows.php       cross-product workflows
 *   content/faq.php             the support FAQ
 */
final class Content
{
    /** @var array<string, array<mixed>> */
    private static array $cache = [];

    public const PRODUCTS = ['patterns', 'stagewatch', 'agmessage', 'playmap'];

    private static function root(): string
    {
        return (defined('PSC_ROOT') ? (string) PSC_ROOT : dirname(__DIR__, 3)) . '/content';
    }

    /** @return array<mixed> */
    public static function get(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $name = str_replace(['..', '\\'], ['', '/'], $name);
            $file = self::root() . '/' . $name . '.php';
            $data = is_file($file) ? require $file : [];
            self::$cache[$name] = is_array($data) ? $data : [];
        }
        return self::$cache[$name];
    }

    /** @return array<string, mixed>|null */
    public static function product(string $key): ?array
    {
        if (!in_array($key, self::PRODUCTS, true)) {
            return null;
        }
        $p = self::get('products/' . $key);
        return $p === [] ? null : $p;
    }

    /** @return array<string, array<string, mixed>> key => product, in ecosystem order */
    public static function products(): array
    {
        $out = [];
        foreach (self::PRODUCTS as $key) {
            $p = self::product($key);
            if ($p !== null) {
                $out[$key] = $p;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function licensing(string $key): ?array
    {
        if (!in_array($key, self::PRODUCTS, true)) {
            return null;
        }
        $l = self::get('licensing/' . $key);
        return $l === [] ? null : $l;
    }

    /** @return array<string, array<string, mixed>> path => page */
    public static function pages(): array
    {
        /** @var array<string, array<string, mixed>> $pages */
        $pages = self::get('pages');
        return $pages;
    }

    /** @return array<string, mixed>|null */
    public static function page(string $path): ?array
    {
        return self::pages()[$path] ?? null;
    }

    /** @return list<array{name: string, path: string}> the breadcrumb trail from Home to $path */
    public static function trail(string $path): array
    {
        $pages = self::pages();
        $trail = [];
        $guard = 0;
        while ($path !== null && isset($pages[$path]) && $guard++ < 10) {
            $p = $pages[$path];
            array_unshift($trail, ['name' => (string) ($p['crumb'] ?? $p['title']), 'path' => $path]);
            $path = $p['parent'] ?? null;
        }
        return $trail;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
