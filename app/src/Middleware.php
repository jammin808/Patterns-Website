<?php

declare(strict_types=1);

namespace Psc;

use Psc\Controllers\NewsController;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Uploads;

/**
 * The named middleware a route can list:
 *   session  start the session (and so mark the response private)
 *   csrf     a POST must carry the session's token (_csrf field or X-CSRF-Token header)
 *   auth     a signed-in, active account; otherwise to the login page with ?next=
 *   admin    an administrator; otherwise 403
 *   guest    signed-in visitors are sent to the dashboard (login, register)
 *   api      a same-origin fetch carrying X-Requested-With (JSON endpoints)
 *   nostore  never cached anywhere; noindex unless the handler chose otherwise
 *   news-address  a live article's comment page asked for at an address it does not have (another category, or
 *            /news/{slug}/): moved for good to its own (NewsController::elsewhere()), before any session starts
 */
final class Middleware
{
    /** @param callable(Request): Response $next */
    public static function run(string $name, App $app, Request $request, callable $next): Response
    {
        switch ($name) {
            case 'session':
                $app->session()->start();
                return $next($request);

            case 'csrf':
                if ($request->method === 'POST') {
                    // A body over post_max_size arrives with no fields and no files at all, so its token is missing
                    // too: say the upload was too large, not that the session expired.
                    $limit = Uploads::iniBytes((string) ini_get('post_max_size'));
                    if ($limit > 0 && (int) $request->header('content-length', '0') > $limit && $request->post === [] && $request->files === []) {
                        $app->log->info('post too large', ['path' => $request->path, 'bytes' => (int) $request->header('content-length', '0')]);
                        $app->session()->flash('danger', self::tooLargeMessage($request->path, $limit));
                        return Response::redirect(self::backTo($request), 303);
                    }
                    $token = $request->input('_csrf') ?: $request->header('x-csrf-token');
                    if (!$app->session()->validCsrf($token)) {
                        $app->log->info('csrf rejected', ['path' => $request->path]);
                        return $app->errorResponse($request, 419);
                    }
                }
                return $next($request);

            case 'auth':
                $user = $app->auth()->user();
                if ($user === null) {
                    if ($request->wantsJson()) {
                        return Response::json(['error' => 'unauthenticated'], 401);
                    }
                    return Response::redirect('/portal/login/?next=' . rawurlencode(self::comeBackTo($request)));
                }
                return $next($request);

            case 'admin':
                $user = $app->auth()->user();
                if ($user === null || ($user['role'] ?? '') !== 'admin') {
                    return $app->errorResponse($request, 403);
                }
                return $next($request);

            case 'guest':
                if ($app->auth()->user() !== null) {
                    return Response::redirect('/portal/');
                }
                return $next($request);

            case 'api':
                if (!$request->isSameOrigin($request->host()) || $request->header('x-requested-with') !== 'fetch') {
                    return Response::json(['error' => 'forbidden'], 403);
                }
                return $next($request);

            case 'news-address':
                return NewsController::elsewhere($app, $request) ?? $next($request);

            case 'nostore':
                $response = $next($request);
                // Private and never stored, keeping any stricter directives the handler set (the assistant's stream
                // adds no-transform, so no proxy or CDN compresses or buffers it).
                $cache = (string) $response->getHeader('Cache-Control');
                if (!str_contains($cache, 'no-store')) {
                    $cache = 'private, no-store';
                } elseif (!str_contains($cache, 'private')) {
                    $cache = 'private, ' . $cache;
                }
                $response->header('Cache-Control', $cache);
                // Not indexed, unless the handler chose otherwise (the contact page is a form, and indexable).
                if (!$response->hasHeader('X-Robots-Tag')) {
                    $response->header('X-Robots-Tag', 'noindex, nofollow');
                }
                return $response;
        }
        throw new \LogicException('Unknown middleware ' . $name);
    }

    /** What a visitor is told when what they sent was larger than the server accepts in one request. */
    public static function tooLargeMessage(string $path, int $limit): string
    {
        $size = filesize_human($limit);
        if (str_starts_with($path, '/portal/admin/releases/')) {
            return "That file is larger than the server accepts in one upload ({$size}), so nothing was saved. Upload it to the downloads-vault/incoming/ folder with the File Manager or FTP, then pick it from the list.";
        }
        return "That upload was larger than the server accepts ({$size} in total), so nothing was sent. Choose a smaller file and try again.";
    }

    /** The page a refused POST goes back to: the form it came from on this site, or the dashboard. */
    private static function backTo(Request $request): string
    {
        $referer = $request->header('referer');
        if ($referer !== '' && strtolower((string) parse_url($referer, PHP_URL_HOST)) === $request->host()) {
            $path = (string) parse_url($referer, PHP_URL_PATH);
            if (str_starts_with($path, '/') && !preg_match(App::UNSAFE_PATH, $path) && !str_starts_with($path, '//')) {
                return $path;
            }
        }
        return '/portal/';
    }

    /**
     * Where sign-in returns to: a GET's path with its query (so a link such as the licence request for one product
     * and tier keeps its choice), anything else to the dashboard. The login controller still passes it through
     * safeNext(), which allows only same-site relative paths.
     */
    private static function comeBackTo(Request $request): string
    {
        if ($request->method !== 'GET') {
            return '/portal/';
        }
        $query = http_build_query(array_filter($request->query, 'is_scalar'), '', '&', PHP_QUERY_RFC3986);
        $target = $request->path . ($query !== '' ? '?' . $query : '');
        return strlen($target) <= 512 ? $target : $request->path;
    }
}
