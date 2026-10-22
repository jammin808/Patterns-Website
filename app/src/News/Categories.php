<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\Content\Content;

/**
 * The fixed Industry Updates categories, with their words from content/news.php. The keys are stored on articles
 * and are part of each category page's address, so they never change; only their words do.
 */
final class Categories
{
    public const KEYS = [
        'video', 'audio', 'lighting', 'staging', 'production', 'showcalling', 'streaming',
        'automation', 'interactive', 'ai', 'technology', 'freelance', 'legal', 'tips',
    ];

    /**
     * Every category in KEYS order: key => name, title (the title tag), description (the meta description),
     * intro and icon. A key missing from the content file still appears, named by its key.
     *
     * @return array<string, array{name: string, title: string, description: string, intro: string, icon: string}>
     */
    public static function all(): array
    {
        $content = Content::get('news')['categories'] ?? [];
        $out = [];
        foreach (self::KEYS as $key) {
            $c = is_array($content) && is_array($content[$key] ?? null) ? $content[$key] : [];
            $out[$key] = [
                'name' => (string) ($c['name'] ?? ucfirst($key)),
                'title' => (string) ($c['title'] ?? ucfirst($key) . ' | Industry Updates'),
                'description' => (string) ($c['description'] ?? ''),
                'intro' => (string) ($c['intro'] ?? ''),
                'icon' => (string) ($c['icon'] ?? 'tag'),
            ];
        }
        return $out;
    }

    public static function has(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** The category's display name ("Video"), or '' for an unknown key. */
    public static function name(string $key): string
    {
        return self::has($key) ? self::all()[$key]['name'] : '';
    }

    /**
     * One category's words, or null for an unknown key.
     *
     * @return array{name: string, title: string, description: string, intro: string, icon: string}|null
     */
    public static function get(string $key): ?array
    {
        return self::has($key) ? self::all()[$key] : null;
    }
}
