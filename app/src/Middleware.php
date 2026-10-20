<?php

declare(strict_types=1);

namespace Psc;

use Psc\Http\Request;
use Psc\Http\Response;

/**
 * The named middleware a route can list:
 *   session  start the session (and so mark the response private)
 *   csrf     a POST must carry the session's token (_csrf field or X-CSRF-Token header)
 *   auth     a signed-in, active account; otherwise to the login page with ?next=
 *   admin    an administrator; otherwise 403
 *   guest    signed-in visitors are sent to the dashboard (login, register)
 *   api      a same-origin fetch carrying X-Requested-With (JSON endpoints)
 *   nostore  never cached anywhere
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
                    $nextPath = $request->method === 'GET' ? $request->path : '/portal/';
                    return Response::redirect('/portal/login/?next=' . rawurlencode($nextPath));
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

            case 'nostore':
                $response = $next($request);
                $response->header('Cache-Control', 'private, no-store');
                $response->header('X-Robots-Tag', 'noindex, nofollow');
                return $response;
        }
        throw new \LogicException('Unknown middleware ' . $name);
    }
}
