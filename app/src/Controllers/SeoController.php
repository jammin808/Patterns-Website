<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Chat\AnthropicGateway;
use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;

/** sitemap.xml, robots.txt, llms.txt, the web manifest, security.txt and a health check, all built from content/. */
final class SeoController extends Controller
{
    public function sitemap(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach (Content::pages() as $path => $page) {
            if (!empty($page['noindex'])) {
                continue;
            }
            $xml[] = '  <url>';
            $xml[] = '    <loc>' . htmlspecialchars($base . $path, ENT_XML1) . '</loc>';
            if (!empty($page['updated'])) {
                $xml[] = '    <lastmod>' . htmlspecialchars((string) $page['updated'], ENT_XML1) . '</lastmod>';
            }
            $xml[] = '  </url>';
        }
        $xml[] = '</urlset>';
        return Response::text(implode("\n", $xml) . "\n", 200, 'application/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function robots(Request $request): Response
    {
        $base = $this->app->baseUrl();
        // The portal is not disallowed: crawlers must be able to fetch it to read its noindex. Search and answer
        // engines are welcome; model-training crawlers are the owner's call (content/site.php 'block_training').
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            '',
        ];
        if (!empty(Content::get('site')['block_training'])) {
            foreach (['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot'] as $bot) {
                $lines[] = 'User-agent: ' . $bot;
            }
            $lines[] = 'Disallow: /';
            $lines[] = '';
        }
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        return Response::text(implode("\n", $lines) . "\n")->header('Cache-Control', 'public, max-age=3600');
    }

    /** llms.txt: a plain map of the site for AI assistants and answer engines (llmstxt.org format). */
    public function llms(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $site = Content::get('site');
        $out = ['# ' . $this->app->name(), ''];
        $out[] = '> ' . (string) ($site['summary'] ?? 'Show control software for live events.');
        $out[] = '';
        if (!empty($site['llms_intro'])) {
            $out[] = (string) $site['llms_intro'];
            $out[] = '';
        }
        $out[] = '## Products';
        $out[] = '';
        foreach (Content::products() as $key => $p) {
            $out[] = '- [' . $p['name'] . '](' . $base . '/' . $key . '/): ' . ($p['summary'] ?? $p['tagline'] ?? '');
            $lic = Content::licensing($key);
            if ($lic !== null) {
                $out[] = '- [' . $p['name'] . ' licensing](' . $base . '/' . $key . '/licensing/): ' . self::licensingNote($lic);
            }
        }
        $out[] = '';
        $sections = ['Ecosystem' => ['ecosystem'], 'Workflows' => ['workflows'], 'Support' => ['support', 'downloads', 'contact'], 'About' => ['about', 'legal']];
        foreach ($sections as $title => $keys) {
            $lines = [];
            foreach (Content::pages() as $path => $page) {
                if (!empty($page['noindex']) || !in_array($page['section'] ?? '', $keys, true)) {
                    continue;
                }
                $lines[] = '- [' . ($page['crumb'] ?? $page['title']) . '](' . $base . $path . '): ' . $page['description'];
            }
            if ($lines !== []) {
                $out[] = '## ' . $title;
                $out[] = '';
                array_push($out, ...$lines);
                $out[] = '';
            }
        }
        return Response::text(implode("\n", $out))->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * What a product's licensing page holds, for llms.txt: its own note, or by its pricing status. Only a page with
     * prices on it is said to have prices.
     *
     * @param array<string, mixed> $licensing
     */
    public static function licensingNote(array $licensing): string
    {
        if (is_string($licensing['llms_note'] ?? null) && $licensing['llms_note'] !== '') {
            return $licensing['llms_note'];
        }
        return ($licensing['pricing_status'] ?? 'live') === 'live'
            ? 'tiers, prices and licence terms'
            : 'how licensing will work and how to register interest';
    }

    public function manifest(Request $request): Response
    {
        $data = [
            'name' => $this->app->name(),
            'short_name' => 'Patterns',
            'description' => 'Show control software for live events.',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'browser',
            // The dark theme's page colour (--bg in site.css), the same as the layout's theme-color meta.
            'background_color' => '#090C10',
            'theme_color' => '#090C10',
            'icons' => [
                ['src' => '/assets/img/brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/assets/img/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/assets/img/brand/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** Days ahead security.txt's Expires stands: always far beyond its one-day cache life, within RFC 9116's year. */
    public const SECURITY_TXT_DAYS = 180;

    /**
     * security.txt (RFC 9116). It names no Policy: that field is for a vulnerability disclosure policy, which the
     * site does not publish, and a privacy notice is not one.
     */
    public function security(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $lines = [
            'Contact: mailto:' . $this->app->config->string('app.support_email'),
            'Expires: ' . gmdate('Y-m-d\T00:00:00\Z', time() + self::SECURITY_TXT_DAYS * 86400),
            'Preferred-Languages: en',
            'Canonical: ' . $base . '/.well-known/security.txt',
        ];
        return Response::text(implode("\n", $lines) . "\n")->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * A health check for uptime monitors: booleans only, nothing sensitive. The site is healthy only when every
     * part it is configured to use works: storage and the database always, the assistant's SDK once the assistant
     * is switched on with a key. config_shadowed is true when a config/local.php sits in the web root unread,
     * because the configuration outside it wins (a change uploaded there has no effect).
     */
    public function health(Request $request): Response
    {
        $cfg = $this->app->config;
        $checks = [
            'storage' => is_writable($this->app->storage->root()),
            'vendor' => AnthropicGateway::available(),
        ];
        try {
            $this->app->db()->value('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'health');
            $checks['database'] = false;
        }
        $checks['config_shadowed'] = $cfg->shadowed();
        $ok = self::healthy($checks, $cfg->bool('chat.enabled', true) && $cfg->string('chat.api_key') !== '');
        return Response::json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503)
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Whether the checks add up to a working site. A shadowed configuration file is reported, not failed: the site
     * runs on the configuration it found.
     *
     * @param array{storage: bool, vendor: bool, database: bool} $checks
     */
    public static function healthy(array $checks, bool $assistantConfigured): bool
    {
        return $checks['storage'] && $checks['database'] && (!$assistantConfigured || $checks['vendor']);
    }
}
