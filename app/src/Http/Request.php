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

    /**
     * The client's address. A forwarding header is believed only as far as the proxies that wrote it:
     *  - with $trustedProxies (addresses or CIDR ranges), only a request whose REMOTE_ADDR is one of them has its
     *    header read at all;
     *  - X-Forwarded-For is read from the right, skipping trusted proxies, because every proxy appends the address
     *    it saw and only the leftmost entries are the client's own writing;
     *  - any other header (a single value, such as CF-Connecting-IP) is read only behind a listed proxy, since a
     *    client can send it too when nothing in front replaces it.
     *
     * @param list<string> $trustedProxies
     */
    public function ip(?string $trustedHeader = null, array $trustedProxies = []): string
    {
        $addr = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        $addr = is_string($addr) ? $addr : '0.0.0.0';
        if ($trustedHeader === null || !isset($this->server[$trustedHeader]) || !is_string($this->server[$trustedHeader])) {
            return $addr;
        }
        $behindProxy = $trustedProxies !== [] && self::inRanges($addr, $trustedProxies);
        if ($trustedProxies !== [] && !$behindProxy) {
            return $addr;
        }
        $value = $this->server[$trustedHeader];
        if (strtoupper($trustedHeader) === 'HTTP_X_FORWARDED_FOR') {
            foreach (array_reverse(explode(',', $value)) as $entry) {
                $candidate = trim($entry);
                if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    return $addr;
                }
                if (!$behindProxy || !self::inRanges($candidate, $trustedProxies)) {
                    return $candidate;
                }
            }
            return $addr;
        }
        $candidate = trim($value);
        return $behindProxy && filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : $addr;
    }

    /** @param list<string> $ranges addresses or CIDR ranges, IPv4 or IPv6 */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = array_pad(explode('/', trim((string) $range), 2), 2, null);
            $netBin = @inet_pton((string) $net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($bin) * 8 : max(0, min(strlen($bin) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
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
