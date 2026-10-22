<?php

declare(strict_types=1);

namespace Psc\View;

use Psc\Http\Request;

/**
 * The Google tag (gtag.js) the owner asked for on every page, placed straight after <head>, in one of two modes set by
 * analytics.consent:
 *
 * - false (the default, the owner's choice): the tag exactly as Google gives it. gtag.js loads on every visit and
 *   Google Analytics sets its cookies at once; there is no cookie banner.
 * - true: behind the visitor's consent (Google Consent Mode, basic). Every consent type starts denied and gtag.js is
 *   fetched only once the visitor has accepted statistics cookies, so nothing reaches Google before that. The inline
 *   part reads a stored acceptance (localStorage psc-consent = "granted:<ms>", good for a year) and loads the tag at
 *   once; the banner (templates/partials/consent.php, site.js initConsent) records a new choice. The cookie notice and
 *   privacy policy must then say so again (their consent wording is in the history of content/legal/).
 *
 * The inline part is allowed by the CSP through its SHA-256 hash, so the snippet and the hash are built from one
 * string here and can never drift apart. Pages whose address carries a one-time token (password reset, email
 * confirmation, invitations) never load it: the tag sends the full address to Google.
 */
final class Analytics
{
    /** How long an answer stands before the banner asks again: a year, in milliseconds. */
    public const CONSENT_MS = 31536000000;

    /** Google's own hosts for GA4, as its content-security-policy guide lists them. */
    private const SCRIPT_HOSTS = 'https://*.googletagmanager.com';
    private const IMG_HOSTS = 'https://*.google-analytics.com https://*.googletagmanager.com';
    private const CONNECT_HOSTS = 'https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com';

    /** The measurement ID when it is well formed, else '' (analytics off). */
    public static function id(string $configured): string
    {
        return preg_match('/^G-[A-Z0-9]{4,20}$/', $configured) === 1 ? $configured : '';
    }

    /** Whether the tag waits for the visitor's consent (and the cookie banner shows): analytics.consent, off by default. */
    public static function asksFirst(\Psc\App $app): bool
    {
        return $app->config->bool('analytics.consent', false);
    }

    /** The ID whose tag (and cookie choice) this page carries, or '' when analytics is off or the page has a token. */
    public static function forPage(\Psc\App $app): string
    {
        $id = self::id($app->config->string('analytics.ga_id'));
        $request = $app->request();
        return $id !== '' && ($request === null || self::allowedOn($request)) ? $id : '';
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

    /** Where gtag.js is fetched from. */
    public static function loaderUrl(string $id): string
    {
        return 'https://www.googletagmanager.com/gtag/js?id=' . $id;
    }

    /** The inline script's body, exactly as it is sent and hashed. */
    public static function inline(string $id, bool $askFirst): string
    {
        if (!$askFirst) {
            return "\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n"
                . "  gtag('js', new Date());\n\n  gtag('config', '" . $id . "');\n";
        }
        return "\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n"
            . "  gtag('consent', 'default', {analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});\n"
            . "  gtag('js', new Date());\n\n  gtag('config', '" . $id . "');\n"
            . "  (function () {\n"
            . "    var choice = '';\n"
            . "    try { choice = localStorage.getItem('psc-consent') || ''; } catch (e) {}\n"
            . "    var parts = choice.split(':');\n"
            . "    if (parts[0] === 'granted' && Date.now() - Number(parts[1]) < " . self::CONSENT_MS . ") {\n"
            . "      gtag('consent', 'update', {analytics_storage: 'granted'});\n"
            . "      var tag = document.createElement('script');\n"
            . "      tag.async = true;\n"
            . "      tag.src = '" . self::loaderUrl($id) . "';\n"
            . "      document.head.appendChild(tag);\n"
            . "    }\n"
            . "  })();\n";
    }

    /** The tag as Google gives it; when it asks first, gtag.js loads only after consent (see the class comment). */
    public static function snippet(string $id, bool $askFirst): string
    {
        $loader = $askFirst ? '' : '<script async src="' . self::loaderUrl($id) . '"></script>' . "\n";
        return "<!-- Google tag (gtag.js) -->\n" . $loader . '<script>' . self::inline($id, $askFirst) . "</script>\n";
    }

    /**
     * The CSP sources the tag needs, by directive: its inline part by hash, and Google's hosts.
     *
     * @return array{script: string, img: string, connect: string}
     */
    public static function csp(string $id, bool $askFirst): array
    {
        $hash = "'sha256-" . base64_encode(hash('sha256', self::inline($id, $askFirst), true)) . "'";
        return ['script' => $hash . ' ' . self::SCRIPT_HOSTS, 'img' => self::IMG_HOSTS, 'connect' => self::CONNECT_HOSTS];
    }
}
