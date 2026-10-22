<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\Images;

/**
 * Industry Updates' article images. /news/media/{file} serves only the images of live articles, the same to
 * everyone and without a session, cached for a day by browsers and an hour by the CDN (never immutable, so an image
 * taken down leaves the CDN within the hour); anything else is a 404 that is never stored. The administrators' route
 * serves any stored image, for the editor and the preview, and is never cached. Both name the file by the stored
 * naming scheme only (Images::file()), so no request reaches another file, and both send the picture with nosniff
 * and a policy that lets it run nothing.
 */
final class NewsMediaController extends Controller
{
    public const PUBLIC_CACHE = 'public, max-age=86400, s-maxage=3600';

    public function public(Request $request): Response
    {
        $name = $request->attr('file');
        $path = (new Images($this->app))->file($name);
        if ($path === null) {
            return $this->notFound();
        }
        try {
            $live = $this->app->db()->value(
                "SELECT i.id FROM news_images i JOIN news_articles a ON a.id = i.article_id"
                . " WHERE i.base = ? AND a.status = 'published' AND a.published_at <= ?",
                [substr($name, 0, 32), time()]
            );
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news media');
            $live = null;
        }
        return $live !== null ? self::serve($path, self::PUBLIC_CACHE) : $this->notFound();
    }

    public function admin(Request $request): Response
    {
        $name = $request->attr('file');
        $path = (new Images($this->app))->file($name);
        if ($path === null || $this->app->db()->value('SELECT id FROM news_images WHERE base = ?', [substr($name, 0, 32)]) === null) {
            return $this->notFound();
        }
        return self::serve($path, 'private, no-store');
    }

    private static function serve(string $path, string $cache): Response
    {
        $response = Response::stream(static function () use ($path): void {
            readfile($path);
        }, str_ends_with($path, '.jpg') ? 'image/jpeg' : 'image/webp');
        return $response
            ->header('Content-Length', (string) filesize($path))
            ->header('Cache-Control', $cache)
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'");
    }
}
