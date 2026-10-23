<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * The announcer's pings over HTTPS: curl when it is installed, PHP's own stream otherwise. Short timeouts, so a
 * slow endpoint delays a tick by seconds, never minutes; no redirect is followed (an endpoint that moves is a
 * failure to look at, not a place to send the site's addresses); https only. Nothing it sends is a secret: the
 * IndexNow key is public by design (its file is served at /{key}.txt).
 */
final class HttpPingTransport implements PingTransport
{
    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 15;

    public function __construct(private readonly string $userAgent)
    {
    }

    public function post(string $url, string $body, string $type): int
    {
        if (preg_match('#^https://[^/\s]+/#i', $url) !== 1) {
            return 0;
        }
        $headers = ['Content-Type: ' . $type, 'User-Agent: ' . $this->userAgent];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return 0;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $ok = curl_exec($ch);
            $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch);
            return $status;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
        ]]);
        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            return 0;
        }
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        foreach (array_reverse($meta['wrapper_data'] ?? []) as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
