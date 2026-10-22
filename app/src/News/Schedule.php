<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * When the desk's sessions run (docs/NEWS.md §4, Schedule). Every time here is London time, so a session set for
 * 06:00 runs at 06:00 in summer and in winter alike, whatever zone the server's clock is in. The cron ticks every
 * 15 minutes; a session is due from its time until two hours later, so a missed tick or a busy server delays it
 * rather than losing it, and a claimed slot (Runs::claim) is never run twice.
 *
 * A day's sessions are the sessions whose weekdays include it, unless the calendar changes the day: "off" runs
 * none, "on" runs every session. A slot's name is its London date and time ("2026-10-22 06:00").
 */
final class Schedule
{
    public const ZONE = 'Europe/London';

    /** A session is due from its time until this many seconds later. */
    public const WINDOW = 7200;

    /** No session starts within this many seconds of another one starting (manual runs included). */
    public const GAP = 3600;

    /** Weekday numbers (ISO: 1 Monday to 7 Sunday) and their names. */
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * The slot due now, or null. A slot is due when the London time is at or after its time and less than two hours
     * after it, its day runs it (the weekday is ticked for it, or the calendar turned the day on, and the calendar
     * did not turn it off), the desk is on, and no run of any kind started within the last hour. With several due,
     * the latest is returned: an older one that missed its turn lapses rather than running late beside it.
     *
     * @param array<string, mixed>|null $lastRun the run that started most recently (Runs::lastStarted())
     * @param list<string> $taken slots already claimed, which are never due again
     */
    public static function due(\DateTimeImmutable $now, Settings $settings, ?array $lastRun, array $taken = []): ?string
    {
        return $settings->get('enabled') === true ? self::slotDue($now, $settings, $lastRun, $taken) : null;
    }

    /**
     * The slot due now by the schedule alone, whether or not the desk is paused: the tick records a paused desk's
     * slot as skipped, so the run list shows the sessions a pause cost.
     *
     * @param array<string, mixed>|null $lastRun
     * @param list<string> $taken
     */
    public static function slotDue(\DateTimeImmutable $now, Settings $settings, ?array $lastRun, array $taken = []): ?string
    {
        $at = $now->getTimestamp();
        if ($lastRun !== null && isset($lastRun['started_at']) && (int) $lastRun['started_at'] > $at - self::GAP) {
            return null;
        }
        $local = $now->setTimezone(self::zone());
        $due = null;
        $dueAt = 0;
        // A slot late in the evening is still due after midnight, so yesterday's slots are looked at too.
        foreach ([$local->modify('-1 day'), $local] as $day) {
            $date = $day->format('Y-m-d');
            foreach (self::sessionsOn($date, $settings) as $time) {
                $slotAt = self::at($date, $time);
                $slot = $date . ' ' . $time;
                if ($slotAt === null || $at < $slotAt || $at >= $slotAt + self::WINDOW || in_array($slot, $taken, true)) {
                    continue;
                }
                if ($slotAt >= $dueAt) {
                    $due = $slot;
                    $dueAt = $slotAt;
                }
            }
        }
        return $due;
    }

    /**
     * The next $count sessions from $from (its own minute included), the soonest first, looked for up to a year ahead.
     *
     * @return list<array{date: string, time: string, slot: string, at: int}>
     */
    public static function upcoming(\DateTimeImmutable $from, Settings $settings, int $count): array
    {
        $out = [];
        $start = $from->getTimestamp();
        $day = $from->setTimezone(self::zone())->setTime(0, 0);
        for ($i = 0; $i < 366 && count($out) < $count; $i++, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            foreach (self::sessionsOn($date, $settings) as $time) {
                $at = self::at($date, $time);
                if ($at !== null && $at >= $start - ($start % 60)) {
                    $out[] = ['date' => $date, 'time' => $time, 'slot' => $date . ' ' . $time, 'at' => $at];
                    if (count($out) >= $count) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * How many sessions each weekday runs by the weekly pattern (the calendar's changes aside).
     *
     * @return array<int, int> weekday 1 to 7 => sessions
     */
    public static function perDay(Settings $settings): array
    {
        $out = array_fill_keys(array_keys(self::DAYS), 0);
        foreach (self::slots($settings) as $slot) {
            foreach ($slot['days'] as $day) {
                $out[$day]++;
            }
        }
        return $out;
    }

    /** How many sessions a week the weekly pattern runs. */
    public static function perWeek(Settings $settings): int
    {
        return array_sum(self::perDay($settings));
    }

    /** Whether any session runs on a date (YYYY-MM-DD, London). */
    public static function isOn(string $date, Settings $settings): bool
    {
        return self::sessionsOn($date, $settings) !== [];
    }

    /** Whether the weekly pattern runs any session on a date, before the calendar's changes. */
    public static function isOnByWeekday(string $date, Settings $settings): bool
    {
        $weekday = self::weekday($date);
        return $weekday !== null && self::perDay($settings)[$weekday] > 0;
    }

    /**
     * The session times (HH:MM) a date runs, in order: none when the calendar turned it off, every session when it
     * turned it on, otherwise the sessions whose weekdays include it.
     *
     * @return list<string>
     */
    public static function sessionsOn(string $date, Settings $settings): array
    {
        $weekday = self::weekday($date);
        if ($weekday === null) {
            return [];
        }
        $override = $settings->get('date_overrides')[$date] ?? null;
        if ($override === 'off') {
            return [];
        }
        $times = [];
        foreach (self::slots($settings) as $slot) {
            if ($override === 'on' || in_array($weekday, $slot['days'], true)) {
                $times[] = $slot['time'];
            }
        }
        return $times;
    }

    /**
     * The Unix time of a London date and time, or null when either is not one. A time the spring clock change skips
     * (01:00 to 01:59) is the first minute after the change (02:00 BST), so a session set then runs late that day,
     * never not at all; a time the autumn change repeats is its one GMT instant, so it runs once.
     */
    public static function at(string $date, string $time): ?int
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, self::zone());
        if ($d === false || $d->format('Y-m-d') !== $date) {
            return null;
        }
        // PHP moves a skipped time on by the hour the clocks jump, into the hour from 02:00; its first minute is the change.
        return $d->format('H:i') === $time ? $d->getTimestamp() : $d->setTime((int) $d->format('G'), 0)->getTimestamp();
    }

    /** A slot's name as people read it: "Thursday 22 October 2026 at 06:00", or as given when it is not a date. */
    public static function label(string $slot): string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $slot, $m)) {
            return $slot;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], self::zone());
        return $d !== false ? $d->format('l j F Y') . ' at ' . $m[2] : $slot;
    }

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONE);
    }

    /**
     * The stored sessions, each a time and its weekdays, the earliest first.
     *
     * @return list<array{time: string, days: list<int>}>
     */
    private static function slots(Settings $settings): array
    {
        $out = [];
        foreach ((array) $settings->get('slots') as $slot) {
            if (!is_array($slot) || !is_string($slot['time'] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slot['time'])) {
                continue;
            }
            $days = array_values(array_filter(array_map('intval', (array) ($slot['days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7));
            $out[] = ['time' => $slot['time'], 'days' => array_values(array_unique($days))];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['time'], $b['time']));
        return $out;
    }

    private static function weekday(string $date): ?int
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::zone());
        return $d !== false && $d->format('Y-m-d') === $date ? (int) $d->format('N') : null;
    }
}
