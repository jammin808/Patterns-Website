<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * Article Markdown as HTML, through the site's escaping md() renderer. Before it runs, the text is put in the
 * shape md() reads (headings and lists on blocks of their own), fenced code is refused (its lines stay, as plain
 * paragraphs) and headings are shifted so a body starts at h2 and never skips a level. Afterwards each external
 * link whose canonical key is registered for the article (news_links) carries data-news-link with the link's id,
 * so a click can be counted; its href stays the real address.
 */
final class Markdown
{
    /**
     * @param array<string, int> $linkIds url_key => news_links.id (ArticleStore::linkIds())
     */
    public static function render(string $md, array $linkIds = [], string $baseUrl = ''): string
    {
        return self::tag(md(self::prepare($md)), $linkIds, $baseUrl);
    }

    /**
     * One line (a list item, a confirmed fact): inline marks and links only.
     *
     * @param array<string, int> $linkIds url_key => news_links.id
     */
    public static function inline(string $md, array $linkIds = [], string $baseUrl = ''): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $md));
        return self::tag(md_inline($line), $linkIds, $baseUrl);
    }

    /** The text md() is given: blank lines around headings and lists, no code fences, headings from ## down. */
    public static function prepare(string $md): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($md)));
        $headings = [];
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\h+\S/', $line, $m)) {
                $headings[] = strlen($m[1]);
            }
        }
        $top = $headings !== [] ? min($headings) : 2;
        $out = [];
        $prevKind = 'blank';
        $prevLevel = 1;
        foreach ($lines as $line) {
            if (preg_match('/^\h*```/', $line)) {
                continue;
            }
            $kind = self::kind($line);
            if ($kind === 'heading') {
                preg_match('/^(#{1,6})\h+(.*)$/', $line, $m);
                $level = min(strlen($m[1]) - $top + 2, $prevLevel + 1, 4);
                $line = str_repeat('#', $level) . ' ' . trim($m[2]);
                $prevLevel = $level;
            }
            if ($kind !== 'blank' && $prevKind !== 'blank' && ($kind === 'heading' || $prevKind === 'heading' || $kind !== $prevKind)) {
                $out[] = '';
            }
            $out[] = $line;
            $prevKind = $kind;
        }
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }

    private static function kind(string $line): string
    {
        return match (true) {
            trim($line) === '' => 'blank',
            (bool) preg_match('/^#{1,6}\h+\S/', $line) => 'heading',
            (bool) preg_match('/^\h*\d+\.\h+\S/', $line) => 'ordered',
            (bool) preg_match('/^\h*[-*]\h+\S/', $line) => 'bullets',
            default => 'text',
        };
    }

    /** @param array<string, int> $linkIds */
    private static function tag(string $html, array $linkIds, string $baseUrl): string
    {
        if ($linkIds === []) {
            return $html;
        }
        return (string) preg_replace_callback('#<a href="([^"]*)"( rel="nofollow noopener" target="_blank")>#', static function (array $m) use ($linkIds, $baseUrl): string {
            $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if ($baseUrl !== '' && Urls::isOwn($href, $baseUrl)) {
                return $m[0];
            }
            $key = Urls::key($href);
            if ($key === null || !isset($linkIds[$key])) {
                return $m[0];
            }
            return '<a href="' . $m[1] . '"' . $m[2] . ' data-news-link="' . (int) $linkIds[$key] . '">';
        }, $html);
    }
}
