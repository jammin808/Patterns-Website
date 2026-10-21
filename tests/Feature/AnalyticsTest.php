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
            self::assertStringContainsString("<head>\n<!-- Google tag (gtag.js) -->\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n", $body, $path);
            self::assertStringContainsString("  gtag('js', new Date());\n\n  gtag('config', '" . self::ID . "');\n", $body, $path);
            self::assertSame(1, substr_count($body, 'googletagmanager.com/gtag/js'), $path);
        }
    }

    /**
     * Consent Mode, basic: every consent type starts denied before the tag is configured, and gtag.js is fetched only
     * inside the branch that finds a stored acceptance, so a visitor who has not accepted sends nothing to Google.
     */
    public function testNothingReachesGoogleBeforeTheVisitorAccepts(): void
    {
        $body = $this->request('GET', '/')->body;
        self::assertStringNotContainsString('<script async src="https://www.googletagmanager.com', $body, 'no loader tag in the page');
        $denied = strpos($body, "gtag('consent', 'default', {analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});");
        $config = strpos($body, "gtag('config', '" . self::ID . "');");
        $granted = strpos($body, "if (parts[0] === 'granted' && Date.now() - Number(parts[1]) < 31536000000) {");
        $loader = strpos($body, "tag.src = 'https://www.googletagmanager.com/gtag/js?id=" . self::ID . "';");
        self::assertIsInt($denied);
        self::assertIsInt($config);
        self::assertIsInt($granted);
        self::assertIsInt($loader);
        self::assertTrue($denied < $config && $config < $granted && $granted < $loader, 'denied first; the loader only after a stored acceptance');
    }

    /** Accept and reject are equals, the banner waits hidden for site.js, and the choice can be reopened. */
    public function testTheCookieChoiceOffersAcceptAndRejectAsEquals(): void
    {
        $body = $this->request('GET', '/')->body;
        self::assertSame(1, preg_match('#<section class="c-consent" data-consent data-ga="' . self::ID . '" aria-labelledby="consent-title" hidden>(.*?)</section>#s', $body, $m));
        preg_match_all('#<button type="button" class="([^"]+)" data-consent-choice="(granted|denied)">([^<]+)</button>#', $m[1], $buttons, PREG_SET_ORDER);
        self::assertCount(2, $buttons);
        self::assertSame($buttons[0][1], $buttons[1][1], 'the same style for both answers');
        self::assertSame(['granted', 'denied'], [$buttons[0][2], $buttons[1][2]]);
        self::assertSame(['Accept statistics cookies', 'Reject statistics cookies'], [$buttons[0][3], $buttons[1][3]]);
        self::assertStringContainsString('href="/legal/cookies/"', $m[1]);
        self::assertLessThan(strpos($body, '<header'), strpos($body, 'data-consent '), 'reached early by the keyboard, after the skip link');
        self::assertStringContainsString('data-consent-open hidden>Cookie settings</button>', $body, 'the footer reopens it');
        self::assertStringContainsString('data-consent-open hidden>Change your cookie choice</button>', $this->request('GET', '/legal/cookies/')->body);
    }

    public function testTheCspAllowsTheInlineTagByItsHashAndGooglesHostsOnly(): void
    {
        $r = $this->request('GET', '/');
        self::assertSame(1, preg_match('#<!-- Google tag \(gtag\.js\) -->\n<script>(.*?)</script>#s', $r->body, $m));
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

    /** With Turnstile switched on, the contact page adds Cloudflare's host to the one policy instead of replacing it. */
    public function testTheContactPageKeepsTheTagWhenTurnstileIsOn(): void
    {
        $this->bootApp(['analytics' => ['ga_id' => self::ID], 'security' => ['turnstile_site_key' => 'site-key', 'turnstile_secret' => 'secret']]);
        $r = $this->request('GET', '/contact/');
        $directives = [];
        foreach (explode(';', (string) $r->getHeader('Content-Security-Policy')) as $part) {
            $words = preg_split('/\s+/', trim($part)) ?: [];
            $directives[(string) array_shift($words)] = $words;
        }
        self::assertContains('https://*.googletagmanager.com', $directives['script-src']);
        self::assertContains('https://challenges.cloudflare.com', $directives['script-src']);
        self::assertSame(['https://challenges.cloudflare.com'], $directives['frame-src'], "Turnstile's frame replaces 'none'");
        self::assertContains('https://*.google-analytics.com', $directives['connect-src']);
        self::assertContains('https://challenges.cloudflare.com', $directives['connect-src']);
        self::assertSame(["'none'"], $directives['object-src']);
    }

    /** The tag sends the full address to Google, so a page whose address carries a one-time token never loads it. */
    public function testPagesWithATokenInTheirAddressCarryNoTag(): void
    {
        foreach (['/portal/reset/?token=abc.def', '/portal/reset/?invite=1&token=abc.def', '/portal/verify/?token=abc.def'] as $path) {
            $body = $this->request('GET', $path)->body;
            self::assertStringNotContainsString('googletagmanager', $body, $path);
            self::assertStringNotContainsString('data-consent ', $body, $path . ': no banner where there is no tag');
        }
        self::assertStringContainsString('googletagmanager', $this->request('GET', '/portal/reset/')->body, 'a reset page without a token is tagged');
    }

    public function testNoIdOrAMalformedIdMeansNoTagAndAnUnchangedCsp(): void
    {
        foreach (['', 'G-<script>', 'UA-12345-1'] as $id) {
            $this->bootApp(['analytics' => ['ga_id' => $id]]);
            $r = $this->request('GET', '/');
            self::assertStringNotContainsString('gtag', $r->body, $id);
            self::assertStringNotContainsString('data-consent', $r->body, $id . ': no banner and no Cookie settings without the tag');
            self::assertStringContainsString("script-src 'self';", (string) $r->getHeader('Content-Security-Policy'), $id);
        }
    }
}
