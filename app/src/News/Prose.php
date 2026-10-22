<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * Rules for article text, the same for every writer (the AI desk, an import file, an administrator): no em dashes,
 * no email addresses, slugs in plain ASCII, the phrases to avoid found as whole words. All static and pure.
 */
final class Prose
{
    public const EM_DASH = "\u{2014}";
    public const EN_DASH = "\u{2013}";

    /** Read time at this many words a minute. */
    public const WORDS_PER_MINUTE = 230;

    /** The longest slug Prose::slug() makes unless asked for another length. */
    public const SLUG_MAX = 80;

    /**
     * The text without dashes used as punctuation: an en dash between digits (a range) becomes a hyphen; every other
     * em or en dash becomes a comma. The commas and spaces left over are then tidied (", ,", " ,", ",." and doubled
     * spaces), a line that now opens or ends with a comma loses it, and line breaks are kept, so Markdown keeps its
     * shape. Text without dashes comes back exactly as it was.
     */
    public static function undash(string $text): string
    {
        if (self::countDashes($text) === 0) {
            return $text;
        }
        $s = (string) preg_replace('/(?<=\d)\h*\x{2013}\h*(?=\d)/u', '-', $text);
        $s = (string) preg_replace('/\h*[\x{2014}\x{2013}]+\h*/u', ', ', $s);
        do {
            $before = $s;
            $s = (string) preg_replace('/,\h*,/u', ',', $s);
            $s = (string) preg_replace('/\h+,/u', ',', $s);
            $s = (string) preg_replace('/,\h*([.!?;:])/u', '$1', $s);
        } while ($s !== $before);
        $s = (string) preg_replace('/^\h*,\h*/um', '', $s);
        $s = (string) preg_replace('/,\h*$/um', '', $s);
        $s = (string) preg_replace('/(?<=\S)\h{2,}/u', ' ', $s);
        return $s;
    }

    /** How many em and en dashes the text holds. */
    public static function countDashes(string $text): int
    {
        return substr_count($text, self::EM_DASH) + substr_count($text, self::EN_DASH);
    }

    /**
     * Which of $phrases the text uses, as whole words and in any case, in the order of $phrases. Words inside
     * quotation marks and the text of Markdown links are someone else's, so they are skipped. Curly and straight
     * apostrophes are the same character here.
     *
     * @param list<string> $phrases
     * @return list<string>
     */
    public static function tells(string $text, array $phrases): array
    {
        $s = (string) preg_replace('/!?\[[^\]]*\]\([^)]*\)/u', ' ', $text);
        $s = (string) preg_replace('/"[^"\n]*"|\x{201C}[^\x{201D}\n]*\x{201D}|\x{2018}[^\x{2019}\n]*\x{2019}/u', ' ', $s);
        return self::mentions($s, $phrases);
    }

    /**
     * Which of $terms appear in the text as whole words, in any case (quotes included), in the order of $terms.
     *
     * @param list<string> $terms
     * @return list<string>
     */
    public static function mentions(string $text, array $terms): array
    {
        $s = str_replace("\u{2019}", "'", $text);
        $found = [];
        foreach ($terms as $term) {
            $t = trim(str_replace("\u{2019}", "'", (string) $term));
            if ($t === '') {
                continue;
            }
            $words = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $pattern = '/(?<![\p{L}\p{N}])' . implode('\s+', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)) . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $s) === 1 && !in_array($term, $found, true)) {
                $found[] = (string) $term;
            }
        }
        return $found;
    }

    /** The number of words in a text (Markdown is read as plain text first). */
    public static function words(string $text): int
    {
        return (int) preg_match_all("/[\\p{L}\\p{N}]+(?:['\u{2019}-][\\p{L}\\p{N}]+)*/u", self::plain($text));
    }

    /** Minutes to read: from a word count or from the text itself; never less than one. */
    public static function readMinutes(int|string $wordsOrText): int
    {
        $words = is_int($wordsOrText) ? $wordsOrText : self::words($wordsOrText);
        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /**
     * An address-safe slug: ASCII (through intl's transliterator, or iconv), lower case, letters, digits and single
     * hyphens only, at most $max characters, cut at a hyphen so no word is broken. Empty when nothing is left.
     */
    public static function slug(string $text, int $max = self::SLUG_MAX): string
    {
        $s = trim($text);
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            $s = $t !== null ? (string) $t->transliterate($s) : $s;
        } elseif (function_exists('iconv')) {
            $s = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        }
        $s = strtolower($s);
        $s = str_replace('&', ' and ', $s);
        $s = (string) preg_replace("/['\"`]+/", '', $s);
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        if (strlen($s) > $max) {
            $cut = substr($s, 0, $max + 1);
            $at = strrpos($cut, '-');
            $s = $at !== false && $at > 0 ? substr($cut, 0, $at) : substr($s, 0, $max);
            $s = trim($s, '-');
        }
        return $s;
    }

    /**
     * Markdown as one line of plain text: links become their text, emphasis, code marks, heading marks and list
     * markers go, and every run of whitespace becomes one space.
     */
    public static function plain(string $md): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $md);
        $s = (string) preg_replace('/^\h*```.*$/mu', '', $s);
        $s = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $s);
        $s = (string) preg_replace('/^\h*#{1,6}\h+/mu', '', $s);
        $s = (string) preg_replace('/^\h*(?:[-*+]|\d+\.)\h+/mu', '', $s);
        $s = str_replace(['**', '`'], '', $s);
        $s = (string) preg_replace('/(?<![\p{L}\p{N}*])\*(?=\S)([^*\n]+)(?<=\S)\*(?![\p{L}\p{N}*])/u', '$1', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * At most $max characters, cut at a word boundary, without trailing punctuation or space. A text that fits is
     * returned as it is (trimmed), its own full stop included.
     */
    public static function cut(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $part = mb_substr($text, 0, $max + 1);
        $at = mb_strrpos($part, ' ');
        $part = $at !== false && $at > 0 ? mb_substr($part, 0, $at) : mb_substr($text, 0, $max);
        return (string) preg_replace('/[\s\p{P}]+$/u', '', $part) ?: mb_substr($text, 0, $max);
    }

    /** Whether the text holds an email address or a mailto: link (never published: addresses get harvested). */
    public static function emails(string $text): bool
    {
        return stripos($text, 'mailto:') !== false
            || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i', $text) === 1;
    }
}
