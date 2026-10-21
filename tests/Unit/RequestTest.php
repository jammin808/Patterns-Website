<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Http\Request;

/**
 * The client's address behind a proxy. The leftmost X-Forwarded-For entry is whatever the client wrote, so trusting
 * it let anyone pick a new address for every request and pass every per-address limit.
 */
final class RequestTest extends TestCase
{
    /** @param array<string, string> $server */
    private static function req(array $server): Request
    {
        return new Request('GET', '/', [], [], [], [], [], $server + ['REMOTE_ADDR' => '10.0.0.5']);
    }

    public function testForwardedForIsReadFromTheRight(): void
    {
        $r = self::req(['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9']);
        self::assertSame('203.0.113.9', $r->ip('HTTP_X_FORWARDED_FOR'), 'the address the proxy saw, not the one the client wrote');
        self::assertSame('10.0.0.5', $r->ip(), 'no header trusted: REMOTE_ADDR');
        self::assertSame('10.0.0.5', self::req(['HTTP_X_FORWARDED_FOR' => '1.2.3.4, not-an-ip'])->ip('HTTP_X_FORWARDED_FOR'));
    }

    public function testTrustedProxiesAreSkippedAndOnlyTheirRequestsAreRead(): void
    {
        $proxies = ['10.0.0.0/8', '2001:db8::/32'];
        $r = self::req(['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9, 10.1.2.3']);
        self::assertSame('203.0.113.9', $r->ip('HTTP_X_FORWARDED_FOR', $proxies));

        $direct = self::req(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
        self::assertSame('198.51.100.1', $direct->ip('HTTP_X_FORWARDED_FOR', $proxies), 'a request that bypassed the proxy is taken at its word');
    }

    public function testASingleValueHeaderIsReadOnlyBehindAListedProxy(): void
    {
        $r = self::req(['HTTP_CF_CONNECTING_IP' => '1.2.3.4']);
        self::assertSame('10.0.0.5', $r->ip('HTTP_CF_CONNECTING_IP'), 'no proxy list: a client could have sent it');
        self::assertSame('1.2.3.4', $r->ip('HTTP_CF_CONNECTING_IP', ['10.0.0.5']));
        self::assertSame('10.0.0.5', $r->ip('HTTP_CF_CONNECTING_IP', ['192.0.2.0/24']));
    }

    public function testRanges(): void
    {
        self::assertTrue(Request::inRanges('192.0.2.77', ['192.0.2.0/24']));
        self::assertFalse(Request::inRanges('192.0.3.1', ['192.0.2.0/24']));
        self::assertTrue(Request::inRanges('10.255.0.1', ['10.0.0.0/8']));
        self::assertTrue(Request::inRanges('192.0.2.1', ['192.0.2.1']));
        self::assertTrue(Request::inRanges('2001:db8::1', ['2001:db8::/32']));
        self::assertFalse(Request::inRanges('2001:db9::1', ['2001:db8::/32']));
        self::assertFalse(Request::inRanges('192.0.2.1', ['2001:db8::/32']));
        self::assertTrue(Request::inRanges('172.20.1.1', ['172.16.0.0/12']));
        self::assertFalse(Request::inRanges('172.32.0.1', ['172.16.0.0/12']));
    }
}
