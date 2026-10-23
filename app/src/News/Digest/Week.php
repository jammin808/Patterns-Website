<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\News\Schedule;
use Psc\News\Settings;

/**
 * When the weekly digest goes (docs/NEWS.md §14.4). Every time here is London time through Psc\News\Schedule, so a
 * digest set for Friday 09:00 goes at 09:00 in summer and in winter alike; a time the spring change skips goes at
 * 02:00 that day, and one the autumn change repeats goes once. A week is its London ISO week ("2026-w43"), which
 * names its row in news_digests and its campaign tag: a week has one digest at most, whatever its settings.
 *
 * A week's digest is due from its send time until WINDOW later, at the first tick in that time; past it, the week is
 * missed and never sent late.
 */
final class Week
{
    /** A week's digest is due from its send time until this many seconds later. */
    public const WINDOW = 86400;

    private const DEFAULT_WEEKDAY = 5;
    private const DEFAULT_TIME = '09:00';

    /** The London ISO week of a time, in lower case: "2026-w43" (31 December 2026 is "2026-w53"). */
    public static function key(int $at): string
    {
        return self::london($at)->format('o-\wW');
    }

    /** The Monday of a week ("2026-w43" is "2026-10-19"). Throws for a name that is not a week. */
    public static function monday(string $week): string
    {
        return self::day($week, 1)->format('Y-m-d');
    }

    /** The send time of a week by the settings (their weekday and time, London), or null when it is not a week. */
    public static function sendAt(string $week, Settings $settings): ?int
    {
        try {
            $date = self::day($week, self::weekday($settings))->format('Y-m-d');
        } catch (\InvalidArgumentException) {
            return null;
        }
        return Schedule::at($date, self::time($settings));
    }

    /**
     * The week whose digest is due now, or null: of this London week's and last week's send times, the latest that
     * is at or before now, less than WINDOW ago, and whose week has no row yet ($taken). The pause is not read here,
     * so a week paused at its time is still due, and the tick records it as skipped.
     *
     * @param list<string> $taken the weeks that have a row in news_digests
     * @return array{week: string, send_at: int}|null
     */
    public static function due(\DateTimeImmutable $now, Settings $settings, array $taken): ?array
    {
        $at = $now->getTimestamp();
        $due = null;
        foreach (self::around($at, [-1, 0]) as $week) {
            $sendAt = self::sendAt($week, $settings);
            if ($sendAt === null || $sendAt > $at || $at >= $sendAt + self::WINDOW || in_array($week, $taken, true)) {
                continue;
            }
            if ($due === null || $sendAt >= $due['send_at']) {
                $due = ['week' => $week, 'send_at' => $sendAt];
            }
        }
        return $due;
    }

    /**
     * The next digest: the one due now, else the first send time after $from of a week with no row yet.
     *
     * @param list<string> $taken
     * @return array{week: string, send_at: int}
     */
    public static function next(\DateTimeImmutable $from, Settings $settings, array $taken): array
    {
        $due = self::due($from, $settings, $taken);
        if ($due !== null) {
            return $due;
        }
        $at = $from->getTimestamp();
        $weeks = self::around($at, [0, 1, 2, 3]);
        foreach ($weeks as $week) {
            $sendAt = self::sendAt($week, $settings);
            if ($sendAt !== null && $sendAt > $at && !in_array($week, $taken, true)) {
                return ['week' => $week, 'send_at' => $sendAt];
            }
        }
        // Unreachable with a valid weekday and time (a week three ahead never has a row); the default stands in.
        $week = $weeks[1];
        $date = self::day($week, self::DEFAULT_WEEKDAY)->format('Y-m-d');
        return ['week' => $week, 'send_at' => (int) Schedule::at($date, self::DEFAULT_TIME)];
    }

    /** A send time as people read it: "Friday 30 October 2026 at 09:00". */
    public static function label(int $at): string
    {
        return self::london($at)->format('l j F Y \a\t H:i');
    }

    /**
     * The day and time the public pages name ("every Friday at 09:00 UK time"), or null while the digest is paused
     * or its settings cannot be read. It never throws: a page that cannot read it says "once a week" instead.
     *
     * @return array{weekday: int, day: string, time: string}|null
     */
    public static function cadence(App $app): ?array
    {
        try {
            $settings = new Settings($app);
            if ($settings->get('digest_enabled') !== true) {
                return null;
            }
            $weekday = self::weekday($settings);
            return ['weekday' => $weekday, 'day' => Schedule::DAYS[$weekday], 'time' => self::time($settings)];
        } catch (\Throwable) {
            // No database or no settings table: the pages' words fall back, and the page itself still answers.
            return null;
        }
    }

    // ------------------------------------------------------------------------------------------------ internals

    private static function london(int $at): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(Schedule::zone());
    }

    /**
     * The weeks of $at shifted by each number of whole London weeks (by date, so a clock change never moves a time
     * into the wrong week).
     *
     * @param list<int> $offsets
     * @return list<string>
     */
    private static function around(int $at, array $offsets): array
    {
        $local = self::london($at);
        $out = [];
        foreach ($offsets as $n) {
            $out[] = ($n === 0 ? $local : $local->modify(($n > 0 ? '+' : '') . (7 * $n) . ' days'))->format('o-\wW');
        }
        return $out;
    }

    /** A weekday (1 Monday to 7 Sunday) of a week, at midnight London. */
    private static function day(string $week, int $weekday): \DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-w(\d{2})$/', $week, $m) !== 1 || $weekday < 1 || $weekday > 7) {
            throw new \InvalidArgumentException('Not a week: ' . $week);
        }
        $d = (new \DateTimeImmutable('now', Schedule::zone()))->setISODate((int) $m[1], (int) $m[2], $weekday)->setTime(0, 0);
        // A week 53 in a year of 52 rolls into the next year, so it is no week of its own.
        if ($d->format('o-\wW') !== $week) {
            throw new \InvalidArgumentException('Not a week: ' . $week);
        }
        return $d;
    }

    private static function weekday(Settings $settings): int
    {
        $weekday = $settings->get('digest_weekday');
        return is_int($weekday) && $weekday >= 1 && $weekday <= 7 ? $weekday : self::DEFAULT_WEEKDAY;
    }

    private static function time(Settings $settings): string
    {
        $time = $settings->get('digest_time');
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : self::DEFAULT_TIME;
    }
}
