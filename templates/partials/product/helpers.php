<?php

declare(strict_types=1);

/*
 * Pure helpers for the product templates (they print nothing). Included with require, so they return closures and
 * declare nothing globally:
 *
 *   $h = require PSC_ROOT . '/templates/partials/product/helpers.php';
 *   $h['words'](6)            "six"
 *   $h['mark']('OSC')         ['mark' => 'OSC', 'product' => null]   the integration chip's abbreviation (no logos)
 *   $h['tier']($tier)         ['amount', 'was', 'unit', 'flag', 'flagOutline', 'free', 'tbc'] for a price line
 *   $h['lead']($pitch, $def)  the pitch without a first sentence that repeats the definition
 *   $h['related']($text, $k)  the other products a sentence names, in the site's order
 *   $h['nowrap']($heading)    the heading escaped, hyphenated words kept on one line
 */

use Psc\Content\Content;

// Built inside a closure, so including this file sets no variables in the template that requires it.
return (static function (): array {
    $words = static function (int $n): string {
        $w = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
        return $w[$n] ?? (string) $n;
    };

    $mark = static function (string $name): array {
        foreach (Content::PRODUCTS as $key) {
            $p = Content::product($key);
            if ($p !== null && str_starts_with($name, (string) $p['name'])) {
                return ['mark' => null, 'product' => $key];
            }
        }
        // First match wins, so the narrower names come before the words they contain.
        $rules = [
            'companion' => 'BFC', 'show network' => 'BUS', 'omt' => 'OMT', 'spout' => 'SPT', 'ndi' => 'NDI', 'osc' => 'OSC',
            'midi' => 'MIDI', 'art-net' => 'DMX', 'ltc' => 'LTC', 'timecode' => 'LTC', 'mcp' => 'MCP', 'claude' => 'AI',
            'google slides' => 'WEB', 'powerpoint' => 'PPT', 'vision mixer' => 'KEY', 'obs' => 'OBS', 'vmix' => 'VMX',
            'http' => 'HTTP', 'pdf' => 'PDF', 'sheets' => 'CSV', 'excel' => 'CSV', 'zoom' => 'CALL', 'arduino' => 'I/O',
            'raspberry' => 'WEB', 'phones' => 'WEB', 'browser' => 'WEB', 'pjlink' => 'PJL', 'projector' => 'PJL',
            'media server' => 'CTL', 'streaming' => 'RTMP', 'capture' => 'SDI', 'spotify' => 'SPF', 'weather' => 'RSS',
            'remote access' => 'WAN', 'survey' => '3D',
        ];
        $lower = strtolower($name);
        foreach ($rules as $needle => $abbr) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '/', $lower)) {
                return ['mark' => $abbr, 'product' => null];
            }
        }
        preg_match_all('/\b([A-Za-z])/', $name, $m);
        return ['mark' => strtoupper(implode('', array_slice($m[1], 0, 3))), 'product' => null];
    };

    $tier = static function (array $t): array {
        $price = isset($t['price']) && is_numeric($t['price']) ? (string) $t['price'] : null;
        $label = (string) ($t['price_label'] ?? '');
        $launch = null;
        if (!empty($t['price_note']) && preg_match('/£\s?(\d+(?:\.\d+)?)/', (string) $t['price_note'], $m)) {
            $launch = $m[1];
        }
        $amount = $launch !== null ? price($launch) : ($price !== null ? price($price) : ($label !== '' ? $label : 'Coming soon'));
        $machines = isset($t['machines']) && is_numeric($t['machines']) ? (int) $t['machines'] : null;
        $backup = (bool) preg_grep('/backup/i', array_map('strval', $t['includes'] ?? []));
        $where = match (true) {
            $machines === null => null,
            $machines === 1 => 'one PC',
            $backup && $machines === 2 => 'main and backup PC',
            default => $machines . ' PCs',
        };
        $period = trim((string) ($t['period'] ?? ''));
        if ($price !== null && (float) $price === 0.0) {
            $unit = $label !== '' && $label !== $amount ? $label : '';
            if ($period !== '' && stripos($unit, $period) === false) {
                $unit = trim($unit . ' ' . $period);
            }
            $unit = ucfirst($unit);
        } elseif ($price === null) {
            $unit = $period !== '' ? ucfirst($period) : null;
        } else {
            $unit = implode(', ', array_filter([ucfirst($period), $where]));
        }
        return [
            'amount' => $amount,
            'was' => $launch !== null && $price !== null ? price($price) : null,
            'unit' => $unit !== '' ? $unit : null,
            'flag' => $t['badge'] ?? ($launch !== null ? 'Launch price' : null),
            'flagOutline' => empty($t['badge']) && $launch !== null,
            'free' => $price !== null && (float) $price === 0.0,
            'tbc' => $price === null,
        ];
    };

    $lead = static function (string $pitch, string $definition): string {
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', trim($pitch), 2) ?: [$pitch];
        $first = rtrim($parts[0], '.');
        if (count($parts) === 2 && $first !== '' && str_starts_with($definition, $first)) {
            return $parts[1];
        }
        return $pitch;
    };

    $related = static function (string $text, string $self): array {
        $out = [];
        foreach (Content::PRODUCTS as $key) {
            $p = Content::product($key);
            if ($key !== $self && $p !== null && str_contains($text, (string) $p['name'])) {
                $out[] = $key;
            }
        }
        return $out;
    };

    // Escapes a heading and keeps hyphenated words ("one-off", "show-calling") whole, so a line never breaks at the hyphen.
    $nowrap = static fn (string $text): string => (string) preg_replace('/([\p{L}\d]+(?:-[\p{L}\d]+)+)/u', '<span class="u-nowrap">$1</span>', e($text));

    return ['words' => $words, 'mark' => $mark, 'tier' => $tier, 'lead' => $lead, 'related' => $related, 'nowrap' => $nowrap];
})();
