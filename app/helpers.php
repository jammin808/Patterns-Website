<?php

declare(strict_types=1);

/*
 * Template helpers. Every value a template prints goes through e() unless it is markup the site itself built.
 */

use Psc\App;
use Psc\Content\Content;

if (!function_exists('e')) {
    /** HTML-escapes a value for text or a quoted attribute. */
    function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A public asset's URL with a content version, so it can be cached for a year. */
    function asset(string $path): string
    {
        static $versions = [];
        $path = '/' . ltrim($path, '/');
        if (!isset($versions[$path])) {
            $file = PSC_ROOT . '/public' . $path;
            $versions[$path] = is_file($file) ? substr(hash('crc32b', (string) filemtime($file) . ':' . (string) filesize($file)), 0, 8) : '0';
        }
        return $path . '?v=' . $versions[$path];
    }

    /** An absolute URL on this site. */
    function url(string $path = '/'): string
    {
        return App::get()->baseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * An icon from the sprite the layout inlines once per page (docs/DESIGN.md 8.30): decorative unless a label is
     * given. $class adds modifiers such as 'c-icon--sm'.
     */
    function icon(string $name, string $class = '', ?string $label = null): string
    {
        $aria = $label !== null ? 'role="img" aria-label="' . e($label) . '"' : 'aria-hidden="true" focusable="false"';
        return '<svg class="c-icon' . ($class !== '' ? ' ' . e($class) : '') . '" ' . $aria . '><use href="#i-' . e($name) . '"/></svg>';
    }

    /** The hidden CSRF field for a form (starts the session). */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(App::get()->session()->csrfToken()) . '">';
    }

    function csrf_token(): string
    {
        return App::get()->session()->csrfToken();
    }

    /**
     * A content file's data (content/<name>.php).
     *
     * @return array<mixed>
     */
    function content(string $name): array
    {
        return Content::get($name);
    }

    /**
     * A product's data, or null.
     *
     * @return array<string, mixed>|null
     */
    function product(string $key): ?array
    {
        return Content::product($key);
    }

    /** ' aria-current="page"' when $path is the current page, ' aria-current="true"' when inside its section. */
    function current_attr(string $path): string
    {
        $req = App::get()->request();
        $now = $req?->path ?? '/';
        if ($now === $path) {
            return ' aria-current="page"';
        }
        if ($path !== '/' && str_starts_with($now, $path)) {
            return ' aria-current="true"';
        }
        return '';
    }

    /** A price for display: "£29", or the fallback when not yet announced. */
    function price(?string $amount, string $fallback = 'Coming soon'): string
    {
        if ($amount === null || $amount === '') {
            return $fallback;
        }
        if (!is_numeric($amount)) {
            return $amount;
        }
        $n = (float) $amount;
        return '£' . (floor($n) == $n ? number_format($n, 0) : number_format($n, 2));
    }

    /** Short date: "20 Oct 2026". */
    function date_short(int|string|null $ts): string
    {
        if ($ts === null || $ts === '' || (int) $ts === 0) {
            return '';
        }
        return date('j M Y', (int) $ts);
    }

    /** Relative time for portal lists: "3 hours ago", falling back to the date after a week. */
    function ago(int|string|null $ts): string
    {
        if ($ts === null || (int) $ts === 0) {
            return '';
        }
        $d = time() - (int) $ts;
        return match (true) {
            $d < 60 => 'just now',
            $d < 3600 => intdiv($d, 60) . ' min ago',
            $d < 86400 => intdiv($d, 3600) . ' hour' . (intdiv($d, 3600) === 1 ? '' : 's') . ' ago',
            $d < 604800 => intdiv($d, 86400) . ' day' . (intdiv($d, 86400) === 1 ? '' : 's') . ' ago',
            default => date_short($ts),
        };
    }

    /** Human file size. */
    function filesize_human(int|string $bytes): string
    {
        $b = (float) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($b < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? (string) (int) $b : number_format($b, 1)) . ' ' . $unit;
            }
            $b /= 1024;
        }
        return (string) $bytes;
    }

    /**
     * Renders the site's small, safe Markdown subset (paragraphs, **bold**, *italic*, `code`, links, lists,
     * headings ###) from untrusted text: everything is escaped first, then the subset is re-applied. Links are
     * kept only for http(s) and site-relative URLs.
     */
    function md(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $blocks = preg_split("/\n{2,}/", $text) ?: [];
        $html = [];
        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            if (array_reduce($lines, static fn (bool $c, string $l): bool => $c && (bool) preg_match('/^\s*([-*]|\d+\.)\s+/', $l), true)) {
                $ordered = (bool) preg_match('/^\s*\d+\./', $lines[0]);
                $items = array_map(static fn (string $l): string => '<li>' . md_inline((string) preg_replace('/^\s*([-*]|\d+\.)\s+/', '', $l)) . '</li>', $lines);
                $html[] = ($ordered ? '<ol>' : '<ul>') . implode('', $items) . ($ordered ? '</ol>' : '</ul>');
            } elseif (preg_match('/^(#{2,4})\s+(.*)$/', $lines[0], $m) && count($lines) === 1) {
                $level = strlen($m[1]);
                $html[] = "<h{$level}>" . md_inline($m[2]) . "</h{$level}>";
            } elseif (str_starts_with($block, '```')) {
                $code = (string) preg_replace('/^```[a-z0-9]*\n?|```$/i', '', $block);
                $html[] = '<pre><code>' . e(trim($code, "\n")) . '</code></pre>';
            } else {
                $html[] = '<p>' . implode('<br>', array_map('md_inline', $lines)) . '</p>';
            }
        }
        return implode("\n", $html);
    }

    function md_inline(string $line): string
    {
        $s = e($line);
        $s = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        $s = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
        $s = (string) preg_replace('/(?<![*\w])\*([^*]+)\*(?!\w)/', '<em>$1</em>', $s);
        $s = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|/(?![/\\\\]))#', $href) || str_contains($href, '\\')) {
                return $m[1];
            }
            $external = str_starts_with($href, 'http') && !str_starts_with($href, App::get()->baseUrl());
            return '<a href="' . e($href) . '"' . ($external ? ' rel="nofollow noopener" target="_blank"' : '') . '>' . $m[1] . '</a>';
        }, $s);
        return $s;
    }
}
