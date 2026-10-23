<?php

declare(strict_types=1);

namespace Psc\News\Digest;

/**
 * Every address the digest uses (docs/NEWS.md §14.3), as paths: an absolute one is App::baseUrl() and the path.
 * Each path is lower case with the routes' own trailing slash, so neither force_https nor the router's slash ever
 * answers it with a 301, which RFC 8058 forbids for the one-click POST. A token rides in the query under the key
 * "token" (App::dispatch lower-cases paths, and the Logger masks that key), never in the path.
 */
final class Links
{
    public const SIGNUP = '/digest/';
    public const CONFIRM = '/digest/confirm/';
    public const UNSUBSCRIBE = '/digest/unsubscribe/';

    public static function confirm(string $token): string
    {
        return self::CONFIRM . '?token=' . rawurlencode($token);
    }

    public static function unsubscribe(string $token): string
    {
        return self::UNSUBSCRIBE . '?token=' . rawurlencode($token);
    }

    /**
     * $path with the digest's campaign tags: static per digest and never per person, so a link says nothing about
     * who followed it. A fragment stays at the end.
     */
    public static function campaign(string $path, string $week): string
    {
        $fragment = '';
        $hash = strpos($path, '#');
        if ($hash !== false) {
            $fragment = substr($path, $hash);
            $path = substr($path, 0, $hash);
        }
        return $path . (str_contains($path, '?') ? '&' : '?')
            . 'utm_source=digest&utm_medium=email&utm_campaign=' . rawurlencode($week) . $fragment;
    }

    /** The mailto link that asks to unsubscribe: the token in the subject finds the row even when forwarded. */
    public static function mailto(string $address, string $token): string
    {
        return 'mailto:' . $address . '?subject=' . rawurlencode('Unsubscribe ' . $token);
    }
}
