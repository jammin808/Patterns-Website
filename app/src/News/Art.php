<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * A category's artwork where an article has no picture (docs/DESIGN.md 8.36): a test card drawn for the category,
 * on a screen that stays dark in both themes. The geometry is literal markup in one table (art-data.php, generated
 * at author time by the artwork's build, never edited by hand); this class only picks from it by the article's id
 * and wraps it, so the page, the share card and the build's own printer print the same bytes. The id picks the
 * composition, its take, the bar that leads the palette and the test card's edge; each take's edge map then gives
 * an edge its picture leaves clear. It is decoration: every svg is hidden from assistive technology and cannot take
 * focus, and its colours are classes in pages/news.css, never in the markup.
 */
final class Art
{
    /**
     * Each category's share card (tools/og, news-{key}.png): the id whose picture reads best at a thumbnail, with the
     * bars starting from grey.
     */
    public const SHARE = ['video' => 0, 'audio' => 0, 'lighting' => 0, 'staging' => 0, 'production' => 301, 'showcalling' => 231, 'streaming' => 0, 'automation' => 231, 'interactive' => 0, 'ai' => 0, 'technology' => 0, 'freelance' => 49, 'legal' => 0, 'tips' => 0];

    /** The generated table beside this class; a test may point it at another copy. */
    public static string $file = __DIR__ . '/art-data.php';

    /** @var array{card: array<string, list<list<string>>>, band: array<string, list<list<string>>>, edge: array{card: array<string, list<list<string>>>, band: array<string, list<list<string>>>}}|null */
    private static ?array $table = null;

    /**
     * The picture an article gets, from its id alone: a composition v (0 to 3), a take k (0 to 2), the bar that
     * leads the palette p (0 to 6) and the edge f (0 to 3) before the take's edge map. Any four ids in a row are four
     * compositions; of nine in a row, two that share one differ in take and lead. It repeats every 336 ids.
     *
     * @return array{v: int, k: int, p: int, f: int}
     */
    public static function pick(int $id): array
    {
        $i = max(0, $id) % 336;
        return [
            'v' => $i % 4,
            'k' => intdiv($i, 4) % 3,
            'p' => $i % 7,
            'f' => ($i + 3 * intdiv($i, 2) + intdiv($i, 4) + 3 * intdiv($i, 12) + intdiv($i, 84)) % 4,
        ];
    }

    /**
     * The tile: a card's 16:9 picture, the lead card's (shown whole, never cropped), or the article's band (its own
     * 16:5 picture from 40rem, the card's below). An unknown key prints nothing.
     */
    public static function tile(string $key, int $id, bool $wide = false, bool $lead = false): string
    {
        $art = self::$table ??= require self::$file;
        if (!isset($art['card'][$key])) {
            return '';
        }
        ['v' => $v, 'k' => $k, 'p' => $p, 'f' => $f] = self::pick($id);
        $edge = $art['edge'][$wide ? 'band' : 'card'][$key][$v][$k][$f];
        $s = '<span class="news-art news-art--p' . $p . ' news-art--f' . $edge . ($wide ? ' news-art--wide' : '') . '">';
        if ($wide) {
            $s .= self::svg('news-art-svg news-art-band', 576, 'slice') . $art['band'][$key][$v][$k] . '</svg>';
        }
        $s .= self::svg($wide ? 'news-art-svg news-art-tile' : 'news-art-svg', 320, $lead && !$wide ? 'meet' : 'slice') . $art['card'][$key][$v][$k] . '</svg>';
        return $s . '</span>';
    }

    private static function svg(string $class, int $width, string $fit): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 ' . $width . ' 180" preserveAspectRatio="xMidYMid ' . $fit . '" aria-hidden="true" focusable="false">';
    }
}
