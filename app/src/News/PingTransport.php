<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * The way out for the announcer's pings (IndexNow and the WebSub hub, docs/NEWS.md §6.1): one POST and the status
 * that came back. HttpPingTransport is the real one; the tests replace it (Announcer::useTransport()), so no test
 * ever reaches the network.
 */
interface PingTransport
{
    /**
     * POSTs $body to $url, sent as $type, and returns the HTTP status, or 0 when no answer came (a refused
     * connection, a timeout, an address that is not https). Never throws for a network failure.
     */
    public function post(string $url, string $body, string $type): int;
}
