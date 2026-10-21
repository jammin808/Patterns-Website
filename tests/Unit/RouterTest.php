<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParamRoutes(): void
    {
        $r = new Router();
        $r->get('/stagewatch/', 'a');
        $r->get('/portal/tickets/{id:\d+}/', 'b');
        $r->post('/portal/tickets/', 'c');

        $m = $r->match('GET', '/stagewatch/');
        self::assertSame('a', $m['route']['handler']);

        $m = $r->match('GET', '/portal/tickets/42/');
        self::assertSame('b', $m['route']['handler']);
        self::assertSame(['id' => '42'], $m['params']);

        self::assertNull($r->match('GET', '/portal/tickets/abc/'));
    }

    public function testMissingSlashRedirectsAndWrongMethodIsReported(): void
    {
        $r = new Router();
        $r->get('/stagewatch/licensing/', 'a');
        $r->post('/portal/tickets/', 'c');
        self::assertSame(['redirect' => '/stagewatch/licensing/'], $r->match('GET', '/stagewatch/licensing'));
        self::assertSame(['allowed' => ['POST']], $r->match('GET', '/portal/tickets/'));
        self::assertNull($r->match('GET', '/sitemap.xml'));
    }

    public function testPlaceholderPatternsMayUseQuantifierBraces(): void
    {
        $r = new Router();
        $r->get('/portal/admin/chats/{id:[a-f0-9]{32}}/', 'chat', [], 'chat');
        $id = str_repeat('ab', 16);
        $m = $r->match('GET', '/portal/admin/chats/' . $id . '/');
        self::assertSame('chat', $m['route']['handler']);
        self::assertSame(['id' => $id], $m['params']);
        self::assertNull($r->match('GET', '/portal/admin/chats/' . substr($id, 1) . '/'));
        self::assertSame('/portal/admin/chats/' . $id . '/', $r->urlFor('chat', ['id' => $id]));
    }

    public function testPatternsAreQuotedLiterally(): void
    {
        $r = new Router();
        $r->get('/robots.txt', 'a');
        self::assertNull($r->match('GET', '/robotsXtxt'));
        self::assertNotNull($r->match('GET', '/robots.txt'));
    }
}
