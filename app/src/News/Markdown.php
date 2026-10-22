<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * Article Markdown as HTML, through the site's escaping md() renderer. Before it runs, the text is put in the
 * shape md() reads (headings and lists on blocks of their own), fenced code is refused (its lines stay, as plain
 * paragraphs) and headings are shifted so a body starts at h2 and never skips a level. Afterwards each external
 * link whose canonical key is registered for the article (news_links) carries data-news-link with the link's id,
 * so a click can be counted; its href stays the real address.
 *
 * A link to another article (Paths::named(): in any case, with or without its slash, relative or on this site's host)
 * is drawn when the page is, never when the text is saved: given the live articles it names ($articles, from
 * ArticleStore::liveBySlugs() over articleSlugs()), it goes to that article's own address as it is now (its comment
 * page, and its fragment, kept), and a link to an article that is not live keeps only its words.
 */
final class Markdown
{
    /**
     * @param array<string, int> $linkIds url_key => news_links.id (ArticleStore::linkIds())
     * @param array<string, array<string, mixed>>|null $articles slug => live article (ArticleStore::liveBySlugs());
     *        null leaves links to articles as written
     */
    public static function render(string $md, array $linkIds = [], string $baseUrl = '', ?array $articles = null): string
    {
        return self::articles(self::tag(md(self::prepare($md)), $linkIds, $baseUrl), $baseUrl, $articles);
    }

    /**
     * One line (a list item, a confirmed fact): inline marks and links only.
     *
     * @param array<string, int> $linkIds url_key => news_links.id
     * @param array<string, array<string, mixed>>|null $articles slug => live article, as for render()
     */
    public static function inline(string $md, array $linkIds = [], string $baseUrl = '', ?array $articles = null): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $md));
        return self::articles(self::tag(md_inline($line), $linkIds, $baseUrl), $baseUrl, $articles);
    }

    /**
     * The slugs of the articles the Markdown links to on this site (Paths::named()), each once: what render() and
     * inline() need read, in one query for a page, before they draw it.
     *
     * @return list<string>
     */
    public static function articleSlugs(string $md, string $baseUrl = ''): array
    {
        preg_match_all('/\[[^\]]+\]\(([^)\s]+)\)/u', $md, $m);
        $slugs = [];
        foreach ($m[1] as $href) {
            $named = Paths::named($href, $baseUrl);
            if ($named !== null && !in_array($named['slug'], $slugs, true)) {
                $slugs[] = $named['slug'];
            }
        }
        return $slugs;
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

    /**
     * Each link md() drew to an article on this site, at that article's own address, or its words alone when the
     * article is not among the live ones.
     *
     * @param array<string, array<string, mixed>>|null $articles
     */
    private static function articles(string $html, string $baseUrl, ?array $articles): string
    {
        if ($articles === null) {
            return $html;
        }
        // md() writes a link on this site as <a href="..."> alone: external ones carry rel and target.
        return (string) preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#s', static function (array $m) use ($baseUrl, $articles): string {
            $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $named = Paths::named($href, $baseUrl);
            if ($named === null) {
                return $m[0];
            }
            $article = $articles[$named['slug']] ?? null;
            if ($article === null) {
                return $m[2];
            }
            $fragment = (string) parse_url($href, PHP_URL_FRAGMENT);
            $to = ($named['comment'] ? Paths::comment($article) : Paths::article($article)) . ($fragment !== '' ? '#' . $fragment : '');
            return '<a href="' . e($to) . '">' . $m[2] . '</a>';
        }, $html);
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
