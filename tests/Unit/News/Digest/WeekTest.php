<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News\Digest;

use Psc\News\Digest\Week;
use Psc\News\Settings;
use Psc\Tests\TestCase;

/**
 * When the weekly digest is due (docs/NEWS.md §14.4, Week): the London ISO week that names it, its send time in GMT,
 * in BST and across both clock changes, the 24 hours it stays due, a week that already has a row, the weekday moved,
 * the next one, and the words the public pages read.
 */
final class WeekTest extends TestCase
{
    /** @param array<string, mixed> $values */
    private function settings(array $values = []): Settings
    {
        if ($values !== []) {
            self::assertSame([], (new Settings($this->app))->save($values, null)['errors']);
        }
        return new Settings($this->app);
    }

    private static function london(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('Europe/London'));
    }

    private static function ts(string $london): int
    {
        return self::london($london)->getTimestamp();
    }

    public function testAWeekIsItsLondonIsoWeekInLowerCase(): void
    {
        self::assertSame('2026-w43', Week::key(self::ts('2026-10-23 09:00')));
        self::assertSame('2026-w53', Week::key(self::ts('2026-12-31 12:00')), 'the ISO year 2026 has 53 weeks');
        self::assertSame('2026-w53', Week::key(self::ts('2027-01-03 23:59')));
        self::assertSame('2027-w01', Week::key(self::ts('2027-01-04 00:00')));
        // 23:30 on a Sunday in summer is 22:30 UTC, still that Sunday's week in London.
        self::assertSame('2026-w30', Week::key(self::ts('2026-07-26 23:30')));
        self::assertSame('2026-w31', Week::key(self::ts('2026-07-27 00:10')), 'just after midnight in London is the next week, though UTC is still Sunday');

        self::assertSame('2026-10-19', Week::monday('2026-w43'));
        self::assertSame('2026-12-28', Week::monday('2026-w53'));
        self::assertSame('2027-01-04', Week::monday('2027-w01'));
        $this->expectException(\InvalidArgumentException::class);
        Week::monday('2027-w53');
    }

    public function testTheSendTimeIsTheWeekdayAndTimeInLondon(): void
    {
        $s = $this->settings();
        self::assertSame(self::ts('2026-10-23 09:00'), Week::sendAt('2026-w43', $s), 'Friday 09:00 by default, in BST');
        self::assertSame(gmmktime(9, 0, 0, 10, 30, 2026), Week::sendAt('2026-w44', $s), 'in GMT 09:00 London is 09:00 UTC');
        self::assertSame(gmmktime(8, 0, 0, 10, 23, 2026), Week::sendAt('2026-w43', $s), 'in BST it is 08:00 UTC');
        self::assertNull(Week::sendAt('not-a-week', $s));
        self::assertNull(Week::sendAt('2027-w53', $s));

        // A Sunday 01:30 the spring change skips (29 March 2026) goes at 02:00 BST, the first minute after the change.
        $spring = $this->settings(['digest_weekday' => '7', 'digest_time' => '01:30']);
        self::assertSame(self::ts('2026-03-29 02:00'), Week::sendAt('2026-w13', $spring));
        self::assertSame(gmmktime(1, 0, 0, 3, 29, 2026), Week::sendAt('2026-w13', $spring));

        // The autumn change repeats 01:30 (25 October 2026): one instant, so the week is due once.
        $autumn = Week::sendAt('2026-w43', $spring);
        self::assertNotNull($autumn);
        $due = Week::due(new \DateTimeImmutable('@' . $autumn), $spring, []);
        self::assertSame(['week' => '2026-w43', 'send_at' => $autumn], $due);
        self::assertNull(Week::due(new \DateTimeImmutable('@' . ($autumn + 3600)), $spring, ['2026-w43']), 'the repeated hour finds the week taken');
    }

    public function testAWeekIsDueFromItsTimeForADay(): void
    {
        $s = $this->settings();
        self::assertNull(Week::due(self::london('2026-10-23 08:59'), $s, []), 'not before its time');
        $at = self::ts('2026-10-23 09:00');
        self::assertSame(['week' => '2026-w43', 'send_at' => $at], Week::due(self::london('2026-10-23 09:00'), $s, []));
        self::assertSame(['week' => '2026-w43', 'send_at' => $at], Week::due(self::london('2026-10-24 08:59'), $s, []), 'within 24 hours');
        self::assertNull(Week::due(self::london('2026-10-24 09:00'), $s, []), 'missed after 24 hours, never sent late');
        self::assertNull(Week::due(self::london('2026-10-23 09:05'), $s, ['2026-w43']), 'a week with a row is never due again');
        self::assertNull(Week::due(self::london('2026-10-21 12:00'), $s, []), 'midweek nothing is due');
    }

    public function testALateSundayTimeIsStillDueOnMonday(): void
    {
        $s = $this->settings(['digest_weekday' => '7', 'digest_time' => '23:30']);
        $sunday = self::ts('2026-10-25 23:30');
        self::assertSame(['week' => '2026-w43', 'send_at' => $sunday], Week::due(self::london('2026-10-26 00:10'), $s, []), 'last week\'s time, 40 minutes ago');
        self::assertNull(Week::due(self::london('2026-10-26 00:10'), $s, ['2026-w43']));
        self::assertSame(['week' => '2026-w43', 'send_at' => $sunday], Week::due(self::london('2026-10-26 23:29'), $s, []));
        self::assertNull(Week::due(self::london('2026-10-26 23:30'), $s, []));
    }

    public function testMovingTheWeekdayNeverSendsAWeekTwice(): void
    {
        // Friday's digest went; the weekday moves to Saturday in the same week: that week has its row.
        $s = $this->settings(['digest_weekday' => '6']);
        self::assertNull(Week::due(self::london('2026-10-24 09:05'), $s, ['2026-w43']));
        self::assertSame('2026-w44', Week::next(self::london('2026-10-24 09:05'), $s, ['2026-w43'])['week']);
        self::assertSame(self::ts('2026-10-31 09:00'), Week::next(self::london('2026-10-24 09:05'), $s, ['2026-w43'])['send_at']);

        // Moved to Monday: the next week's Monday is due on its own.
        $monday = $this->settings(['digest_weekday' => '1']);
        self::assertSame(['week' => '2026-w44', 'send_at' => self::ts('2026-10-26 09:00')], Week::due(self::london('2026-10-26 09:00'), $monday, ['2026-w43']));
    }

    public function testTheNextDigest(): void
    {
        $s = $this->settings();
        self::assertSame(['week' => '2026-w43', 'send_at' => self::ts('2026-10-23 09:00')], Week::next(self::london('2026-10-21 12:00'), $s, []), 'later this week');
        self::assertSame(['week' => '2026-w43', 'send_at' => self::ts('2026-10-23 09:00')], Week::next(self::london('2026-10-23 10:00'), $s, []), 'the one due now');
        self::assertSame(['week' => '2026-w44', 'send_at' => self::ts('2026-10-30 09:00')], Week::next(self::london('2026-10-23 10:00'), $s, ['2026-w43']), 'next week once this one has gone');
        self::assertSame(['week' => '2026-w44', 'send_at' => self::ts('2026-10-30 09:00')], Week::next(self::london('2026-10-24 10:00'), $s, []), 'a missed week is not next');
        self::assertSame('Friday 30 October 2026 at 09:00', Week::label(self::ts('2026-10-30 09:00')));
    }

    public function testTheCadenceIsNullWhilePausedOrUnreadable(): void
    {
        self::assertSame(['weekday' => 5, 'day' => 'Friday', 'time' => '09:00'], Week::cadence($this->app));
        $this->settings(['digest_weekday' => '2', 'digest_time' => '07:45']);
        self::assertSame(['weekday' => 2, 'day' => 'Tuesday', 'time' => '07:45'], Week::cadence($this->app));
        $this->settings(['digest_enabled' => false]);
        self::assertNull(Week::cadence($this->app), 'paused');
        $this->settings(['digest_enabled' => true]);
        self::assertNotNull(Week::cadence($this->app));
        // A database with no tables (the empty fallback while db.dsn is missing): null, never an exception.
        $this->app->useDatabase(\Psc\Db\Database::connect('sqlite::memory:'));
        self::assertNull(Week::cadence($this->app));
    }
}
