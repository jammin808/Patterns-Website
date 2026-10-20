<?php

declare(strict_types=1);

namespace Psc;

use Psc\Auth\Auth;
use Psc\Db\Database;
use Psc\Db\Migrator;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Http\Router;
use Psc\Http\Session;
use Psc\Mail\Mailer;
use Psc\Support\Logger;
use Psc\Support\RateLimiter;
use Psc\Support\Storage;
use Psc\View\Seo;
use Psc\View\View;

/**
 * The application: settings, lazily built services, the route table and the request pipeline
 * (route → middleware → handler → security and cache headers). Public pages never touch the database or the
 * session, so they render and cache even when either is down.
 */
final class App
{
    private static ?self $instance = null;

    private ?Database $db = null;
    private ?Session $session = null;
    private ?Auth $auth = null;
    private ?View $view = null;
    private ?Mailer $mailer = null;
    private ?RateLimiter $limiter = null;
    private ?Request $request = null;
    /** @var array<string, array{string, array<string, mixed>}> cookies set by services during this request */
    private array $cookieQueue = [];
    public readonly Router $router;
    public readonly Logger $log;
    public readonly Storage $storage;

    private function __construct(public readonly Config $config, public readonly string $root)
    {
        $this->storage = Storage::resolve($root, $config->get('storage.path'));
        $this->log = new Logger($this->storage->path('logs'));
        $this->router = new Router();
        date_default_timezone_set($config->string('app.timezone', 'Europe/London'));
        mb_internal_encoding('UTF-8');
        (require $root . '/app/routes.php')($this->router, $this);
    }

    public static function boot(?Config $config = null): self
    {
        $root = defined('PSC_ROOT') ? (string) PSC_ROOT : dirname(__DIR__, 2);
        $config ??= Config::load($root);
        self::$instance = new self($config, $root);
        self::$instance->installErrorHandlers();
        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('App not booted');
        }
        return self::$instance;
    }

    public function baseUrl(): string
    {
        return rtrim($this->config->string('app.url'), '/');
    }

    public function name(): string
    {
        return $this->config->string('app.name', 'Patterns Show Control');
    }

    public function db(): Database
    {
        if ($this->db === null) {
            $dsn = $this->config->string('db.dsn');
            if ($dsn === '') {
                $dsn = 'sqlite:' . $this->storage->dir('data') . '/site.sqlite';
            }
            $this->db = Database::connect($dsn, $this->config->string('db.user'), $this->config->string('db.password'));
            if ($this->config->bool('db.auto_migrate', true)) {
                (new Migrator($this->db, $this->root . '/database/migrations', $this->storage->dir('cache') . '/schema-version'))->ensure();
            }
        }
        return $this->db;
    }

    public function useDatabase(Database $db): void
    {
        $this->db = $db;
    }

    public function session(): Session
    {
        if ($this->session === null) {
            $secure = $this->request?->isHttps() ?? false;
            $this->session = new Session(
                $this->storage->dir('sessions'),
                $secure,
                $this->config->int('security.session_idle_seconds', 7200),
                $this->config->int('security.session_absolute_seconds', 43200),
            );
        }
        return $this->session;
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this);
    }

    public function view(): View
    {
        if ($this->view === null) {
            $this->view = new View($this->root . '/templates');
            $this->view->share('app', $this);
        }
        return $this->view;
    }

    public function mailer(): Mailer
    {
        return $this->mailer ??= Mailer::fromConfig($this->config, $this->storage, $this->log, $this->view());
    }

    public function useMailer(Mailer $mailer): void
    {
        $this->mailer = $mailer;
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->db(), $this->secret());
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    /**
     * Queues a cookie for the response being built (services such as Auth set cookies before a controller has
     * made its Response). Secure follows the request; expires 0 = browser session, negative = delete.
     */
    public function queueCookie(string $name, string $value, int $expires = 0, bool $httpOnly = true): void
    {
        $this->cookieQueue[$name] = [$value, [
            'expires' => $expires < 0 ? time() - 86400 : $expires,
            'path' => '/',
            'secure' => $this->request?->isHttps() ?? false,
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]];
    }

    /** The name a cookie gets: __Host- prefixed on HTTPS (bound to this host, path /, Secure). */
    public function cookieName(string $base): string
    {
        return ($this->request?->isHttps() ?? false) ? '__Host-' . $base : $base;
    }

    /** The signing secret; development falls back to a per-install random file, production refuses to run without one. */
    public function secret(): string
    {
        $secret = $this->config->string('app.secret');
        if (strlen($secret) >= 32) {
            return $secret;
        }
        if ($this->config->isProduction()) {
            throw new \RuntimeException('app.secret is not set');
        }
        $file = $this->storage->dir('cache') . '/dev-secret';
        if (!is_file($file)) {
            @file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        }
        return (string) @file_get_contents($file);
    }

    /** A keyed hash of an IP address: rate limits and logs never store the address itself. */
    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', 'ip:' . $ip, $this->secret());
    }

    public function clientIp(): string
    {
        $header = $this->config->get('app.trusted_proxy_header');
        return $this->request?->ip(is_string($header) ? $header : null) ?? '0.0.0.0';
    }

    /** A Seo object for a path with the site defaults applied. */
    public function seo(string $title, string $description, string $path): Seo
    {
        return new Seo($title, $description, $path, $this->baseUrl(), $this->name());
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $this->log->exception($e, 'request ' . $request->method . ' ' . $request->path);
            $response = $this->errorResponse($request, 500, $e);
        }
        return $this->finalise($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        if ($this->config->bool('app.force_https', true) && !$request->isHttps() && !$this->isLocalHost($request->host()) && $this->config->isProduction()) {
            return Response::redirect('https://' . $request->host() . $request->path . $this->queryString($request), 301);
        }
        $lower = strtolower($request->path);
        if ($lower !== $request->path && !str_starts_with($request->path, '/portal/') && !str_starts_with($request->path, '/api/')) {
            $again = $this->router->match('GET', $lower);
            $target = is_array($again) && isset($again['redirect']) ? $again['redirect'] : $lower;
            return Response::redirect($target . $this->queryString($request), 301);
        }
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return $this->errorResponse($request, 404);
        }
        if (isset($match['redirect'])) {
            return Response::redirect($match['redirect'] . $this->queryString($request), 301);
        }
        if (isset($match['allowed'])) {
            return $this->errorResponse($request, 405)->header('Allow', implode(', ', $match['allowed']));
        }
        $route = $match['route'];
        foreach ($match['params'] as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }
        $this->request = $request;
        $core = function (Request $req) use ($route): Response {
            return $this->invoke($route['handler'], $req);
        };
        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            fn (callable $next, string $name): callable => fn (Request $req): Response => Middleware::run($name, $this, $req, $next),
            $core
        );
        return $pipeline($request);
    }

    private function invoke(mixed $handler, Request $request): Response
    {
        if ($handler instanceof \Closure) {
            $out = $handler($request, $this);
        } elseif (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            [$class, $method] = $handler;
            $controller = new $class($this);
            $out = $controller->{$method}($request);
        } else {
            throw new \LogicException('Bad route handler');
        }
        if (!$out instanceof Response) {
            throw new \LogicException('Handler did not return a Response');
        }
        return $out;
    }

    public function errorResponse(Request $request, int $status, ?\Throwable $e = null): Response
    {
        if ($request->wantsJson() || str_starts_with($request->path, '/api/')) {
            $messages = [404 => 'not_found', 405 => 'method_not_allowed', 403 => 'forbidden', 429 => 'too_many_requests', 500 => 'server_error'];
            return Response::json(['error' => $messages[$status] ?? 'error'], $status);
        }
        try {
            $titles = [403 => 'Access denied', 404 => 'Page not found', 405 => 'Method not allowed', 419 => 'Session expired', 429 => 'Too many requests', 500 => 'Something went wrong'];
            $seo = $this->seo(($titles[$status] ?? 'Error') . ' | ' . $this->name(), 'Patterns Show Control', $request->path)->noindex();
            $template = $this->view()->exists('errors/' . $status) ? 'errors/' . $status : 'errors/error';
            $html = $this->view()->page($template, [
                'seo' => $seo,
                'status' => $status,
                'title' => $titles[$status] ?? 'Error',
                'debug' => $this->config->bool('app.debug') && $e !== null ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : null,
                'section' => null,
            ]);
            return Response::html($html, $status);
        } catch (\Throwable $inner) {
            $this->log->exception($inner, 'error page');
            return Response::html('<!doctype html><meta charset="utf-8"><title>Error</title><h1>Something went wrong</h1><p><a href="/">Return to the home page</a></p>', $status);
        }
    }

    private function finalise(Request $request, Response $response): Response
    {
        foreach ($this->cookieQueue as $name => [$value, $options]) {
            $response->cookie($name, $value, $options);
        }
        $this->cookieQueue = [];
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->header('X-Frame-Options', 'DENY');
        $response->header('Cross-Origin-Opener-Policy', 'same-origin');
        $response->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        if ($request->isHttps()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $type = $response->getHeader('Content-Type') ?? '';
        if (str_starts_with($type, 'text/html') && !$response->hasHeader('Content-Security-Policy')) {
            $response->header('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "media-src 'self'",
                "frame-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]) . ($request->isHttps() ? '; upgrade-insecure-requests' : ''));
        }
        if (!$response->hasHeader('Cache-Control')) {
            $sessionUsed = $this->session !== null && $this->session->isStarted();
            if (($request->method === 'GET' || $request->method === 'HEAD') && $response->status === 200 && !$sessionUsed && !$response->isStream()) {
                $ttl = $this->config->int('app.page_cache_seconds', 300);
                $response->header('Cache-Control', 'public, max-age=' . $ttl . ', stale-while-revalidate=86400');
            } else {
                $response->header('Cache-Control', 'private, no-store');
            }
        }
        if ($response->status >= 400 && !$response->hasHeader('X-Robots-Tag')) {
            $response->header('X-Robots-Tag', 'noindex');
        }
        return $response;
    }

    private function queryString(Request $request): string
    {
        $qs = (string) ($request->server['QUERY_STRING'] ?? '');
        return $qs !== '' ? '?' . $qs : '';
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');
    }

    private function installErrorHandlers(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $debug = $this->config->bool('app.debug');
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            $this->log->warning('php', ['severity' => $severity, 'message' => $message, 'file' => $file . ':' . $line]);
            return true;
        });
    }
}
