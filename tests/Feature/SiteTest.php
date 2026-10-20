<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Content\Content;
use Psc\Tests\TestCase;

final class SiteTest extends TestCase
{
    public function testSecurityHeadersOnPages(): void
    {
        $r = $this->request('GET', '/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString("default-src 'self'", (string) $r->getHeader('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $r->getHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        self::assertStringContainsString('max-age=31536000', (string) $r->getHeader('Strict-Transport-Security'));
        self::assertStringStartsWith('public', (string) $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies(), 'public pages set no cookies');
    }

    public function testUnknownPagesAre404AndNoindex(): void
    {
        $r = $this->request('GET', '/no-such-page/');
        self::assertSame(404, $r->status);
        self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
    }

    public function testCanonicalRedirects(): void
    {
        foreach (array_keys(Content::pages()) as $path) {
            if ($path === '/') {
                continue;
            }
            $r = $this->request('GET', rtrim($path, '/'));
            self::assertSame(301, $r->status, $path);
            self::assertSame($path, $r->getHeader('Location'));
            break;
        }
        $r = $this->request('GET', '/StageWatch');
        self::assertSame(301, $r->status);
    }

    public function testSitemapRobotsAndLlms(): void
    {
        $sitemap = $this->request('GET', '/sitemap.xml');
        self::assertSame(200, $sitemap->status);
        $xml = simplexml_load_string($sitemap->body);
        self::assertNotFalse($xml);
        $locs = array_map('strval', iterator_to_array($xml->url->loc ?? [], false));
        foreach ($xml->url as $u) {
            self::assertStringStartsWith('https://patternsshowcontrol.test/', (string) $u->loc);
            self::assertStringNotContainsString('/portal/', (string) $u->loc);
        }
        self::assertNotEmpty($xml->url);

        $robots = $this->request('GET', '/robots.txt')->body;
        self::assertStringContainsString('Disallow: /portal/', $robots);
        self::assertStringContainsString('Sitemap: https://patternsshowcontrol.test/sitemap.xml', $robots);

        $llms = $this->request('GET', '/llms.txt');
        self::assertSame(200, $llms->status);
        self::assertStringStartsWith('# Patterns Show Control', $llms->body);
    }

    public function testSecurityTxtAndManifest(): void
    {
        self::assertStringContainsString('Contact: mailto:', $this->request('GET', '/.well-known/security.txt')->body);
        $m = json_decode($this->request('GET', '/manifest.webmanifest')->body, true);
        self::assertSame('Patterns Show Control', $m['name']);
    }
}
