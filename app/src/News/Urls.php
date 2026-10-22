<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * Web addresses as Industry Updates compares them. Two addresses that lead to the same page get the same canonical
 * form, and so the same key: links are registered, counted and checked for duplicates by key, never by the text
 * someone typed. Only http and https addresses on a named host are addresses at all: userinfo ("user@host") and
 * IP-literal hosts are refused, so a link can neither disguise its host nor point into a private network.
 */
final class Urls
{
    /** The longest host name DNS allows, and so the longest host an address has here (news_links.host holds it). */
    public const HOST_MAX = 253;

    /** Query parameters that track a click and never change the page. */
    private const TRACKING = ['fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    /**
     * The canonical form: lower-case scheme and host (through IDNA), no trailing dot, no "www.", no default port,
     * no fragment and no tracking parameters; the path as it was, without a trailing slash except at the root; every
     * other query parameter kept, sorted by name. Null when the text is not an http(s) address on a named host.
     */
    public static function canonical(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !mb_check_encoding($url, 'UTF-8') || preg_match('/[\s\x00-\x1F\x7F\\\\]/u', $url) || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        // parse_url reads "https://a@b" as user a; an @ anywhere before the path is userinfo however it parsed.
        $authority = (string) preg_replace('#^https?://#i', '', $url);
        $authority = (string) strtok($authority, '/?#');
        if (str_contains($authority, '@')) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = self::normaliseHost($parts['host']);
        if ($host === null) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $port = null;
        }
        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        $query = self::query($parts['query'] ?? '');
        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path . ($query !== '' ? '?' . $query : '');
    }

    /** The sha256 of the canonical form (news_links.url_key), or null when the text is not an address. */
    public static function key(string $url): ?string
    {
        $canonical = self::canonical($url);
        return $canonical !== null ? hash('sha256', $canonical) : null;
    }

    /** The canonical host ("example.com" for "https://WWW.Example.com./x"), or null when the text is not an address. */
    public static function host(string $url): ?string
    {
        $canonical = self::canonical($url);
        if ($canonical === null) {
            return null;
        }
        $host = parse_url($canonical, PHP_URL_HOST);
        return is_string($host) ? $host : null;
    }

    /**
     * Whether a link stays on this site: a site-relative path, or an absolute address whose canonical host is
     * exactly the site's ("patternsshowcontrol.co.uk.evil.example" is someone else's).
     */
    public static function isOwn(string $url, string $baseUrl): bool
    {
        $url = trim($url);
        if (str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\')) {
            return true;
        }
        $host = self::host($url);
        $own = self::host($baseUrl);
        return $host !== null && $own !== null && $host === $own;
    }

    /**
     * Whether an address is on one of $domains or a subdomain of one (a banned "example.com" also bans
     * "news.example.com"). The domains are normalised the way normaliseDomain() stores them.
     *
     * @param list<string> $domains
     */
    public static function onDomain(string $url, array $domains): bool
    {
        $host = self::host($url);
        if ($host === null) {
            return false;
        }
        foreach ($domains as $domain) {
            $d = self::normaliseDomain((string) $domain);
            if ($d !== null && ($host === $d || str_ends_with($host, '.' . $d))) {
                return true;
            }
        }
        return false;
    }

    /**
     * A site name as typed into a banned list ("https://www.Example.com/page", "*.example.com", "example.com.")
     * reduced to the host that is compared: "example.com". Null when it is not a host name.
     */
    public static function normaliseDomain(string $domain): ?string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $domain)) {
            $domain = 'http://' . ltrim($domain, '*.');
        }
        $host = parse_url($domain, PHP_URL_HOST);
        return is_string($host) ? self::normaliseHost(ltrim($host, '*.')) : null;
    }

    /** A host lower-cased through IDNA, without a trailing dot or "www.", or null for an IP literal or a non-name. */
    private static function normaliseHost(string $host): ?string
    {
        $host = trim($host);
        if ($host === '' || str_starts_with($host, '[') || filter_var(trim($host, '.'), FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        if (preg_match('/[^\x20-\x7E]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                return null;
            }
            $host = $ascii;
        }
        $host = rtrim(strtolower($host), '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (strlen($host) > self::HOST_MAX || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host)) {
            return null;
        }
        // Every label numeric ("127.1", "0x7f.1" aside) is an address in disguise, not a name.
        if (preg_match('/^[0-9.]+$/', $host) || preg_match('/^(?:0x[0-9a-f]+\.?)+$/', $host)) {
            return null;
        }
        return $host;
    }

    /** The query without tracking parameters, the rest sorted by name (equal names keep their order). */
    private static function query(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING, true)) {
                continue;
            }
            $pairs[] = [rawurldecode(explode('=', $pair, 2)[0]), $pair];
        }
        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        return implode('&', array_column($pairs, 1));
    }
}
