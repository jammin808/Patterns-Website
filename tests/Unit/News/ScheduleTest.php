<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use Psc\News\Schedule;
use Psc\News\Settings;
use Psc\Tests\TestCase;

/**
 * When sessions are due (docs/NEWS.md §4 Schedule): London time in summer and winter, the two-hour window, the
 * calendar's changes, the hour between sessions, and the counts the desk pages show.
 */
final class ScheduleTest extends TestCase
{
    private function settings(array $values = []): Settings
    {
        $s = new Settings($this->app);
        if ($values !== []) {
            self::assertSame([], $s->save($values, null)['errors']);
        }
        return new Settings($this->app);
    }

    private static function london(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('Europe/London'));
    }

    private static function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    public function testSessionsFollowLondonTimeInSummerAndWinter(): void
    {
        $s = $this->settings();
        // 22 July (BST, UTC+1): 06:00 in London is 05:00 UTC.
        self::assertNull(Schedule::due(self::utc('2027-07-22 04:59'), $s, null));
        self::assertSame('2027-07-22 06:00', Schedule::due(self::utc('2027-07-22 05:00'), $s, null));
        // 22 December (GMT): 06:00 in London is 06:00 UTC.
        self::assertNull(Schedule::due(self::utc('2027-12-22 05:30'), $s, null));
        self::assertSame('2027-12-22 06:00', Schedule::due(self::utc('2027-12-22 06:00'), $s, null));
        self::assertSame('2027-12-22 14:30', Schedule::due(self::utc('2027-12-22 14:45'), $s, null));
        // The day the clocks go back (31 October 2027) is still on London time.
        self::assertSame('2027-10-31 06:00', Schedule::due(self::utc('2027-10-31 06:00'), $s, null));
        self::assertSame('2027-03-28 06:00', Schedule::due(self::utc('2027-03-28 05:00'), $s, null), 'the day they go forward');
    }

    public function testASlotIsDueForTwoHoursAndNotTwice(): void
    {
        $s = $this->settings();
        self::assertSame('2027-01-12 06:00', Schedule::due(self::london('2027-01-12 07:59'), $s, null));
        self::assertNull(Schedule::due(self::london('2027-01-12 08:00'), $s, null), 'two hours late, it lapses');
        self::assertNull(Schedule::due(self::london('2027-01-12 06:30'), $s, null, ['2027-01-12 06:00']), 'a claimed slot is never due again');
    }

    public function testALateSlotIsStillDueAfterMidnight(): void
    {
        $s = $this->settings(['slots' => [['time' => '23:30', 'days' => ['2']]]]);
        // Tuesday 12 January 2027 at 23:30, still due at 00:45 on Wednesday.
        self::assertSame('2027-01-12 23:30', Schedule::due(self::london('2027-01-13 00:45'), $s, null));
        self::assertNull(Schedule::due(self::london('2027-01-14 00:45'), $s, null), 'Wednesday has no session');
    }

    public function testWeekdaysAndTheCalendarDecideTheDay(): void
    {
        $s = $this->settings(['slots' => [
            ['time' => '06:00', 'days' => ['1', '2', '3', '4', '5']],
            ['time' => '14:30', 'days' => ['1', '3', '5']],
        ]]);
        self::assertSame([1 => 2, 2 => 1, 3 => 2, 4 => 1, 5 => 2, 6 => 0, 7 => 0], Schedule::perDay($s));
        self::assertSame(8, Schedule::perWeek($s));
        // Saturday 18 January 2031 runs nothing by the pattern.
        self::assertNull(Schedule::due(self::london('2031-01-18 06:05'), $s, null));
        self::assertSame([], Schedule::sessionsOn('2031-01-18', $s));
        self::assertSame(['06:00'], Schedule::sessionsOn('2031-01-14', $s), 'Tuesday has the morning only');
        self::assertFalse(Schedule::isOn('2031-01-18', $s));

        // The calendar turns Saturday on (every session) and Monday off.
        $s = $this->settings(['date_overrides' => ['2031-01-18' => 'on', '2031-01-13' => 'off']]);
        self::assertSame(['06:00', '14:30'], Schedule::sessionsOn('2031-01-18', $s));
        self::assertSame('2031-01-18 14:30', Schedule::due(self::london('2031-01-18 14:40'), $s, null));
        self::assertNull(Schedule::due(self::london('2031-01-13 06:05'), $s, null));
        self::assertTrue(Schedule::isOn('2031-01-18', $s));
        self::assertFalse(Schedule::isOn('2031-01-13', $s));
        self::assertTrue(Schedule::isOnByWeekday('2031-01-13', $s), 'Monday is on by its weekday');
        self::assertFalse(Schedule::isOnByWeekday('2031-01-18', $s));
        self::assertSame([], Schedule::sessionsOn('not a date', $s));
    }

    public function testNoSessionWithinAnHourOfAnotherStarting(): void
    {
        $s = $this->settings();
        $at = self::london('2027-01-12 06:20');
        self::assertNull(Schedule::due($at, $s, ['started_at' => $at->getTimestamp() - 3599]));
        self::assertSame('2027-01-12 06:00', Schedule::due($at, $s, ['started_at' => $at->getTimestamp() - 3600]));
        self::assertSame('2027-01-12 06:00', Schedule::due($at, $s, ['started_at' => null]), 'a run that never started is no gap');
    }

    public function testAPausedDeskHasNoDueSessionButItsSlotIsStillKnown(): void
    {
        $s = $this->settings(['enabled' => '0']);
        self::assertNull(Schedule::due(self::london('2027-01-12 06:05'), $s, null));
        self::assertSame('2027-01-12 06:00', Schedule::slotDue(self::london('2027-01-12 06:05'), $s, null));
    }

    public function testWithSeveralDueTheLatestRuns(): void
    {
        $s = $this->settings(['slots' => [['time' => '06:00', 'days' => ['2']], ['time' => '07:00', 'days' => ['2']]]]);
        self::assertSame('2027-01-12 07:00', Schedule::due(self::london('2027-01-12 07:10'), $s, null));
        self::assertSame('2027-01-12 06:00', Schedule::due(self::london('2027-01-12 07:10'), $s, null, ['2027-01-12 07:00']));
    }

    public function testUpcomingSessionsInOrder(): void
    {
        $s = $this->settings(['slots' => [['time' => '06:00', 'days' => ['2', '4']], ['time' => '14:30', 'days' => ['4']]]]);
        $next = Schedule::upcoming(self::london('2027-01-12 06:00'), $s, 4);
        self::assertSame(['2027-01-12 06:00', '2027-01-14 06:00', '2027-01-14 14:30', '2027-01-19 06:00'], array_column($next, 'slot'));
        self::assertSame(self::london('2027-01-14 14:30')->getTimestamp(), $next[2]['at']);
        self::assertSame(['2027-01-14 06:00'], array_column(Schedule::upcoming(self::london('2027-01-12 06:01'), $s, 1), 'slot'));
        self::assertSame([], Schedule::upcoming(self::london('2027-01-12 06:00'), $this->settings(['slots' => []]), 6));
    }

    public function testSlotNamesAndLabels(): void
    {
        self::assertSame('Thursday 22 October 2026 at 06:00', Schedule::label('2026-10-22 06:00'));
        self::assertSame('manual-1-abc', Schedule::label('manual-1-abc'));
        self::assertSame(self::london('2026-10-22 06:00')->getTimestamp(), Schedule::at('2026-10-22', '06:00'));
        self::assertNull(Schedule::at('2026-02-30', '06:00'));
        self::assertNull(Schedule::at('2026-10-22', '6:00'));
        self::assertNull(Schedule::at('2026-10-22', '24:00'));
    }

    /** 28 March 2027: London jumps from 01:00 GMT to 02:00 BST, so 01:00 to 01:59 never happen that day. */
    public function testASessionTheSpringChangeSkipsRunsAtTheFirstMinuteAfterIt(): void
    {
        $change = self::utc('2027-03-28 01:00')->getTimestamp();
        self::assertSame($change, Schedule::at('2027-03-28', '01:30'));
        self::assertSame($change, Schedule::at('2027-03-28', '01:00'));
        self::assertSame($change, Schedule::at('2027-03-28', '01:59'));
        self::assertSame($change, Schedule::at('2027-03-28', '02:00'));
        self::assertSame(self::utc('2027-03-28 00:59')->getTimestamp(), Schedule::at('2027-03-28', '00:59'));
        self::assertSame(self::utc('2027-03-28 01:30')->getTimestamp(), Schedule::at('2027-03-28', '02:30'));

        $s = $this->settings(['slots' => [['time' => '01:30', 'days' => ['7']]]]);
        self::assertNull(Schedule::due(self::utc('2027-03-28 00:59'), $s, null));
        self::assertSame('2027-03-28 01:30', Schedule::due(self::utc('2027-03-28 01:00'), $s, null), 'due at 02:00 BST');
        self::assertSame('2027-03-28 01:30', Schedule::due(self::utc('2027-03-28 02:45'), $s, null), 'for the usual two hours');
        self::assertNull(Schedule::due(self::utc('2027-03-28 03:00'), $s, null));
        self::assertNull(Schedule::due(self::utc('2027-03-28 01:15'), $s, null, ['2027-03-28 01:30']), 'claimed, it runs once');
        $next = Schedule::upcoming(self::utc('2027-03-27 12:00'), $s, 2);
        self::assertSame([['2027-03-28 01:30', $change], ['2027-04-04 01:30', self::utc('2027-04-04 00:30')->getTimestamp()]], array_map(static fn (array $u): array => [$u['slot'], $u['at']], $next));
    }

    /** 25 October 2026: London goes from 02:00 BST back to 01:00 GMT, so 01:00 to 01:59 happen twice. */
    public function testASessionTheAutumnChangeRepeatsRunsOnce(): void
    {
        $at = self::utc('2026-10-25 01:30')->getTimestamp();
        self::assertSame($at, Schedule::at('2026-10-25', '01:30'), 'the GMT 01:30');
        $s = $this->settings(['slots' => [['time' => '01:30', 'days' => ['7']]]]);
        self::assertNull(Schedule::due(self::utc('2026-10-25 00:30'), $s, null), 'not at the first 01:30 (BST)');
        self::assertSame('2026-10-25 01:30', Schedule::due(self::utc('2026-10-25 01:30'), $s, null));
        self::assertNull(Schedule::due(self::utc('2026-10-25 01:45'), $s, null, ['2026-10-25 01:30']), 'claimed, it runs once');
        self::assertSame(['2026-10-25 01:30'], array_column(Schedule::upcoming(self::utc('2026-10-24 12:00'), $s, 1), 'slot'));
    }
}
