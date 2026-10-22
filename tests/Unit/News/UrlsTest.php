<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use PHPUnit\Framework\TestCase;
use Psc\News\Urls;

final class UrlsTest extends TestCase
{
    public function testTheCanonicalFormOfAnAddress(): void
    {
        self::assertSame('https://example.com/Path?a=1&b=2', Urls::canonical('HTTPS://WWW.Example.COM./Path/?b=2&utm_source=news&a=1#section'));
        self::assertSame('http://example.com/x', Urls::canonical('http://example.com:80/x'));
        self::assertSame('https://example.com:8443/x', Urls::canonical('https://example.com:8443/x'), 'only the default port goes');
        self::assertSame('https://example.com/', Urls::canonical('https://example.com'));
        self::assertSame('https://example.com/', Urls::canonical('https://www.example.com/'), 'the root keeps its slash');
        self::assertSame('https://example.com/a?id=7&q=x%20y', Urls::canonical('https://example.com/a/?q=x%20y&fbclid=1&gclid=2&mc_cid=3&mc_eid=4&UTM_Medium=5&id=7'));
        self::assertSame('https://example.com/a?b=2&b=1', Urls::canonical('https://example.com/a?b=2&b=1'), 'equal names keep their order');
        self::assertSame('https://xn--bcher-kva.de/x', Urls::canonical("https://b\u{00FC}cher.de/x"), 'hosts go through IDNA');
    }

    public function testOnlyHttpAddressesOnNamedHostsAreAddresses(): void
    {
        foreach ([
            'https://user:pw@example.com/', 'https://example.com@evil.example/', 'https://user@example.com/',
            'http://127.0.0.1/', 'http://[::1]/', 'http://127.1/', 'http://192.168.0.10:8080/admin',
            'ftp://example.com/', 'javascript:alert(1)', '//example.com/x', '/news/', 'mailto:ben@example.com',
            'https://exa mple.com/', 'https://example.com/a b', 'https://localhost/', "https://example.com/a\x00b", '',
        ] as $bad) {
            self::assertNull(Urls::canonical($bad), $bad);
            self::assertNull(Urls::key($bad), $bad);
        }
    }

    public function testTheKeyIsTheHashOfTheCanonicalForm(): void
    {
        $key = Urls::key('https://www.example.com/story/?utm_campaign=x');
        self::assertSame(hash('sha256', 'https://example.com/story'), $key);
        self::assertSame($key, Urls::key('https://example.com/story#comments'));
        self::assertNotSame($key, Urls::key('https://example.com/story?page=2'));
        self::assertSame('example.com', Urls::host('https://WWW.Example.com./x'));
        self::assertNull(Urls::host('/relative/'));
    }

    public function testOwnLinksAreDecidedByTheHostNotAPrefix(): void
    {
        $base = 'https://patternsshowcontrol.co.uk';
        self::assertTrue(Urls::isOwn('https://patternsshowcontrol.co.uk/support/', $base));
        self::assertTrue(Urls::isOwn('https://www.patternsshowcontrol.co.uk/news/', $base));
        self::assertTrue(Urls::isOwn('http://patternsshowcontrol.co.uk/', $base));
        self::assertTrue(Urls::isOwn('/news/a-story/', $base));
        self::assertFalse(Urls::isOwn('https://patternsshowcontrol.co.uk.evil.example/', $base));
        self::assertFalse(Urls::isOwn('https://evil.example/patternsshowcontrol.co.uk/', $base));
        self::assertFalse(Urls::isOwn('//evil.example/x', $base));
        self::assertFalse(Urls::isOwn('/\\evil.example/x', $base));
    }

    public function testBannedDomainsCoverTheirSubdomains(): void
    {
        $banned = ['example.com', 'https://www.Spam.example/page'];
        self::assertTrue(Urls::onDomain('https://example.com/a', $banned));
        self::assertTrue(Urls::onDomain('https://news.example.com/a', $banned));
        self::assertTrue(Urls::onDomain('https://spam.example/', $banned));
        self::assertFalse(Urls::onDomain('https://notexample.com/', $banned));
        self::assertFalse(Urls::onDomain('https://example.com.au/', $banned));
        self::assertSame('example.com', Urls::normaliseDomain('https://www.Example.com/page'));
        self::assertSame('example.com', Urls::normaliseDomain('*.example.com'));
        self::assertSame('example.com', Urls::normaliseDomain('example.com.'));
        self::assertSame('example.com', Urls::normaliseDomain('example.com:8080/x'));
        self::assertNull(Urls::normaliseDomain('not a domain'));
        self::assertNull(Urls::normaliseDomain('127.0.0.1'));
        self::assertNull(Urls::normaliseDomain(''));
    }

    public function testAHostIsAtMostTheLengthDnsAllows(): void
    {
        $longest = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 53), 'example']);
        self::assertSame(Urls::HOST_MAX, strlen($longest));
        self::assertSame($longest, Urls::host('https://www.' . $longest . '/x'), '"www." aside');
        self::assertNull(Urls::host('https://e' . $longest . '/x'));
        self::assertNull(Urls::key('https://e' . $longest . '/x'));
        self::assertNull(Urls::host('https://' . str_repeat('a', 64) . '.example/x'), 'a label is at most 63');
    }
}
