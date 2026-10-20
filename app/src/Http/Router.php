<?php

declare(strict_types=1);

namespace Psc\Http;

/**
 * Maps a method and path to a handler. Patterns use {name} or {name:regex} placeholders. Page paths end with a
 * slash; a GET without the slash that would match is answered with a permanent redirect to the slashed path.
 */
final class Router
{
    /** @var list<array{methods: list<string>, pattern: string, regex: string, handler: mixed, middleware: list<string>, name: ?string}> */
    private array $routes = [];

    /** @param list<string> $middleware */
    public function get(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler, $middleware, $name);
    }

    /** @param list<string> $middleware */
    public function post(string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->add(['POST'], $pattern, $handler, $middleware, $name);
    }

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     */
    public function add(array $methods, string $pattern, mixed $handler, array $middleware = [], ?string $name = null): void
    {
        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $pattern,
            'regex' => self::compile($pattern),
            'handler' => $handler,
            'middleware' => $middleware,
            'name' => $name,
        ];
    }

    /**
     * @return array{route: array{methods: list<string>, pattern: string, regex: string, handler: mixed, middleware: list<string>, name: ?string}, params: array<string, string>}|array{redirect: string}|array{allowed: list<string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            return ['route' => $route, 'params' => $params];
        }
        if ($allowed !== []) {
            return ['allowed' => array_values(array_unique($allowed))];
        }
        if (($method === 'GET' || $method === 'HEAD') && !str_ends_with($path, '/') && !str_contains(basename($path), '.')) {
            foreach ($this->routes as $route) {
                if (in_array('GET', $route['methods'], true) && preg_match($route['regex'], $path . '/')) {
                    return ['redirect' => $path . '/'];
                }
            }
        }
        return null;
    }

    /** @return list<string> every static GET pattern (no placeholders) */
    public function staticGetPaths(): array
    {
        $out = [];
        foreach ($this->routes as $r) {
            if (in_array('GET', $r['methods'], true) && !str_contains($r['pattern'], '{')) {
                $out[] = $r['pattern'];
            }
        }
        return $out;
    }

    public function urlFor(string $name, array $params = []): string
    {
        foreach ($this->routes as $r) {
            if ($r['name'] === $name) {
                return (string) preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', static fn (array $m): string => rawurlencode((string) ($params[$m[1]] ?? '')), $r['pattern']);
            }
        }
        throw new \InvalidArgumentException("No route named {$name}");
    }

    private static function compile(string $pattern): string
    {
        $parts = preg_split('/(\{\w+(?::[^}]+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$pattern];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)(?::([^}]+))?\}$/', $part, $m)) {
                $regex .= '(?P<' . $m[1] . '>' . (($m[2] ?? '') !== '' ? $m[2] : '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#u';
    }
}
