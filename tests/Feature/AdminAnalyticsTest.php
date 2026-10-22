<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Http\Request;
use Psc\Tests\TestCase;
use Psc\View\Analytics;

/**
 * The Google tag sends each page's address and title to Google, and admin pages are titled with draft headlines
 * and account names: no admin page carries it, whatever its status, while the rest of the portal still does.
 */
final class AdminAnalyticsTest extends TestCase
{
    private const ID = 'G-0C7QKZTNF7';

    protected function setUp(): void
    {
        parent::setUp();
        self::rmrf($this->storage);
        $this->bootApp(['analytics' => ['ga_id' => self::ID]]);
    }

    public function testNoAdminPageCarriesTheTag(): void
    {
        $this->signInAdmin();
        foreach (['/portal/admin/' => 200, '/portal/admin/users/' => 200, '/portal/admin/news/' => 404, '/portal/admin/news/desk/' => 404] as $path => $status) {
            $r = $this->request('GET', $path);
            self::assertSame($status, $r->status, $path);
            self::assertStringNotContainsString('googletagmanager', $r->body, $path);
            self::assertStringNotContainsString("gtag('config'", $r->body, $path);
        }
        self::assertStringContainsString('googletagmanager.com/gtag/js?id=' . self::ID, $this->request('GET', '/portal/')->body, 'the client portal is still tagged');
        self::assertStringContainsString('googletagmanager.com/gtag/js?id=' . self::ID, $this->request('GET', '/news/')->body);
    }

    public function testOnlyPathsUnderTheAdminAreaAreLeftOut(): void
    {
        self::assertFalse(Analytics::allowedOn(new Request('GET', '/portal/admin/')));
        self::assertFalse(Analytics::allowedOn(new Request('GET', '/portal/admin/news/12/preview/')));
        self::assertTrue(Analytics::allowedOn(new Request('GET', '/portal/administrators-guide/')));
        self::assertTrue(Analytics::allowedOn(new Request('GET', '/portal/')));
        self::assertFalse(Analytics::allowedOn(new Request('GET', '/portal/reset/', ['token' => 'x'])), 'the token rule still holds');
    }
}
