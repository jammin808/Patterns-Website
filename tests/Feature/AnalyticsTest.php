<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Tests\TestCase;

/** The Google tag: first in <head> on every page, allowed by the CSP through its hash, never on token pages. */
final class AnalyticsTest extends TestCase
{
    private const ID = 'G-0C7QKZTNF7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(['analytics' => ['ga_id' => self::ID]]);
    }

    public function testTheTagIsFirstInTheHeadOfPublicPortalAndErrorPages(): void
    {
        foreach (['/', '/stagewatch/licensing/', '/contact/', '/portal/login/', '/no-such-page/'] as $path) {
            $body = $this->request('GET', $path)->body;
            self::assertStringContainsString(
                "<head>\n<!-- Google tag (gtag.js) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=" . self::ID . "\"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n\n  gtag('config', '" . self::ID . "');\n</script>\n",
                $body,
                $path,
            );
            self::assertSame(1, substr_count($body, 'googletagmanager.com/gtag/js'), $path);
        }
    }

    public function testTheCspAllowsTheInlineTagByItsHashAndGooglesHostsOnly(): void
    {
        $r = $this->request('GET', '/');
        self::assertSame(1, preg_match('#<!-- Google tag \(gtag\.js\) -->\n<script async [^>]*></script>\n<script>(.*?)</script>#s', $r->body, $m));
        $csp = (string) $r->getHeader('Content-Security-Policy');
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $words = preg_split('/\s+/', trim($part)) ?: [];
            $directives[(string) array_shift($words)] = $words;
        }
        $hash = "'sha256-" . base64_encode(hash('sha256', $m[1], true)) . "'";
        self::assertContains($hash, $directives['script-src'], 'the inline tag runs under the strict CSP');
        self::assertNotContains("'unsafe-inline'", $directives['script-src']);
        self::assertContains('https://*.googletagmanager.com', $directives['script-src']);
        self::assertContains('https://*.google-analytics.com', $directives['connect-src']);
        self::assertContains('https://*.analytics.google.com', $directives['connect-src']);
        self::assertContains('https://*.google-analytics.com', $directives['img-src']);
        self::assertSame(["'self'"], $directives['style-src'], 'nothing else is loosened');
    }

    /** The tag sends the full address to Google, so a page whose address carries a one-time token never loads it. */
    public function testPagesWithATokenInTheirAddressCarryNoTag(): void
    {
        foreach (['/portal/reset/?token=abc.def', '/portal/reset/?invite=1&token=abc.def', '/portal/verify/?token=abc.def'] as $path) {
            self::assertStringNotContainsString('googletagmanager', $this->request('GET', $path)->body, $path);
        }
        self::assertStringContainsString('googletagmanager', $this->request('GET', '/portal/reset/')->body, 'a reset page without a token is tagged');
    }

    public function testNoIdOrAMalformedIdMeansNoTagAndAnUnchangedCsp(): void
    {
        foreach (['', 'G-<script>', 'UA-12345-1'] as $id) {
            $this->bootApp(['analytics' => ['ga_id' => $id]]);
            $r = $this->request('GET', '/');
            self::assertStringNotContainsString('gtag', $r->body, $id);
            self::assertStringContainsString("script-src 'self';", (string) $r->getHeader('Content-Security-Policy'), $id);
        }
    }
}
