<?php

declare(strict_types=1);

namespace Psc\Chat;

use Psc\Content\Content;

/**
 * The assistant's knowledge: the same public facts the site shows (products, features, licensing, workflows,
 * FAQ, the page list for linking) compiled from content/, plus the curated notes in knowledge/*.md. It is
 * compiled once per content change and kept in storage/cache, so every conversation sends identical text and
 * the prompt cache holds.
 */
final class Knowledge
{
    public function __construct(private readonly string $root, private readonly string $cacheDir)
    {
    }

    public function text(): string
    {
        $sources = array_merge(
            glob($this->root . '/content/*.php') ?: [],
            glob($this->root . '/content/*/*.php') ?: [],
            glob($this->root . '/knowledge/*.md') ?: [],
        );
        sort($sources);
        $stamp = '';
        foreach ($sources as $f) {
            $stamp .= $f . ':' . (string) filemtime($f) . ':' . (string) filesize($f) . ';';
        }
        $file = $this->cacheDir . '/knowledge-' . substr(hash('sha256', $stamp), 0, 16) . '.txt';
        if (is_file($file)) {
            return (string) file_get_contents($file);
        }
        $text = $this->compile();
        if (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0770, true)) {
            foreach (glob($this->cacheDir . '/knowledge-*.txt') ?: [] as $old) {
                @unlink($old);
            }
            @file_put_contents($file, $text, LOCK_EX);
        }
        return $text;
    }

    public function compile(): string
    {
        $out = [];
        $site = Content::get('site');
        $out[] = '# Patterns Show Control';
        if (!empty($site['summary'])) {
            $out[] = (string) $site['summary'];
        }
        foreach (Content::products() as $key => $p) {
            $out[] = '';
            $out[] = '# ' . $p['name'] . ' (/' . $key . '/)';
            foreach (['role_line' => 'Role', 'tagline' => 'Tagline', 'summary' => 'Summary', 'pitch' => 'In short', 'version' => 'Current version', 'os' => 'Runs on', 'distribution' => 'Distribution', 'status' => 'Status', 'external_url' => 'Own website'] as $field => $label) {
                if (!empty($p[$field]) && is_string($p[$field])) {
                    $out[] = $label . ': ' . $p[$field];
                }
            }
            if (!empty($p['audiences']) && is_array($p['audiences'])) {
                $out[] = 'For: ' . implode('; ', array_map('strval', $p['audiences']));
            }
            foreach ($p['feature_groups'] ?? [] as $g) {
                $out[] = '## ' . ($g['title'] ?? '') . (!empty($g['benefit']) ? '. ' . $g['benefit'] : '');
                foreach ($g['items'] ?? [] as $item) {
                    $out[] = '- ' . self::plain($item);
                }
            }
            if (!empty($p['integrations'])) {
                $out[] = '## Integrations';
                foreach ($p['integrations'] as $i) {
                    $out[] = '- ' . ($i['name'] ?? '') . ': ' . self::plain($i['text'] ?? '');
                }
            }
            if (!empty($p['ai']['items'])) {
                $out[] = '## AI';
                foreach ($p['ai']['items'] as $i) {
                    $out[] = '- ' . self::plain($i);
                }
            }
            if (!empty($p['requirements'])) {
                $out[] = '## System requirements';
                foreach ($p['requirements'] as $r) {
                    $out[] = '- ' . ($r['label'] ?? '') . ': ' . self::plain($r['value'] ?? '');
                }
            }
            if (!empty($p['getting_started'])) {
                $out[] = '## Getting started';
                foreach (array_values($p['getting_started']) as $i => $s) {
                    $out[] = ($i + 1) . '. ' . self::plain(is_array($s) ? ($s['text'] ?? $s['title'] ?? '') : $s);
                }
            }
            if (!empty($p['roadmap'])) {
                $out[] = '## Coming (not available yet)';
                foreach ($p['roadmap'] as $r) {
                    $out[] = '- ' . self::plain($r);
                }
            }
            foreach ($p['faqs'] ?? [] as $f) {
                $out[] = 'Q: ' . self::plain($f['q'] ?? '') . "\nA: " . self::plain($f['a'] ?? '');
            }
            $lic = Content::licensing($key);
            if ($lic !== null) {
                $out[] = '## ' . $p['name'] . ' licensing (/' . $key . '/licensing/)';
                foreach (['model', 'intro'] as $f) {
                    if (!empty($lic[$f])) {
                        $out[] = self::plain($lic[$f]);
                    }
                }
                if (($lic['pricing_status'] ?? 'live') !== 'live') {
                    $out[] = 'Prices for ' . $p['name'] . ' are not announced yet. Do not quote any price for it.';
                }
                foreach ($lic['tiers'] ?? [] as $t) {
                    $price = $t['price_label'] ?? (isset($t['price']) && $t['price'] !== null ? '£' . $t['price'] : 'price not announced');
                    // While a launch offer runs, the launch price is the price, as every page shows it.
                    $launch = Content::launchPrice($t);
                    if ($launch !== null) {
                        $price = price($launch) . ' launch price (list price ' . price(isset($t['price']) ? (string) $t['price'] : null) . ')';
                    }
                    $out[] = '- ' . ($t['name'] ?? '') . ': ' . $price . (!empty($t['machines']) ? ', ' . $t['machines'] . ' machine(s)' : '') . (!empty($t['summary']) ? '. ' . self::plain($t['summary']) : '');
                }
                foreach ($lic['extras'] ?? [] as $x) {
                    $out[] = '- ' . ($x['title'] ?? '') . ': ' . self::plain($x['text'] ?? '');
                }
                foreach ($lic['policies'] ?? [] as $x) {
                    $out[] = '- ' . ($x['title'] ?? '') . ': ' . self::plain($x['text'] ?? '');
                }
                if (!empty($lic['matrix']['groups'])) {
                    $cols = $lic['matrix']['columns'] ?? [];
                    $out[] = 'Feature availability by tier (' . implode(' / ', array_map('strval', $cols)) . '):';
                    foreach ($lic['matrix']['groups'] as $grp) {
                        foreach ($grp['rows'] ?? [] as $row) {
                            $cells = [];
                            foreach ($cols as $c) {
                                $v = $row['values'][$c] ?? null;
                                $cells[] = $v === true ? 'yes' : ($v === false || $v === null ? 'no' : (string) $v);
                            }
                            $out[] = '- ' . self::plain($row['feature'] ?? '') . ': ' . implode(' / ', $cells);
                        }
                    }
                }
                foreach ($lic['how_to_buy'] ?? [] as $i => $s) {
                    $out[] = 'How to buy ' . ((int) $i + 1) . ': ' . self::plain(is_array($s) ? ($s['text'] ?? '') : $s);
                }
                foreach ($lic['faqs'] ?? [] as $f) {
                    $out[] = 'Q: ' . self::plain($f['q'] ?? '') . "\nA: " . self::plain($f['a'] ?? '');
                }
            }
        }
        $workflows = Content::get('workflows');
        if ($workflows !== []) {
            $out[] = '';
            $out[] = '# Workflows (/workflows/)';
            foreach ($workflows as $slug => $w) {
                $out[] = '## ' . ($w['title'] ?? $slug) . ' (/workflows/' . $slug . '/)';
                if (!empty($w['summary'])) {
                    $out[] = self::plain($w['summary']);
                }
                foreach ($w['steps'] ?? [] as $i => $s) {
                    $out[] = ((int) $i + 1) . '. ' . self::plain(($s['title'] ?? '') . ': ' . ($s['text'] ?? ''));
                }
            }
        }
        foreach (Content::get('faq') as $group) {
            if (!is_array($group)) {
                continue;
            }
            $out[] = '';
            $out[] = '# FAQ: ' . ($group['title'] ?? '');
            foreach ($group['items'] ?? [] as $f) {
                $out[] = 'Q: ' . self::plain($f['q'] ?? '') . "\nA: " . self::plain($f['a'] ?? '');
            }
        }
        foreach (glob($this->root . '/knowledge/*.md') ?: [] as $md) {
            $out[] = '';
            $out[] = trim((string) file_get_contents($md));
        }
        $out[] = '';
        $out[] = '# Pages on this site (link with these relative paths)';
        // Each line is "path (name): what it is": a name and its line are joined by a colon here, never a dash.
        foreach (Content::pages() as $path => $page) {
            if (empty($page['noindex'])) {
                $out[] = '- ' . $path . ' (' . ($page['crumb'] ?? $page['title']) . '): ' . $page['description'];
            }
        }
        $out[] = '- /portal/register/: create a free client account';
        $out[] = '- /portal/login/: client sign-in';
        $out[] = '- /portal/tickets/new/: report a bug or ask for help (account needed)';
        $out[] = '- /portal/ideas/: feature requests board and votes (account needed)';
        $out[] = '- /portal/downloads/: downloads (account needed)';
        $out[] = '- /portal/licences/request/: request a licence (account needed)';
        return trim(implode("\n", $out)) . "\n";
    }

    private static function plain(mixed $v): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = strip_tags($s);
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
