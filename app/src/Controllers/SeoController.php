<?php

declare(strict_types=1);

namespace Psc\Controllers;

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
            if (Content::licensing($key) !== null) {
                $out[] = '- [' . $p['name'] . ' licensing](' . $base . '/' . $key . '/licensing/): tiers, prices and licence terms';
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

    public function security(Request $request): Response
    {
        $base = $this->app->baseUrl();
        $lines = [
            'Contact: mailto:' . $this->app->config->string('app.support_email'),
            'Expires: ' . gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 year', strtotime(gmdate('Y-01-01')))),
            'Preferred-Languages: en',
            'Canonical: ' . $base . '/.well-known/security.txt',
            'Policy: ' . $base . '/legal/privacy/',
        ];
        return Response::text(implode("\n", $lines) . "\n")->header('Cache-Control', 'public, max-age=86400');
    }

    /** A health check for uptime monitors: booleans only, nothing sensitive. */
    public function health(Request $request): Response
    {
        $checks = [
            'storage' => is_writable($this->app->storage->root()),
            'vendor' => class_exists(\Anthropic\Client::class),
        ];
        try {
            $this->app->db()->value('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'health');
            $checks['database'] = false;
        }
        $ok = $checks['storage'] && $checks['database'];
        return Response::json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503)
            ->header('X-Robots-Tag', 'noindex');
    }
}
