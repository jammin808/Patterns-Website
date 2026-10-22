<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\View\Schema;

/**
 * Industry Updates' public pages: the index, the category pages, the articles, the RSS feed and the tracked links
 * out to sources. They read the same for every visitor, never start a session and never set a cookie, and are
 * cached briefly (CACHE) so a correction or a takedown leaves the CDN within minutes.
 */
final class NewsController extends Controller
{
    /** Every news page's Cache-Control. */
    public const CACHE = 'public, max-age=120, stale-while-revalidate=600';

    /** Articles on one page of a list. */
    public const PER_PAGE = 12;

    public function index(Request $request): Response
    {
        $page = Content::page('/news/') ?? ['title' => 'Industry Updates', 'description' => 'AV and live event industry news.', 'template' => 'news/index', 'section' => 'news'];
        $title = (string) $page['title'];
        $description = (string) $page['description'];
        $seo = $this->app->seo($title, $description, '/news/');
        $seo->image = isset($page['og']) ? (string) $page['og'] : null;
        $seo->imageAlt = isset($page['og_alt']) ? (string) $page['og_alt'] : null;
        $seo->breadcrumbs = Content::trail('/news/');
        $this->baseGraph($seo);
        $seo->add(Schema::webPage($this->app->baseUrl(), $seo->canonical(), $title, $description, 'CollectionPage'));
        // The page still renders, with its empty state, when the database cannot be read.
        try {
            $articles = (new ArticleStore($this->app))->live(null, null, 1, self::PER_PAGE)['items'];
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news index');
            $articles = [];
        }
        return $this->page((string) ($page['template'] ?? 'news/index'), [
            'page' => $page,
            'section' => 'news',
            'copy' => Content::get('news'),
            'categories' => Categories::all(),
            'articles' => $articles,
        ], $seo)->header('Cache-Control', self::CACHE);
    }

    public function category(Request $request): Response
    {
        return $this->notFound();
    }

    public function show(Request $request): Response
    {
        return $this->notFound();
    }

    public function feed(Request $request): Response
    {
        return $this->notFound();
    }

    public function go(Request $request): Response
    {
        return $this->notFound();
    }

    public function click(Request $request): Response
    {
        return $this->notFound();
    }
}
