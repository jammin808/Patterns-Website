<?php

declare(strict_types=1);

namespace Psc\Http;

/** An immutable view of one HTTP request. */
final class Request
{
    /** @var array<string, string> */
    private array $attributes = [];

    /** @var array<string, mixed> */
    public readonly array $query;

    /** @var array<string, mixed> */
    public readonly array $post;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers lower-case names
     * @param array<string, string> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $query = [],
        array $post = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly array $server = [],
        private readonly string $rawBody = '',
    ) {
        $this->query = self::scrub($query);
        $this->post = self::scrub($post);
    }

    /**
     * Replaces invalid UTF-8 in every string with U+FFFD, so nothing malformed reaches the database or a page.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public static function scrub(array $values): array
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        array_walk_recursive($values, static function (mixed &$v): void {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $v = mb_scrub($v, 'UTF-8');
            }
        });
        mb_substitute_character($previous);
        return $values;
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $raw = '';
        $type = $headers['content-type'] ?? '';
        if ($method !== 'GET' && $method !== 'HEAD' && str_contains($type, 'application/json')) {
            $raw = (string) file_get_contents('php://input', false, null, 0, 1_048_576);
        }
        /** @var array<string, string> $cookies */
        $cookies = array_filter($_COOKIE, 'is_string');
        return new self($method, self::normalisePath($path), $_GET, $_POST, $headers, $cookies, $_FILES, $_SERVER, $raw);
    }

    public static function normalisePath(string $path): string
    {
        $path = rawurldecode($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        return $path;
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $data = json_decode(mb_scrub($this->rawBody, 'UTF-8'), true);
        return is_array($data) ? $data : [];
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (($this->server['SERVER_PORT'] ?? null) == 443) {
            return true;
        }
        return strtolower($this->header('x-forwarded-proto')) === 'https';
    }

    public function ip(?string $trustedHeader = null): string
    {
        if ($trustedHeader !== null && isset($this->server[$trustedHeader]) && is_string($this->server[$trustedHeader])) {
            $candidate = trim(explode(',', $this->server[$trustedHeader])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        $addr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($addr) ? $addr : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr($this->header('user-agent'), 0, 255);
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json')
            || str_contains($this->header('content-type'), 'application/json');
    }

    /**
     * A same-origin browser request: Sec-Fetch-Site says so, or the Origin/Referer host is ours. Used with a
     * custom header on JSON endpoints, which a cross-site form cannot send without a CORS preflight we never grant.
     */
    public function isSameOrigin(string $host): bool
    {
        $site = $this->header('sec-fetch-site');
        if ($site !== '') {
            return $site === 'same-origin';
        }
        $origin = $this->header('origin') ?: $this->header('referer');
        if ($origin === '') {
            return false;
        }
        return strtolower((string) parse_url($origin, PHP_URL_HOST)) === strtolower($host);
    }

    public function host(): string
    {
        return strtolower(explode(':', $this->header('host', 'localhost'))[0]);
    }

    public function withAttribute(string $key, string $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    public function attr(string $key, string $default = ''): string
    {
        return $this->attributes[$key] ?? $default;
    }

    public function attrInt(string $key): int
    {
        return (int) ($this->attributes[$key] ?? 0);
    }
}
