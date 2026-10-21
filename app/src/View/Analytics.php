<?php

declare(strict_types=1);

namespace Psc\View;

use Psc\Http\Request;

/**
 * The Google tag (gtag.js) the owner asked for on every page, placed straight after <head>.
 *
 * The inline part is allowed by the CSP through its SHA-256 hash, so the snippet and the hash are built from one
 * string here and can never drift apart. Pages whose address carries a one-time token (password reset, email
 * confirmation, invitations) never load it: the tag sends the full address to Google.
 */
final class Analytics
{
    /** Google's own hosts for GA4, as its content-security-policy guide lists them. */
    private const SCRIPT_HOSTS = 'https://*.googletagmanager.com';
    private const IMG_HOSTS = 'https://*.google-analytics.com https://*.googletagmanager.com';
    private const CONNECT_HOSTS = 'https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com';

    /** The measurement ID when it is well formed, else '' (analytics off). */
    public static function id(string $configured): string
    {
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $configured) === 1 ? $configured : '';
    }

    /** Whether this request's page may carry the tag: never when its address holds a token. */
    public static function allowedOn(Request $request): bool
    {
        foreach (array_keys($request->query) as $key) {
            if (stripos((string) $key, 'token') !== false) {
                return false;
            }
        }
        return true;
    }

    /** The inline script's body, exactly as it is sent and hashed. */
    public static function inline(string $id): string
    {
        return "\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n\n  gtag('config', '" . $id . "');\n";
    }

    /** The tag as Google gives it. */
    public static function snippet(string $id): string
    {
        return "<!-- Google tag (gtag.js) -->\n"
            . '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>' . "\n"
            . '<script>' . self::inline($id) . "</script>\n";
    }

    /**
     * The CSP sources the tag needs, by directive: its inline part by hash, and Google's hosts.
     *
     * @return array{script: string, img: string, connect: string}
     */
    public static function csp(string $id): array
    {
        $hash = "'sha256-" . base64_encode(hash('sha256', self::inline($id), true)) . "'";
        return ['script' => $hash . ' ' . self::SCRIPT_HOSTS, 'img' => self::IMG_HOSTS, 'connect' => self::CONNECT_HOSTS];
    }
}
