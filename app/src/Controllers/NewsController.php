<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\News\Comments;
use Psc\News\Draft;
use Psc\News\Images;
use Psc\News\Markdown;
use Psc\News\Prose;
use Psc\News\Settings;
use Psc\News\Urls;
use Psc\View\Schema;

/**
 * Industry Updates' public pages: the index, the category pages, the articles, the RSS feed and the tracked links
 * out to sources. They read the same for every visitor, never start a session and never set a cookie, and are
 * cached briefly (CACHE) so a correction or a takedown leaves the CDN within minutes. A search is the one list
 * that is private and never stored. A page number past the end is a 404 and ?page=1 is the clean address; every
 * page after the first, every search and an empty category are noindex, follow (docs/NEWS.md §10).
 *
 * A click on a source counts once per link per address in CLICK_WINDOW, never from a HEAD request or a user agent
 * that names itself a robot, and only while the article is live. /news/go/{id}/ goes to the stored address only.
 */
final class NewsController extends Controller
{
    /** Every news page's Cache-Control. */
    public const CACHE = 'public, max-age=120, stale-while-revalidate=600';

    /** Articles on one page of a list. */
    public const PER_PAGE = 12;

    /** The longest search, in characters (the store searches no more). */
    public const QUERY_MAX = 100;

    /** Articles in the RSS feed, the newest first. */
    public const FEED_ITEMS = 30;

    /** A user agent that matches is never counted as a click. */
    public const BOT_AGENTS = '/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|httpclient|headless/i';

    /** One click per link per address is counted in this many seconds. */
    public const CLICK_WINDOW = 600;

    /** A change within this many seconds of going live (or of being stored) is part of publishing: no "Updated" line. */
    public const UPDATED_AFTER = 600;

    /** The share card of a news page without an image of its own. */
    public const SHARE_CARD = '/assets/img/og/news.png';

    /** The widest a search title shows of the words searched for, so the title tag stays within 65 characters. */
    private const TITLE_QUERY_MAX = 28;

    public function index(Request $request): Response
    {
        // The category form shown on phones sends ?category=; each category has an address of its own.
        if (array_key_exists('category', $request->query)) {
            $key = $request->queryString('category');
            if ($key === '') {
                return Response::redirect('/news/', 302);
            }
            return Categories::has($key) ? Response::redirect('/news/category/' . $key . '/', 302) : $this->notFound();
        }
        return $this->listing($request, null);
    }

    public function category(Request $request): Response
    {
        $key = $request->attr('key');
        return Categories::has($key) ? $this->listing($request, $key) : $this->notFound();
    }

    public function show(Request $request): Response
    {
        $article = (new ArticleStore($this->app))->findBySlug($request->attr('slug'));
        return $article !== null ? $this->renderArticle($article) : $this->notFound();
    }

    /**
     * An article as its page: the public page of a live article, or with $preview the administrator's preview of any
     * article (noindex, never cached, its images from the admin media route, no click counting and no comments).
     * $article is a row from ArticleStore::find() or findBySlug().
     *
     * @param array<string, mixed> $article
     */
    public function renderArticle(array $article, bool $preview = false): Response
    {
        $store = new ArticleStore($this->app);
        $base = $this->app->baseUrl();
        $copy = Content::get('news');
        $id = (int) $article['id'];
        $path = '/news/' . $article['slug'] . '/';
        $title = (string) $article['title'];
        $seoTitle = trim((string) ($article['seo_title'] ?? '')) ?: Prose::cut($title, 60);
        $summary = trim((string) ($article['summary'] ?? '')) ?: Prose::cut(Prose::plain((string) $article['dek']), 155);
        $aiWritten = (int) $article['ai_written'] === 1;
        $primary = Categories::get((string) $article['category']);

        $links = $store->links($id);
        $linkIds = $store->linkIds($id);
        $byKey = [];
        foreach ($links as $l) {
            $byKey[(string) $l['url_key']] ??= $l;
        }
        $sources = array_values(array_filter($links, static fn (array $l): bool => (int) $l['is_source'] === 1));
        $images = array_values(array_filter(array_map(fn (array $i): ?array => $this->picture($i, $preview), $store->images($id))));
        $lead = $images[0] ?? null;

        $byline = trim((string) ($article['byline'] ?? ''));
        if ($byline === '') {
            $byline = $aiWritten ? Draft::deskByline() : (string) ($copy['name'] ?? 'Industry Updates');
        }
        // The publisher is the author only of what he wrote himself; the desk's work and anyone else's is the site's.
        $publisher = $this->app->config->string('app.publisher');
        $authorId = !$aiWritten && $publisher !== '' && $byline === $publisher ? Schema::personId($base) : Schema::organizationId($base);

        // Updated is a change after the article went live and after it was stored (an import dated earlier is not one).
        $published = $article['published_at'] !== null ? (int) $article['published_at'] : null;
        $changed = (int) $article['updated_at'];
        $updated = $published !== null && $changed - max($published, (int) $article['created_at']) > self::UPDATED_AFTER ? $changed : null;
        $modified = $updated ?? $published ?? $changed;
        $registry = Content::page('/news/') ?? [];

        $seo = $this->app->seo($seoTitle, $summary, $path);
        $seo->ogTitle = $title;
        $seo->ogType = 'article';
        $seo->image = $lead['og'] ?? (string) ($registry['og'] ?? self::SHARE_CARD);
        $seo->imageAlt = $lead['alt'] ?? (isset($registry['og_alt']) ? (string) $registry['og_alt'] : null);
        $seo->breadcrumbs = Content::trail('/news/');
        if ($primary !== null) {
            $seo->crumb($primary['name'], '/news/category/' . $article['category'] . '/');
        }
        $seo->crumb($seoTitle, $path);
        if ($preview) {
            $seo->noindex();
        }
        $this->baseGraph($seo);
        $seo->add(Schema::webPage($base, $seo->canonical(), $seoTitle, $summary, 'WebPage'));
        $node = [
            '@type' => 'NewsArticle',
            '@id' => $seo->canonical() . '#article',
            'headline' => Prose::cut($title, 110),
            'description' => $summary,
            'datePublished' => date(DATE_ATOM, $published ?? $changed),
            'dateModified' => date(DATE_ATOM, $modified),
            'articleSection' => $primary['name'] ?? '',
            'keywords' => implode(', ', $article['tag_list']),
            'image' => $seo->absolute($seo->image),
            'wordCount' => (int) $article['word_count'],
            'author' => ['@id' => $authorId],
            'publisher' => ['@id' => Schema::organizationId($base)],
            'isBasedOn' => array_map(static fn (array $l): string => (string) $l['url'], $sources),
            'mainEntityOfPage' => ['@id' => $seo->canonical() . '#webpage'],
            'inLanguage' => 'en-GB',
            'isPartOf' => ['@id' => Schema::websiteId($base)],
        ];
        $seo->add(array_filter($node, static fn (mixed $v): bool => $v !== '' && $v !== []));

        $item = function (array $i) use ($linkIds, $base, $byKey): array {
            $url = (string) $i['url'];
            $link = $byKey[(string) Urls::key($url)] ?? null;
            return [
                'html' => Markdown::inline((string) $i['text'], $linkIds, $base),
                'url' => preg_match('#^https?://#i', $url) === 1 ? $url : '',
                'link' => $link !== null ? (int) $link['id'] : null,
                'label' => $link !== null ? (string) (($link['publisher'] ?? '') ?: $link['host']) : (string) Urls::host($url),
            ];
        };
        $response = $this->page('news/show', [
            'section' => 'news',
            'copy' => $copy,
            'article' => $article,
            'preview' => $preview,
            'live' => $store->isLive($article),
            'primary' => $primary,
            'categories' => Categories::all(),
            'byline' => $byline,
            'published' => $published,
            'updated' => $updated,
            'modified' => $modified,
            'readMinutes' => Prose::readMinutes((int) $article['word_count']),
            'body' => Markdown::render((string) $article['body'], $linkIds, $base),
            'impact' => self::demote(Markdown::render((string) ($article['impact'] ?? ''), $linkIds, $base)),
            'confirmed' => array_map($item, $article['confirmed']),
            'speculation' => array_map($item, $article['speculation']),
            'sources' => $sources,
            'related' => $store->related($id, !$preview),
            'lead' => $lead,
            'images' => array_slice($images, 1),
            'aiNote' => $aiWritten ? (string) (new Settings($this->app))->get('ai_note') : '',
            'comments' => $preview ? [] : $this->comments($id),
        ], $seo);
        return $preview ? $response : $response->header('Cache-Control', self::CACHE);
    }

    /** RSS 2.0: the newest live articles, each described by its summary and its sources through /news/go/{id}/. */
    public function feed(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $copy = Content::get('news');
        $feed = is_array($copy['feed'] ?? null) ? $copy['feed'] : [];
        $store = new ArticleStore($this->app);
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = [];
        $built = 0;
        foreach ($store->live(null, null, 1, self::FEED_ITEMS)['items'] as $a) {
            $url = $base . '/news/' . $a['slug'] . '/';
            $html = '<p>' . e((string) ($a['summary'] ?: $a['dek'])) . '</p>';
            $sources = array_values(array_filter($store->links((int) $a['id']), static fn (array $l): bool => (int) $l['is_source'] === 1));
            if ($sources !== []) {
                $html .= '<p>' . e((string) ($copy['sections']['sources'] ?? 'Sources')) . ':</p><ul>';
                foreach ($sources as $l) {
                    $label = (string) (($l['title'] ?? '') ?: $l['host']);
                    $html .= '<li><a href="' . e($base . '/news/go/' . (int) $l['id'] . '/') . '">' . e($label) . '</a>'
                        . (($l['publisher'] ?? '') !== '' && $l['publisher'] !== null ? ' (' . e((string) $l['publisher']) . ')' : '') . '</li>';
                }
                $html .= '</ul>';
            }
            $built = max($built, (int) $a['published_at'], (int) $a['updated_at']);
            $lines = [
                '    <item>',
                '      <title>' . $x((string) $a['title']) . '</title>',
                '      <link>' . $x($url) . '</link>',
                '      <guid isPermaLink="true">' . $x($url) . '</guid>',
                '      <pubDate>' . $x(date(DATE_RSS, (int) $a['published_at'])) . '</pubDate>',
            ];
            foreach ($a['categories'] as $key) {
                $lines[] = '      <category>' . $x(Categories::name((string) $key)) . '</category>';
            }
            $lines[] = '      <description>' . $x($html) . '</description>';
            $lines[] = '    </item>';
            $items[] = implode("\n", $lines);
        }
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '  <channel>',
            '    <title>' . $x((string) ($feed['title'] ?? 'Industry Updates')) . '</title>',
            '    <link>' . $x($base . '/news/') . '</link>',
            '    <description>' . $x((string) ($feed['description'] ?? '')) . '</description>',
            '    <language>en-gb</language>',
            '    <atom:link href="' . $x($base . '/news/feed.xml') . '" rel="self" type="application/rss+xml"/>',
        ];
        if ($built > 0) {
            $xml[] = '    <lastBuildDate>' . $x(date(DATE_RSS, $built)) . '</lastBuildDate>';
        }
        array_push($xml, ...$items);
        $xml[] = '  </channel>';
        $xml[] = '</rss>';
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/rss+xml')->header('Cache-Control', self::CACHE);
    }

    /** A tracked link out: to the address stored for it, never one from the request; 404 once its article is not live. */
    public function go(Request $request): Response
    {
        $link = $this->liveLink($request->attrInt('id'));
        if ($link === null) {
            return $this->notFound();
        }
        if ($request->method === 'GET') {
            $this->countClick($link, $request);
        }
        return Response::redirect((string) $link['url'], 302);
    }

    /** news.js reports a click on a source link it lets the browser follow; {"counted": bool}. */
    public function click(Request $request): Response
    {
        $link = $this->liveLink($request->attrInt('id'));
        if ($link === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['counted' => $this->countClick($link, $request)]);
    }

    /**
     * An article image as the templates draw it, by the stored naming scheme (ArticleStore::imageFiles()): its
     * src (the 960 wide copy, or the widest there is), srcset, size, alt text, caption, credit and share card, from
     * the public media route or, with $admin, the administrators' one. Null for a row that names no files.
     *
     * @param array<string, mixed> $image a news_images row
     * @return array{src: string, srcset: string, width: int, height: int, alt: string, caption: string, credit: string, credit_url: string, og: string}|null
     */
    public function picture(array $image, bool $admin = false): ?array
    {
        $listed = array_map('intval', explode(',', (string) ($image['widths'] ?? '')));
        $widths = array_values(array_intersect(ArticleStore::IMAGE_WIDTHS, $listed));
        if (ArticleStore::imageFiles($image) === [] || $widths === []) {
            return null;
        }
        $route = $admin ? '/portal/admin/news/media/' : '/news/media/';
        $file = $route . $image['base'];
        $largest = (int) ($image['width'] ?? 0);
        $srcset = null;
        if (class_exists(Images::class) && method_exists(Images::class, 'srcset')) {
            $srcset = (new Images($this->app))->srcset($image, $admin);
        }
        // Copies are never upscaled, so the widest is described by its own width.
        $srcset ??= implode(', ', array_map(static fn (int $w): string => $file . '-' . $w . '.webp ' . ($largest > 0 ? min($w, $largest) : $w) . 'w', $widths));
        $creditUrl = (string) ($image['credit_url'] ?? '');
        return [
            'src' => $file . '-' . (in_array(960, $widths, true) ? 960 : max($widths)) . '.webp',
            'srcset' => $srcset,
            'width' => max(1, $largest),
            'height' => max(1, (int) ($image['height'] ?? 0)),
            'alt' => (string) ($image['alt'] ?? ''),
            'caption' => (string) ($image['caption'] ?? ''),
            'credit' => (string) ($image['credit'] ?? ''),
            'credit_url' => preg_match('#^https?://#i', $creditUrl) === 1 ? $creditUrl : '',
            'og' => $file . '-og.jpg',
        ];
    }

    // ------------------------------------------------------------------------------------------------- internals

    /**
     * The index ($key null) or a category page: one page of live articles, the category list with its counts, the
     * search on the index, and the pager.
     */
    private function listing(Request $request, ?string $key): Response
    {
        $path = $key === null ? '/news/' : '/news/category/' . $key . '/';
        $hasPage = array_key_exists('page', $request->query);
        $raw = $request->queryString('page');
        $page = $hasPage ? (preg_match('/^[1-9]\d{0,5}$/', $raw) === 1 ? (int) $raw : 0) : 1;
        if ($page === 0) {
            return $this->notFound();
        }
        // Searches are the index's alone: the words are kept to what the store searches.
        $q = $key === null ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $request->queryString('q'))), 0, self::QUERY_MAX) : '';
        $clean = array_filter(['q' => $q, 'page' => $page > 1 ? (string) $page : ''], static fn (string $v): bool => $v !== '');
        if (($hasPage && $page === 1) || ($key === null && array_key_exists('q', $request->query) && $q === '')) {
            return Response::redirect($path . ($clean !== [] ? '?' . http_build_query($clean) : ''), 301);
        }

        $unavailable = false;
        try {
            $store = new ArticleStore($this->app);
            $result = $store->live($key, $q !== '' ? $q : null, $page, self::PER_PAGE);
            $counts = $store->categoryCounts();
            $all = $key === null && $q === '' ? $result['total'] : $store->live(null, null, 1, 1)['total'];
        } catch (\Throwable $e) {
            // The page still renders, saying the articles cannot be shown, and is not cached that way.
            $this->app->log->exception($e, 'news list');
            $result = ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
            $counts = [];
            $all = 0;
            $unavailable = true;
        }
        if ($page > $result['pages']) {
            return $this->notFound();
        }

        $copy = Content::get('news');
        $registry = Content::page('/news/') ?? [];
        $category = $key !== null ? Categories::get($key) : null;
        if ($category !== null) {
            $title = $category['title'];
            $description = $category['description'];
        } else {
            $title = (string) ($registry['title'] ?? 'Industry Updates');
            $description = (string) ($registry['description'] ?? '');
        }
        $name = (string) ($copy['name'] ?? 'Industry Updates');
        if ($q !== '') {
            $title = 'Search: “' . Prose::cut($q, self::TITLE_QUERY_MAX) . '”' . ($page > 1 ? ', page ' . $page : '') . ' | ' . $name;
            $description = (string) ($copy['search']['description'] ?? $description);
        } elseif ($page > 1) {
            $title = $category !== null ? $category['name'] . ' news, page ' . $page . ' | ' . $name : $title . ', page ' . $page;
        }

        $seo = $this->app->seo($title, $description, $path . ($clean !== [] ? '?' . http_build_query($clean) : ''));
        $seo->image = (string) ($registry['og'] ?? self::SHARE_CARD);
        $seo->imageAlt = isset($registry['og_alt']) ? (string) $registry['og_alt'] : null;
        $seo->breadcrumbs = Content::trail('/news/');
        if ($category !== null) {
            $seo->crumb($category['name'], $path);
        }
        if ($q !== '' || $page > 1 || ($category !== null && $result['total'] === 0)) {
            $seo->noindexFollow();
        }
        $this->baseGraph($seo);
        $list = [];
        foreach ($result['items'] as $a) {
            $list[] = ['name' => (string) $a['title'], 'url' => $seo->absolute('/news/' . $a['slug'] . '/')];
        }
        $listName = $category !== null
            ? strtr((string) ($copy['list']['category'] ?? 'Latest in :category'), [':category' => $category['name']])
            : (string) ($copy['list']['latest'] ?? 'Latest articles');
        $seo->add(Schema::webPage($this->app->baseUrl(), $seo->canonical(), $title, $description, 'CollectionPage', $list !== [] ? ['mainEntity' => ['@id' => $seo->canonical() . '#list']] : []));
        if ($list !== []) {
            $seo->add(Schema::itemList($seo->canonical(), $listName, $list));
        }

        $articles = [];
        foreach ($result['items'] as $a) {
            $a['picture'] = is_array($a['image'] ?? null) ? $this->picture($a['image']) : null;
            $articles[] = $a;
        }
        $response = $this->page('news/index', [
            'page' => $registry,
            'section' => 'news',
            'copy' => $copy,
            'categories' => Categories::all(),
            'category' => $key,
            'counts' => $counts,
            'all' => $all,
            'articles' => $articles,
            'lead' => $q === '' && $page === 1,
            'q' => $q,
            'pageNo' => $page,
            'pages' => $result['pages'],
            'total' => $result['total'],
            'path' => $path,
            'unavailable' => $unavailable,
        ], $seo);
        return $response->header('Cache-Control', $q !== '' || $unavailable ? 'private, no-store' : self::CACHE);
    }

    /** @return array<string, mixed>|null the link with its address, while its article is live */
    private function liveLink(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $link = $this->app->db()->one(
            "SELECT l.id, l.url FROM news_links l JOIN news_articles a ON a.id = l.article_id"
            . " WHERE l.id = ? AND a.status = 'published' AND a.published_at <= ?",
            [$id, time()]
        );
        return $link !== null && preg_match('#^https?://#i', (string) $link['url']) === 1 ? $link : null;
    }

    /**
     * Counts a click on the link, in its total and in today's row (UTC), unless the user agent is a robot or this
     * address already clicked it within CLICK_WINDOW (the limiter keeps only a keyed hash of the address). True when
     * counted. A failure is logged, never shown: the visitor still reaches the source.
     *
     * @param array<string, mixed> $link
     */
    private function countClick(array $link, Request $request): bool
    {
        $agent = $request->userAgent();
        if ($agent === '' || preg_match(self::BOT_AGENTS, $agent) === 1) {
            return false;
        }
        $id = (int) $link['id'];
        try {
            if (!$this->app->limiter()->hit('news-click:' . $id . ':' . $this->app->clientIp(), 1, self::CLICK_WINDOW)) {
                return false;
            }
            (new ArticleStore($this->app))->countClick($id, gmdate('Y-m-d'));
            return true;
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news click');
            return false;
        }
    }

    /**
     * The article's visible comments, from the comments layer once it is installed; none until then.
     *
     * @return list<array<string, mixed>>
     */
    private function comments(int $articleId): array
    {
        if (!class_exists(Comments::class) || !method_exists(Comments::class, 'visible')) {
            return [];
        }
        try {
            return array_values((new Comments($this->app))->visible($articleId));
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news comments');
            return [];
        }
    }

    /** Headings one level down (h2 becomes h3, to h5 at most), for Markdown shown under a heading of its own. */
    private static function demote(string $html): string
    {
        return (string) preg_replace_callback('#<(/?)h([2-4])>#', static fn (array $m): string => '<' . $m[1] . 'h' . ((int) $m[2] + 1) . '>', $html);
    }
}
