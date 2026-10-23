<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News\Digest;

use Psc\News\Digest\Composer;
use Psc\News\Digest\Digests;
use Psc\News\Digest\Subscribers;
use Psc\News\Settings;
use Psc\Tests\TestCase;

/**
 * What a week's digest holds (docs/NEWS.md §14.3 Composer): windows that follow one another without a gap or an
 * overlap (a skipped week not counted, the weekday moved either way), only the articles first live in the window and
 * live now, the newest first, at most 15 with the rest counted, an empty week, a week composed twice, an article
 * taken down after composing, and the desk's preview with its sample.
 */
final class ComposerTest extends TestCase
{
    private const WEEK = 604800;

    private static function ts(string $london): int
    {
        return (new \DateTimeImmutable($london, new \DateTimeZone('Europe/London')))->getTimestamp();
    }

    private function composer(): Composer
    {
        return new Composer($this->app);
    }

    /** A digest row written directly, as a tick would have left it. */
    private function digest(string $week, string $sendAt, string $state, ?int $windowStart = null): int
    {
        $at = self::ts($sendAt);
        return $this->db->insert('news_digests', [
            'week' => $week, 'send_at' => $at, 'window_start' => $windowStart ?? $at - self::WEEK, 'window_end' => $at,
            'articles' => $state === 'skipped' ? null : '[]', 'left_out' => 0, 'state' => $state, 'recipients' => 0,
            'composed_at' => $at, 'finished_at' => $state === 'sending' ? null : $at,
        ]);
    }

    public function testTheFirstWindowIsTheWeekBeforeItsTime(): void
    {
        $send = self::ts('2026-10-23 09:00');
        self::assertSame(['start' => $send - self::WEEK, 'end' => $send], $this->composer()->window($send));
    }

    public function testEachWindowStartsWhereTheLastComposedOneEnded(): void
    {
        foreach (['sent', 'empty', 'stopped', 'sending'] as $state) {
            $this->db->exec('DELETE FROM news_digests');
            // The week before ended at 08:30 (its time was half an hour earlier then).
            $this->digest('2026-w42', '2026-10-16 08:30', $state);
            $send = self::ts('2026-10-23 09:00');
            self::assertSame(['start' => self::ts('2026-10-16 08:30'), 'end' => $send], $this->composer()->window($send), $state);
        }
    }

    public function testASkippedWeekIsNotCountedSoTheNextWindowReachesBackAWeek(): void
    {
        $this->digest('2026-w41', '2026-10-09 09:00', 'sent');
        $this->digest('2026-w42', '2026-10-16 09:00', 'skipped');
        $send = self::ts('2026-10-23 09:00');
        self::assertSame(['start' => $send - self::WEEK, 'end' => $send], $this->composer()->window($send), 'up to 7 days back, never the whole pause');
        self::assertSame('2026-w41', (new Digests($this->app))->lastComposed()['week']);
    }

    public function testMovingTheWeekdayLeavesNoGapAndNoOverlap(): void
    {
        $this->digest('2026-w42', '2026-10-16 09:00', 'sent');
        $friday = self::ts('2026-10-16 09:00');
        // Earlier: Monday of the next week, three days after.
        self::assertSame(['start' => $friday, 'end' => self::ts('2026-10-19 09:00')], $this->composer()->window(self::ts('2026-10-19 09:00')));
        // Later: Sunday of the next week, nine days after.
        self::assertSame(['start' => $friday, 'end' => self::ts('2026-10-25 09:00')], $this->composer()->window(self::ts('2026-10-25 09:00')));
        // Two weeks on, a week was missed in between: back to 7 days by the calendar (the clocks went back between).
        $later = self::ts('2026-10-30 09:00');
        self::assertSame(['start' => self::ts('2026-10-23 09:00'), 'end' => $later], $this->composer()->window($later));
    }

    /**
     * Reaching back 7 days is 7 days by the London calendar, so a clock change after a skipped week, or before the
     * first digest, moves neither end: the window starts where the skipped week's would have ended, at the same time
     * of day.
     */
    public function testReachingBackAWeekCountsLondonDaysAcrossAClockChange(): void
    {
        // Autumn: the clocks go back on 25 October 2026.
        self::assertSame(['start' => self::ts('2026-10-23 09:00'), 'end' => self::ts('2026-10-30 09:00')], $this->composer()->window(self::ts('2026-10-30 09:00')), 'the first digest');
        $this->digest('2026-w42', '2026-10-16 09:00', 'sent');
        $this->digest('2026-w43', '2026-10-23 09:00', 'skipped');
        $afterSkip = $this->makeArticle(['published_at' => self::ts('2026-10-23 09:30')]);
        $inSkip = $this->makeArticle(['published_at' => self::ts('2026-10-23 08:30')]);
        $send = self::ts('2026-10-30 09:00');
        $digest = $this->composer()->compose('2026-w44', $send, $send + 60);
        self::assertSame([self::ts('2026-10-23 09:00'), $send], [$digest['window_start'], $digest['window_end']]);
        self::assertSame([$afterSkip], $digest['articles'], 'live after the skipped week\'s time: this digest\'s; before it: the skipped week\'s, sent in none');
        self::assertNotContains($inSkip, $digest['articles']);

        // Spring: the clocks go forward on 28 March 2027.
        $this->db->exec('DELETE FROM news_digests');
        $this->digest('2027-w11', '2027-03-19 09:00', 'sent');
        $this->digest('2027-w12', '2027-03-26 09:00', 'skipped');
        self::assertSame(self::ts('2027-03-26 09:00'), $this->composer()->window(self::ts('2027-04-02 09:00'))['start'], 'never an hour of the skipped week');

        // A time the spring change skips: a Sunday digest at 01:30 went at 02:00 BST on 29 March 2026, and the week
        // after reaches back to exactly then.
        $this->db->exec('DELETE FROM news_digests');
        self::assertSame(\Psc\News\Schedule::at('2026-03-29', '01:30'), $this->composer()->window(self::ts('2026-04-05 01:30'))['start']);
    }

    /**
     * A send time the spring change moved (a Sunday 01:30 that went at 02:00 BST on 29 March 2026) reaches back to the
     * week before's own send time, 01:30, not 02:00: after a skipped week, what went live between them is this
     * digest's, and what went live before is the skipped week's, sent in none.
     */
    public function testASendTimeTheSpringChangeMovedReachesBackToTheWeekBeforesTime(): void
    {
        (new Settings($this->app))->save(['digest_weekday' => '7', 'digest_time' => '01:30'], null);
        $this->digest('2026-w11', '2026-03-15 01:30', 'sent');
        $this->digest('2026-w12', '2026-03-22 01:30', 'skipped');
        $inSkip = $this->makeArticle(['published_at' => self::ts('2026-03-22 01:15')]);
        $afterSkip = $this->makeArticle(['published_at' => self::ts('2026-03-22 01:45')]);
        $send = (int) \Psc\News\Schedule::at('2026-03-29', '01:30');
        self::assertSame(self::ts('2026-03-29 02:00'), $send, 'its time skipped, it went at 02:00 BST');
        $digest = $this->composer()->compose('2026-w13', $send, $send + 60);
        self::assertSame([self::ts('2026-03-22 01:30'), $send], [$digest['window_start'], $digest['window_end']]);
        self::assertSame([$afterSkip], $digest['articles']);
        self::assertNotContains($inSkip, $digest['articles']);
        $this->db->exec('DELETE FROM news_digests');
        self::assertSame(self::ts('2026-03-22 02:01'), $this->composer()->window(self::ts('2026-03-29 02:01'))['start'], 'a time not the settings\' own keeps its time of day');
    }

    public function testOnlyArticlesFirstLiveInTheWindowAndLiveNowAreTakenTheNewestFirst(): void
    {
        $start = self::ts('2026-10-16 09:00');
        $end = self::ts('2026-10-23 09:00');
        $now = self::ts('2026-10-23 09:05');
        $before = $this->makeArticle(['published_at' => $start - 1]);
        $first = $this->makeArticle(['published_at' => $start]);
        $newest = $this->makeArticle(['published_at' => $end - 60]);
        $middle = $this->makeArticle(['published_at' => $start + 86400]);
        $atEnd = $this->makeArticle(['published_at' => $end]);
        $down = $this->makeArticle(['published_at' => $start + 3600]);
        $this->db->exec("UPDATE news_articles SET status = 'draft' WHERE id = ?", [$down]);
        $scheduled = $this->makeArticle(['published_at' => $start + 7200]);
        $this->db->exec('UPDATE news_articles SET published_at = ? WHERE id = ?', [$now + 3600, $scheduled]);
        // Taken down and put back inside the window: it counts from when it first went live, the week before.
        $returned = $this->makeArticle(['published_at' => $start + 4 * 86400]);
        $this->db->exec('UPDATE news_articles SET first_live_at = ? WHERE id = ?', [$start - 3 * 86400, $returned]);

        self::assertSame(['ids' => [$newest, $middle, $first], 'left_out' => 0], $this->composer()->pick($start, $end, $now));
        self::assertContains($returned, $this->composer()->pick($start - self::WEEK, $start, $now)['ids'], 'in the window of its first time live');
        self::assertContains($before, $this->composer()->pick($start - self::WEEK, $start, $now)['ids']);
        self::assertNotContains($atEnd, $this->composer()->pick($start, $end, $now)['ids'], 'the end is the next window\'s');
    }

    public function testFifteenAtMostAndTheRestCounted(): void
    {
        $start = self::ts('2026-10-16 09:00');
        $ids = [];
        for ($i = 0; $i < 17; $i++) {
            $ids[] = $this->makeArticle(['published_at' => $start + 600 * ($i + 1)]);
        }
        $picked = $this->composer()->pick($start, $start + self::WEEK, $start + self::WEEK);
        self::assertSame(array_slice(array_reverse($ids), 0, Composer::MAX_ARTICLES), $picked['ids']);
        self::assertSame(2, $picked['left_out']);
    }

    public function testAWeekIsComposedOnceAndAnEmptyOneFinishesAtOnce(): void
    {
        $send = self::ts('2026-10-23 09:00');
        $now = $send + 300;
        $empty = $this->composer()->compose('2026-w43', $send, $now);
        self::assertSame(['empty', [], $now, 0, $send - self::WEEK, $send], [$empty['state'], $empty['articles'], $empty['finished_at'], $empty['recipients'], $empty['window_start'], $empty['window_end']]);

        $this->db->exec('DELETE FROM news_digests');
        $article = $this->makeArticle(['published_at' => $send - 3600]);
        $subscribers = new Subscribers($this->app);
        foreach (['a@example.test', 'b@example.test'] as $email) {
            $row = $subscribers->request($email, 'c', 'p', $now - 60)['subscriber'];
            $subscribers->confirm((string) $row['selector'], 0, $now - 30);
        }
        $subscribers->request('pending@example.test', 'c', 'p', $now - 60);
        $first = $this->composer()->compose('2026-w43', $send, $now);
        self::assertSame(['sending', [$article], null, 2, $now, '2026-w43', $send], [$first['state'], $first['articles'], $first['finished_at'], $first['recipients'], $first['composed_at'], $first['week'], $first['send_at']]);
        $this->makeArticle(['published_at' => $send - 1800]);
        $again = $this->composer()->compose('2026-w43', $send, $now + 900);
        self::assertSame($first, $again, 'a week composed twice gives the first row');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_digests'));

        $skipped = $this->composer()->skip('2026-w44', $send + self::WEEK, 'Paused', $now + self::WEEK);
        self::assertNotNull($skipped);
        self::assertSame(['skipped', null, 'Paused', $send, $send + self::WEEK], [$skipped['state'], $skipped['articles'], $skipped['note'], $skipped['window_start'], $skipped['window_end']]);
        self::assertNull($this->composer()->skip('2026-w44', $send + self::WEEK, 'Paused', $now + self::WEEK), 'another tick wrote it');
    }

    public function testAnArticleTakenDownAfterComposingIsLeftOut(): void
    {
        $send = self::ts('2026-10-23 09:00');
        $keep = $this->makeArticle(['published_at' => $send - 7200, 'title' => 'An article that stays live all week', 'categories' => ['streaming', 'video']]);
        $gone = $this->makeArticle(['published_at' => $send - 3600]);
        $digest = $this->composer()->compose('2026-w43', $send, $send + 60);
        self::assertSame([$gone, $keep], $digest['articles']);
        $this->db->exec("UPDATE news_articles SET status = 'draft' WHERE id = ?", [$gone]);

        $articles = $this->composer()->articles($digest, $send + 120);
        self::assertCount(1, $articles);
        $slug = (string) $this->db->value('SELECT slug FROM news_articles WHERE id = ?', [$keep]);
        self::assertSame(['id' => $keep, 'title' => 'An article that stays live all week', 'dek' => (string) $this->db->value('SELECT dek FROM news_articles WHERE id = ?', [$keep]),
            'category' => 'streaming', 'category_name' => 'Streaming', 'path' => '/news/streaming/' . $slug . '/'], $articles[0]);
        $shape = $this->composer()->shape($digest, $send + 120);
        self::assertSame(['id', 'week', 'send_at', 'window_start', 'window_end', 'articles', 'left_out', 'sample'], array_keys($shape));
        self::assertSame([(int) $digest['id'], '2026-w43', false], [$shape['id'], $shape['week'], $shape['sample']]);
    }

    public function testThePreviewIsTheNextDigestAsItStandsOrASample(): void
    {
        $now = new \DateTimeImmutable('now');
        $old = [];
        for ($i = 0; $i < 4; $i++) {
            $old[] = $this->makeArticle(['published_at' => $now->getTimestamp() - 30 * 86400 + $i * 60]);
        }
        // The last digest went a minute ago, so nothing is new yet: the newest three stand in.
        $this->db->insert('news_digests', ['week' => \Psc\News\Digest\Week::key($now->getTimestamp() - 60), 'send_at' => $now->getTimestamp() - 60, 'window_start' => $now->getTimestamp() - 60 - self::WEEK,
            'window_end' => $now->getTimestamp() - 60, 'articles' => '[]', 'left_out' => 0, 'state' => 'empty', 'recipients' => 0, 'composed_at' => $now->getTimestamp() - 60, 'finished_at' => $now->getTimestamp() - 60]);
        $preview = $this->composer()->preview($now);
        self::assertNull($preview['id']);
        self::assertTrue($preview['sample']);
        self::assertSame([$old[3], $old[2], $old[1]], array_column($preview['articles'], 'id'));
        $next = \Psc\News\Digest\Week::next($now, new Settings($this->app), [\Psc\News\Digest\Week::key($now->getTimestamp() - 60)]);
        self::assertSame([$next['week'], $next['send_at']], [$preview['week'], $preview['send_at']]);
        self::assertGreaterThan($now->getTimestamp(), $preview['send_at']);

        $fresh = $this->makeArticle(['published_at' => $now->getTimestamp() - 30]);
        $preview = $this->composer()->preview($now);
        self::assertFalse($preview['sample']);
        self::assertSame([$fresh], array_column($preview['articles'], 'id'), 'only what went live since the last digest');
    }
}
