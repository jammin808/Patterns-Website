<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\App;
use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\News\Comments;
use Psc\News\Draft;
use Psc\News\Images;
use Psc\News\Markdown;
use Psc\News\Paths;
use Psc\News\Prose;
use Psc\News\Settings;
use Psc\News\Urls;
use Psc\View\Schema;

/**
 * Industry Updates' public pages, one silo (Psc\News\Paths): the hub (/news/) and each category's hub, their pages
 * (/news/page/2/, /news/{key}/page/2/), the articles under their primary category, the RSS feeds and the tracked
 * links out to sources. They read the same for every visitor, never start a session and never set a cookie, and are
 * cached briefly (CACHE) so a correction or a takedown leaves the CDN within minutes. Every page of a list has an
 * address of its own and names itself as canonical, so each is indexed; ?page=N and page 1's own path answer 301
 * to the clean address, and a page number past the end is a 404 wherever it is asked for, never a move to one. A
 * search is the one list paged by its query, private, never stored and never indexed, as is an empty category
 * (docs/NEWS.md §10). A list whose articles cannot be read is a 503 with Retry-After, never stored and never indexed.
 * Every move keeps the rest of the query (utm_ tags survive the hop); only a page number moves into the path.
 *
 * An article is found by its slug alone, so asked for under another category, or as /news/{slug}/, it answers 301
 * to its own address (elsewhere()); a deleted article's slug answers 410, noindex (ArticleStore::isRetired()), and
 * any other unknown slug a plain 404. The old category address (/news/category/{key}/) answers 301 to the
 * category's hub. Its links to other articles are drawn at their addresses of the day (Markdown::render()).
 *
 * A click on a source counts once per link per address in CLICK_WINDOW, never from a HEAD request or a user agent
 * that names itself a robot, and only while the article is live. /news/go/{id}/ goes to the stored address only, and
 * always goes there, but counts only a visit (isVisit()): never an image or a script that names it on another page.
 */
final class NewsController extends Controller
{
    /**
     * Every news page's Cache-Control: a CDN may serve a page for up to about 3 minutes after a change, its max-age
     * and then, served stale, its stale-while-revalidate (TAKEDOWN).
     */
    public const CACHE = 'public, max-age=120, stale-while-revalidate=60';

    /** Articles on one page of a list. */
    public const PER_PAGE = 12;

    /** The longest search, in characters (the store searches no more). */
    public const QUERY_MAX = 100;

    /** Articles in an RSS feed, the newest first. */
    public const FEED_ITEMS = 30;

    /** The newest articles of its primary category an article ends with ("More in"). */
    public const MORE_IN = 4;

    /** A user agent that matches is never counted as a click. */
    public const BOT_AGENTS = '/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|httpclient|headless/i';

    /** One click per link per address is counted in this many seconds. */
    public const CLICK_WINDOW = 600;

    /** Seconds a list that could not be read asks to be fetched again after (with its 503). */
    public const RETRY_AFTER = 120;

    /** The share card of the hub, and of a page with no card of its own. */
    public const SHARE_CARD = '/assets/img/og/news.png';

    /** The longest title tag a news page has; an article's gets the section's name only within it. */
    public const TITLE_MAX = 60;

    /** The widest a search title shows of the words searched for, so the title tag stays within 65 characters. */
    private const TITLE_QUERY_MAX = 28;

    public function index(Request $request): Response
    {
        // The category form shown on phones sends ?category=; each category has an address of its own.
        if (array_key_exists('category', $request->query)) {
            $key = $request->queryString('category');
            if ($key === '') {
                return Response::redirect(Paths::index(), 302);
            }
            return Categories::has($key) ? Response::redirect(Paths::category($key), 302) : $this->notFound();
        }
        return $this->listing($request, null);
    }

    public function category(Request $request): Response
    {
        $key = $request->attr('category');
        return Categories::has($key) ? $this->listing($request, $key) : $this->notFound();
    }

    /** /news/category/{key}/, the old address of a category: moved for good to its hub (and a valid ?page= to its page). */
    public function oldCategory(Request $request): Response
    {
        $key = $request->attr('key');
        if (!Categories::has($key)) {
            return $this->notFound();
        }
        $raw = $request->queryString('page');
        $page = preg_match('/^[1-9]\d{0,5}$/', $raw) === 1 ? (int) $raw : 1;
        return $this->hasPage($key, '', $page) ? self::moved(Paths::category($key, $page), $request, ['page']) : $this->notFound();
    }

    public function show(Request $request): Response
    {
        $store = new ArticleStore($this->app);
        $article = $store->findBySlug($request->attr('slug'));
        if ($article === null) {
            return $store->isRetired($request->attr('slug')) ? $this->app->errorResponse($request, 410) : $this->notFound();
        }
        $at = Paths::article($article);
        return $request->path === $at ? $this->renderArticle($article) : Response::redirect($at . self::query($request), 301);
    }

    /**
     * A live article's page asked for at an address it does not have (another category, or the lasting /news/{slug}/):
     * the answer that moves the visitor to its own, 301 (308 for a POST, so a form posted from a page drawn before
     * its category changed is sent again, not lost). A deleted article's slug answers 410. Null when the address is its
     * own or names no live article; the route answers then. The news-address middleware asks it for the comment pages,
     * before any session starts.
     */
    public static function elsewhere(App $app, Request $request): ?Response
    {
        $store = new ArticleStore($app);
        $article = $store->findBySlug($request->attr('slug'));
        if ($article === null) {
            return $store->isRetired($request->attr('slug')) ? $app->errorResponse($request, 410) : null;
        }
        $at = str_ends_with($request->path, '/comment/') ? Paths::comment($article) : Paths::article($article);
        return $request->path === $at ? null : Response::redirect($at . self::query($request), $request->method === 'POST' ? 308 : 301);
    }

    /**
     * An article as its page: the public page of a live article, or with $preview the administrator's preview of any
     * article (noindex, never cached, its images from the admin media route, no click counting and no comments).
     * $article is a row from ArticleStore::find() or findBySlug().
     *
     * Its head: the SEO title as the title tag (with " | Industry Updates" only while the whole fits TITLE_MAX), the
     * summary as the description, the headline as the share title, the lead image's 1200 x 630 share card (else its
     * category's), the article: times, section and tags, and a NewsArticle on the site's graph beside its WebPage and
     * the breadcrumbs Home, Industry Updates, its category, itself. It ends with the newest of its category.
     *
     * @param array<string, mixed> $article
     */
    public function renderArticle(array $article, bool $preview = false): Response
    {
        $store = new ArticleStore($this->app);
        $base = $this->app->baseUrl();
        $copy = Content::get('news');
        $name = (string) ($copy['name'] ?? 'Industry Updates');
        $id = (int) $article['id'];
        $path = Paths::article($article);
        $title = (string) $article['title'];
        $seoTitle = trim((string) ($article['seo_title'] ?? '')) ?: Prose::cut($title, 60);
        $titleTag = mb_strlen($seoTitle . ' | ' . $name) <= self::TITLE_MAX ? $seoTitle . ' | ' . $name : $seoTitle;
        $summary = trim((string) ($article['summary'] ?? '')) ?: Prose::cut(Prose::plain((string) $article['dek']), 155);
        $aiWritten = (int) $article['ai_written'] === 1;
        $primaryKey = (string) $article['category'];
        $primary = Categories::get($primaryKey);

        $links = $store->links($id);
        $linkIds = $store->linkIds($id);
        $byKey = [];
        foreach ($links as $l) {
            $byKey[(string) $l['url_key']] ??= $l;
        }
        $sources = array_values(array_filter($links, static fn (array $l): bool => (int) $l['is_source'] === 1));
        // Its links to other articles go where each lives now, or keep only their words once it is not live: one
        // query for every such link on the page.
        $texts = [(string) $article['body'], (string) ($article['impact'] ?? ''), ...array_column($article['confirmed'], 'text'), ...array_column($article['speculation'], 'text')];
        $articles = $store->liveBySlugs(Markdown::articleSlugs(implode("\n", $texts), $base));
        $images = array_values(array_filter(array_map(fn (array $i): ?array => $this->picture($i, $preview), $store->images($id))));
        $lead = $images[0] ?? null;

        $byline = trim((string) ($article['byline'] ?? ''));
        if ($byline === '') {
            $byline = $aiWritten ? Draft::deskByline() : $name;
        }
        // The publisher is the author only of what he wrote himself; the desk's work and anyone else's is the site's.
        $publisher = $this->app->config->string('app.publisher');
        $authorId = !$aiWritten && $publisher !== '' && $byline === $publisher ? Schema::personId($base) : Schema::organizationId($base);

        // Updated is a change after the article went live and after it was stored (an import dated earlier is not one).
        $published = $article['published_at'] !== null ? (int) $article['published_at'] : null;
        $updated = ArticleStore::updatedAfterLive($article);
        $modified = ArticleStore::lastChange($article);
        $registry = Content::page(Paths::index()) ?? [];

        $seo = $this->app->seo($titleTag, $summary, $path);
        $seo->ogTitle = $title;
        $seo->ogType = 'article';
        if ($lead !== null) {
            $seo->image = $lead['og'];
            $seo->imageAlt = $lead['alt'];
        } elseif ($primary !== null) {
            $seo->image = Categories::shareCard($primaryKey);
            $seo->imageAlt = Categories::headline($primaryKey) . ', from ' . $name;
        } else {
            $seo->image = (string) ($registry['og'] ?? self::SHARE_CARD);
            $seo->imageAlt = isset($registry['og_alt']) ? (string) $registry['og_alt'] : null;
        }
        $seo->breadcrumbs = Content::trail(Paths::index());
        if ($primary !== null) {
            $seo->crumb($primary['name'], Paths::category($primaryKey));
        }
        $seo->crumb($seoTitle, $path);
        if ($preview) {
            $seo->noindex();
        }
        $this->baseGraph($seo);
        $seo->add(Schema::webPage($base, $seo->canonical(), $seoTitle, $summary, 'WebPage'));
        // The share card (the lead image's 1200 x 630 crop, or its category's), then the lead image at its widest.
        $pictures = [$seo->absolute((string) $seo->image)];
        if ($lead !== null && $lead['largest'] !== '') {
            $pictures[] = $seo->absolute($lead['largest']);
        }
        $node = [
            '@type' => 'NewsArticle',
            '@id' => $seo->canonical() . '#article',
            'headline' => Prose::cut($title, 110),
            'description' => $summary,
            'datePublished' => date(DATE_ATOM, $published ?? (int) $article['updated_at']),
            'dateModified' => date(DATE_ATOM, $modified),
            'articleSection' => $primary['name'] ?? '',
            'keywords' => implode(', ', $article['tag_list']),
            'image' => $pictures,
            'wordCount' => (int) $article['word_count'],
            'author' => ['@id' => $authorId],
            'publisher' => ['@id' => Schema::organizationId($base)],
            'isBasedOn' => array_map(static fn (array $l): string => (string) $l['url'], $sources),
            'isAccessibleForFree' => true,
            'mainEntityOfPage' => ['@id' => $seo->canonical() . '#webpage'],
            'inLanguage' => 'en-GB',
            'isPartOf' => ['@id' => Schema::websiteId($base)],
        ];
        $seo->add(array_filter($node, static fn (mixed $v): bool => $v !== '' && $v !== []));

        $item = function (array $i) use ($linkIds, $base, $byKey, $articles): array {
            $url = (string) $i['url'];
            $link = $byKey[(string) Urls::key($url)] ?? null;
            return [
                'html' => Markdown::inline((string) $i['text'], $linkIds, $base, $articles),
                'url' => preg_match('#^https?://#i', $url) === 1 ? $url : '',
                'link' => $link !== null ? (int) $link['id'] : null,
                'label' => $link !== null ? (string) (($link['publisher'] ?? '') ?: $link['host']) : (string) Urls::host($url),
            ];
        };
        $feeds = [[Paths::feed(), (string) ($copy['feed']['label'] ?? 'Industry Updates RSS feed')]];
        if ($primary !== null) {
            $feeds[] = [Paths::feed($primaryKey), strtr((string) ($copy['feed']['category_label'] ?? ':category: Industry Updates RSS feed'), [':category' => $primary['name']])];
        }
        $response = $this->page('news/show', [
            'section' => 'news',
            'copy' => $copy,
            'article' => $article,
            'preview' => $preview,
            'live' => $store->isLive($article),
            'primary' => $primary,
            'primaryKey' => $primaryKey,
            'categories' => Categories::all(),
            'byline' => $byline,
            'published' => $published,
            'updated' => $updated,
            'modified' => $modified,
            'readMinutes' => Prose::readMinutes((int) $article['word_count']),
            'body' => Markdown::render((string) $article['body'], $linkIds, $base, $articles),
            'impact' => self::demote(Markdown::render((string) ($article['impact'] ?? ''), $linkIds, $base, $articles)),
            'confirmed' => array_map($item, $article['confirmed']),
            'speculation' => array_map($item, $article['speculation']),
            'sources' => $sources,
            'related' => $store->related($id, !$preview),
            'more' => $primary !== null ? $store->moreIn($primaryKey, $id, self::MORE_IN) : [],
            'lead' => $lead,
            'images' => array_slice($images, 1),
            'aiNote' => $aiWritten ? (string) (new Settings($this->app))->get('ai_note') : '',
            'comments' => $preview ? [] : $this->comments($id),
            'feeds' => $feeds,
        ], $seo);
        return $preview ? $response : $response->header('Cache-Control', self::CACHE);
    }

    /**
     * RSS 2.0: the newest live articles, every category's at /news/feed.xml and one category's at /news/{key}/feed.xml,
     * each described by its summary and its sources through the tracked links. An item's link is the article's own
     * address; its guid is the lasting /news/{slug}/, so a change of category never shows a reader the article twice.
     */
    public function feed(Request $request): Response
    {
        $key = $request->attr('category');
        $category = $key !== '' ? Categories::get($key) : null;
        if ($key !== '' && $category === null) {
            return $this->notFound();
        }
        $base = $this->app->baseUrl();
        $copy = Content::get('news');
        $feed = is_array($copy['feed'] ?? null) ? $copy['feed'] : [];
        $store = new ArticleStore($this->app);
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $items = [];
        $built = 0;
        foreach ($store->live($category !== null ? $key : null, null, 1, self::FEED_ITEMS)['items'] as $a) {
            $html = '<p>' . e((string) ($a['summary'] ?: $a['dek'])) . '</p>';
            $sources = array_values(array_filter($store->links((int) $a['id']), static fn (array $l): bool => (int) $l['is_source'] === 1));
            if ($sources !== []) {
                $html .= '<p>' . e((string) ($copy['sections']['sources'] ?? 'Sources')) . ':</p><ul>';
                foreach ($sources as $l) {
                    $label = (string) (($l['title'] ?? '') ?: $l['host']);
                    $html .= '<li><a href="' . e($base . Paths::go((int) $l['id'])) . '">' . e($label) . '</a>'
                        . (($l['publisher'] ?? '') !== '' && $l['publisher'] !== null ? ' (' . e((string) $l['publisher']) . ')' : '') . '</li>';
                }
                $html .= '</ul>';
            }
            $built = max($built, (int) $a['published_at'], (int) $a['updated_at']);
            $lines = [
                '    <item>',
                '      <title>' . $x((string) $a['title']) . '</title>',
                '      <link>' . $x($base . Paths::article($a)) . '</link>',
                '      <guid isPermaLink="true">' . $x($base . Paths::permalink($a)) . '</guid>',
                '      <pubDate>' . $x(date(DATE_RSS, (int) $a['published_at'])) . '</pubDate>',
            ];
            foreach ($a['categories'] as $k) {
                $lines[] = '      <category>' . $x(Categories::name((string) $k)) . '</category>';
            }
            $lines[] = '      <description>' . $x($html) . '</description>';
            $lines[] = '    </item>';
            $items[] = implode("\n", $lines);
        }
        $title = (string) ($feed['title'] ?? 'Industry Updates');
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '  <channel>',
            '    <title>' . $x($category !== null ? strtr((string) ($feed['category_title'] ?? ':title: :category'), [':title' => $title, ':category' => $category['name']]) : $title) . '</title>',
            '    <link>' . $x($base . ($category !== null ? Paths::category($key) : Paths::index())) . '</link>',
            '    <description>' . $x($category !== null ? $category['description'] : (string) ($feed['description'] ?? '')) . '</description>',
            '    <language>en-gb</language>',
            '    <atom:link href="' . $x($base . Paths::feed($category !== null ? $key : null)) . '" rel="self" type="application/rss+xml"/>',
        ];
        if ($built > 0) {
            $xml[] = '    <lastBuildDate>' . $x(date(DATE_RSS, $built)) . '</lastBuildDate>';
        }
        array_push($xml, ...$items);
        $xml[] = '  </channel>';
        $xml[] = '</rss>';
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/rss+xml')->header('Cache-Control', self::CACHE);
    }

    /**
     * A tracked link out: to the address stored for it, never one from the request; 404 once its article is not live.
     * Counted only for a visit (isVisit()), and sent on whatever it is: a reader's click from another site (a feed
     * reader's) counts like one from the page.
     */
    public function go(Request $request): Response
    {
        $link = $this->liveLink($request->attrInt('id'));
        if ($link === null) {
            return $this->notFound();
        }
        if ($request->method === 'GET' && self::isVisit($request)) {
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
     * the public media route or, with $admin, the administrators' one, and its widest copy (largest). Null for a row
     * that names no files.
     *
     * @param array<string, mixed> $image a news_images row
     * @return array{src: string, srcset: string, width: int, height: int, alt: string, caption: string, credit: string, credit_url: string, og: string, largest: string}|null
     */
    public function picture(array $image, bool $admin = false): ?array
    {
        $listed = array_map('intval', explode(',', (string) ($image['widths'] ?? '')));
        $widths = array_values(array_intersect(ArticleStore::IMAGE_WIDTHS, $listed));
        if (ArticleStore::imageFiles($image) === [] || $widths === []) {
            return null;
        }
        $file = Paths::media((string) $image['base'], $admin);
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
            'largest' => $file . '-' . max($widths) . '.webp',
        ];
    }

    // ------------------------------------------------------------------------------------------------- internals

    /**
     * The hub ($key null) or a category's hub, at one of its pages: its live articles, the category bar with its
     * counts, the search on the hub, the pager, the way across the silo (the hub and the other categories that have
     * articles), the feeds. A page's number is in its path; a search's is in its query, beside its words.
     */
    private function listing(Request $request, ?string $key): Response
    {
        $q = $key === null ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $request->queryString('q'))), 0, self::QUERY_MAX) : '';
        $atPage = $request->attr('page') !== '' ? (int) $request->attr('page') : null;
        $hasPage = array_key_exists('page', $request->query);
        $raw = $request->queryString('page');
        $asked = $hasPage ? (preg_match('/^[1-9]\d{0,5}$/', $raw) === 1 ? (int) $raw : 0) : null;
        $listPath = static fn (int $n): string => $key === null ? Paths::index($n) : Paths::category($key, $n);
        $searchPath = static fn (int $n): string => Paths::index() . '?' . http_build_query(['q' => $q] + ($n > 1 ? ['page' => (string) $n] : []));
        if ($asked === 0) {
            return $this->notFound();
        }
        // A move goes only to a page there is, with the rest of the query.
        if ($q !== '') {
            // A search pages by its query, its page 1 without a number.
            if ($atPage !== null || $asked === 1) {
                $to = $asked ?? $atPage ?? 1;
                return $this->hasPage($key, $q, $to) ? self::moved($searchPath($to), $request, ['q', 'page']) : $this->notFound();
            }
            $page = $asked ?? 1;
        } else {
            // Every other page of a list has a path of its own, page 1 the list's own; an empty search is no search.
            if ($asked !== null || $atPage === 1 || ($key === null && array_key_exists('q', $request->query))) {
                $to = $asked ?? $atPage ?? 1;
                return $this->hasPage($key, '', $to) ? self::moved($listPath($to), $request, ['q', 'page']) : $this->notFound();
            }
            $page = $atPage ?? 1;
        }
        $path = $q !== '' ? $searchPath($page) : $listPath($page);

        $unavailable = false;
        try {
            $store = new ArticleStore($this->app);
            $result = $store->live($key, $q !== '' ? $q : null, $page, self::PER_PAGE);
            $counts = $store->categoryCounts();
            $all = $key === null && $q === '' ? $result['total'] : $store->live(null, null, 1, 1)['total'];
        } catch (\Throwable $e) {
            // The page still renders, saying the articles cannot be shown, as a 503 that is neither cached nor
            // indexed, so a crawler retries it instead of recording an empty list (or a missing page).
            $this->app->log->exception($e, 'news list');
            $result = ['items' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
            $counts = [];
            $all = 0;
            $unavailable = true;
        }
        if (!$unavailable && $page > $result['pages']) {
            return $this->notFound();
        }

        $copy = Content::get('news');
        $registry = Content::page(Paths::index()) ?? [];
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
            // Each page names itself, so no two pages of a list share a title or a description.
            $title = $category !== null ? $category['name'] . ' news, page ' . $page . ' | ' . $name : $title . ', page ' . $page;
            $description = Prose::cut('Page ' . $page . ': ' . $description, 155);
        }

        $seo = $this->app->seo($title, $description, $path);
        if ($category !== null) {
            $seo->image = Categories::shareCard((string) $key);
            $seo->imageAlt = Categories::headline((string) $key) . ', from ' . $name;
        } else {
            $seo->image = (string) ($registry['og'] ?? self::SHARE_CARD);
            $seo->imageAlt = isset($registry['og_alt']) ? (string) $registry['og_alt'] : null;
        }
        $seo->breadcrumbs = Content::trail(Paths::index());
        if ($category !== null) {
            $seo->crumb($category['name'], Paths::category((string) $key));
        }
        if ($page > 1 && $q === '') {
            $seo->crumb('Page ' . $page, $path);
        }
        if ($unavailable || $q !== '' || ($category !== null && $result['total'] === 0)) {
            $seo->noindexFollow();
        }
        $this->baseGraph($seo);
        $list = [];
        foreach ($result['items'] as $a) {
            $list[] = ['name' => (string) $a['title'], 'url' => $seo->absolute(Paths::article($a))];
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
        $feeds = [];
        if ($category !== null) {
            $feeds[] = [Paths::feed($key), strtr((string) ($copy['feed']['category_label'] ?? ':category: Industry Updates RSS feed'), [':category' => $category['name']])];
        }
        $feeds[] = [Paths::feed(), (string) ($copy['feed']['label'] ?? 'Industry Updates RSS feed')];
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
            'pageLink' => $q !== '' ? $searchPath : $listPath,
            'unavailable' => $unavailable,
            'feeds' => $feeds,
        ], $seo, 'layouts/base', $unavailable ? 503 : 200);
        if ($unavailable) {
            $response->header('Retry-After', (string) self::RETRY_AFTER);
        }
        return $response->header('Cache-Control', $q !== '' || $unavailable ? 'private, no-store' : self::CACHE);
    }

    /**
     * Whether page $n of a list (a category's or the hub's, or a search's) exists: what a move to it asks first, so a
     * move never leads to a 404. A list that cannot be read is let through, for its own page to say so (503).
     */
    private function hasPage(?string $key, string $q, int $n): bool
    {
        if ($n <= 1) {
            return true;
        }
        try {
            $total = (new ArticleStore($this->app))->live($key, $q !== '' ? $q : null, 1, 1)['total'];
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news list');
            return true;
        }
        return $n <= max(1, (int) ceil($total / self::PER_PAGE));
    }

    /**
     * Whether the browser says a request is a person following a link: Sec-Fetch-Dest document and Sec-Fetch-Mode
     * navigate, or neither sent (an older browser, a feed reader). An image, a script, a frame or a fetch that names
     * the address on another page says otherwise.
     */
    private static function isVisit(Request $request): bool
    {
        $dest = strtolower($request->header('sec-fetch-dest'));
        $mode = strtolower($request->header('sec-fetch-mode'));
        return ($dest === '' || $dest === 'document') && ($mode === '' || $mode === 'navigate');
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

    /**
     * The request's query string, with its question mark, for an address that moves (utm_ tags survive the hop),
     * without the parameters $without names; each pair kept is as it was sent.
     *
     * @param list<string> $without
     */
    private static function query(Request $request, array $without = []): string
    {
        $qs = (string) ($request->server['QUERY_STRING'] ?? '');
        if ($without !== []) {
            $qs = implode('&', array_filter(explode('&', $qs), static fn (string $pair): bool => $pair !== ''
                && !in_array((string) preg_replace('/\[.*$/s', '', urldecode(explode('=', $pair, 2)[0])), $without, true)));
        }
        return $qs !== '' ? '?' . $qs : '';
    }

    /**
     * A list's move for good: to $to (which may hold a query of its own), with the request's query but for $without
     * (a page number the path now holds, a search the address now holds, or one that was empty).
     *
     * @param list<string> $without
     */
    private static function moved(string $to, Request $request, array $without): Response
    {
        $rest = self::query($request, $without);
        return Response::redirect($to . ($rest !== '' && str_contains($to, '?') ? '&' . substr($rest, 1) : $rest), 301);
    }

    /** Headings one level down (h2 becomes h3, to h5 at most), for Markdown shown under a heading of its own. */
    private static function demote(string $html): string
    {
        return (string) preg_replace_callback('#<(/?)h([2-4])>#', static fn (array $m): string => '<' . $m[1] . 'h' . ((int) $m[2] + 1) . '>', $html);
    }
}
