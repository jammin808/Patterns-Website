<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News\Digest;

use Psc\App;
use Psc\Config;
use Psc\Mail\Delivery;
use Psc\News\Digest\Digests;
use Psc\News\Digest\Sender;
use Psc\News\Digest\Sends;
use Psc\News\Digest\Subscribers;
use Psc\News\Settings;
use Psc\Tests\Fakes\FakeCourier;
use Psc\Tests\TestCase;

/**
 * The digest's step of the tick (docs/NEWS.md §14.4, Sender): composed at its time and never before, a paused week
 * skipped, a pause mid-send holding the rest, each cap, the tick's time limit, each outcome of §14.4's table with what
 * it writes and whether the batch goes on, nobody who left or joined since composing, a row of unknown outcome never
 * sent again, the 48-hour limit, every article taken down, a blocked mailbox, and the fence that nobody ever gets two
 * copies. FakeCourier checks, at each message, that its send row is already claimed.
 */
final class SenderTest extends TestCase
{
    private const SEND = '2026-10-23 09:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(['mail' => ['unsubscribe_to' => 'unsubscribe@example.test']]);
    }

    private static function at(string $london): \DateTimeImmutable
    {
        return new \DateTimeImmutable($london, new \DateTimeZone('Europe/London'));
    }

    private static function ts(string $london): int
    {
        return self::at($london)->getTimestamp();
    }

    private function courier(): FakeCourier
    {
        return new FakeCourier($this->db);
    }

    /** @return array<string, mixed> one tick's report */
    private function tick(string $london, FakeCourier $courier, ?\Closure $clock = null, ?App $app = null): array
    {
        return (new Sender($app ?? $this->app, $courier, $clock))->tick(self::at($london));
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values): void
    {
        self::assertSame([], (new Settings($this->app))->save($values, null)['errors']);
    }

    /** Confirmed subscribers, confirmed on the Tuesday before the send; their ids in order. */
    private function subscribers(int $n, string $confirmed = '2026-10-20 12:00'): array
    {
        $s = new Subscribers($this->app);
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $row = $s->request('reader' . $i . '@example.test', 'c', 'p', self::ts($confirmed))['subscriber'];
            self::assertSame('confirmed', $s->confirm((string) $row['selector'], 0, self::ts($confirmed)));
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /** An article that went live on the Thursday before the send. */
    private function article(string $london = '2026-10-22 12:00'): int
    {
        return $this->makeArticle(['published_at' => self::ts($london)]);
    }

    /** @return array<string, mixed> */
    private function digestRow(string $week = '2026-w43'): array
    {
        $row = (new Digests($this->app))->forWeek($week);
        self::assertNotNull($row);
        return $row;
    }

    /** @return array<int, array<string, mixed>> the digest's send rows by subscriber */
    private function sendRows(int $digestId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT * FROM news_digest_sends WHERE digest_id = ? ORDER BY id', [$digestId]) as $row) {
            $out[(int) $row['subscriber_id']] = $row;
        }
        return $out;
    }

    /** An App on the same database and storage with other mail settings. */
    private function appWith(array $mail): App
    {
        $app = App::boot(Config::fromArray(PSC_ROOT, [
            'app' => ['url' => 'https://patternsshowcontrol.test', 'env' => 'testing', 'debug' => true, 'secret' => str_repeat('ab', 32), 'force_https' => false],
            'storage' => ['path' => $this->storage],
            'mail' => $mail + ['driver' => 'log', 'unsubscribe_to' => 'unsubscribe@example.test'],
            'db' => ['auto_migrate' => false],
            'chat' => ['api_key' => ''],
        ]));
        $app->useDatabase($this->db);
        return $app;
    }

    public function testAWeekIsComposedAtItsTimeAndSentToEveryone(): void
    {
        $article = $this->article();
        $ids = $this->subscribers(3);
        $courier = $this->courier();

        $early = $this->tick('2026-10-23 08:59', $courier);
        self::assertSame(['idle', null, 0], [$early['status'], $early['week'], $early['sent']]);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_digests'), 'nothing before its time');

        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['status' => 'sent', 'week' => '2026-w43', 'sent' => 3, 'bounced' => 0, 'retry' => 0, 'failed' => 0, 'left' => 0, 'note' => null], $report);
        $row = $this->digestRow();
        self::assertSame(['sent', 3, [$article], self::ts('2026-10-23 09:05')], [$row['state'], $row['recipients'], $row['articles'], $row['finished_at']]);
        self::assertSame(['reader1@example.test', 'reader2@example.test', 'reader3@example.test'], $courier->digestsTo());
        $digest = $courier->calls[0]['digest'];
        self::assertSame([(int) $row['id'], '2026-w43', [$article], false], [$digest['id'], $digest['week'], array_column($digest['articles'], 'id'), $digest['sample']]);
        foreach ($this->sendRows((int) $row['id']) as $subscriber => $send) {
            self::assertContains($subscriber, $ids);
            self::assertSame(['sent', self::ts('2026-10-23 09:05'), 1], [$send['state'], (int) $send['sent_at'], (int) $send['attempts']]);
        }

        $later = $this->tick('2026-10-23 09:20', $courier);
        self::assertSame('idle', $later['status']);
        self::assertCount(3, $courier->digestsTo(), 'a week is sent once');
    }

    public function testAWeekWithNoNewArticleSendsNothing(): void
    {
        $this->subscribers(2);
        $this->article('2026-10-10 12:00');
        $courier = $this->courier();
        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['empty', '2026-w43'], [$report['status'], $report['week']]);
        self::assertSame('empty', $this->digestRow()['state']);
        self::assertSame([], $courier->calls);
        self::assertSame('idle', $this->tick('2026-10-23 09:20', $courier)['status']);
    }

    public function testAPausedWeekIsSkippedWithItsNote(): void
    {
        $this->subscribers(2);
        $this->article();
        $this->settings(['digest_enabled' => false]);
        $courier = $this->courier();
        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['skipped', '2026-w43', Sender::NOTES['paused']], [$report['status'], $report['week'], $report['note']]);
        $row = $this->digestRow();
        self::assertSame(['skipped', Sender::NOTES['paused'], null], [$row['state'], $row['note'], $row['articles']]);
        $this->settings(['digest_enabled' => true]);
        self::assertSame('idle', $this->tick('2026-10-23 09:20', $courier)['status'], 'continuing never sends a skipped week late');
        self::assertSame([], $courier->calls);
    }

    public function testAPauseMidSendHoldsTheRestUntilItIsContinued(): void
    {
        $this->article();
        $this->subscribers(5);
        $this->settings(['digest_per_tick' => '2']);
        $courier = $this->courier();
        self::assertSame(['sending', 2, 3], array_values(array_intersect_key($this->tick('2026-10-23 09:05', $courier), ['status' => 1, 'sent' => 1, 'left' => 1])));
        $this->settings(['digest_enabled' => false]);
        $paused = $this->tick('2026-10-23 09:20', $courier);
        self::assertSame(['paused', '2026-w43', 0, 3], [$paused['status'], $paused['week'], $paused['sent'], $paused['left']]);
        self::assertCount(2, $courier->digestsTo());
        $this->settings(['digest_enabled' => true]);
        self::assertSame(['sending', 2, 1], array_values(array_intersect_key($this->tick('2026-10-23 09:35', $courier), ['status' => 1, 'sent' => 1, 'left' => 1])));
        self::assertSame(['sent', 1, 0], array_values(array_intersect_key($this->tick('2026-10-23 09:50', $courier), ['status' => 1, 'sent' => 1, 'left' => 1])));
        self::assertSame(['reader1@example.test', 'reader2@example.test', 'reader3@example.test', 'reader4@example.test', 'reader5@example.test'], $courier->digestsTo());
    }

    public function testTheHourlyAndDailyCapsCountTheMailboxsEarlierSends(): void
    {
        $this->article();
        $ids = $this->subscribers(5);
        $now = self::ts('2026-10-23 09:05');
        $sender = new Sender($this->app, $this->courier());
        self::assertSame(25, $sender->allowance($now), 'the per-tick cap first');

        // An earlier digest's sends: two claimed 30 minutes ago, one 20 hours ago, and one refused before it went.
        $old = $this->db->insert('news_digests', ['week' => '2026-w42', 'send_at' => $now - 604800, 'window_start' => $now - 2 * 604800, 'window_end' => $now - 604800, 'articles' => '[]', 'left_out' => 0, 'state' => 'sent', 'recipients' => 4, 'composed_at' => $now - 604800, 'finished_at' => $now - 604800]);
        foreach ([[$ids[0], 'sent', 1800], [$ids[1], 'failed', 1800], [$ids[2], 'sent', 20 * 3600], [$ids[3], 'retry', 600]] as [$subscriber, $state, $ago]) {
            $this->db->insert('news_digest_sends', ['digest_id' => $old, 'subscriber_id' => $subscriber, 'state' => $state, 'attempts' => 1, 'claimed_at' => $now - $ago]);
        }
        $this->settings(['digest_per_hour' => '4', 'digest_per_day' => '10']);
        self::assertSame(2, $sender->allowance($now), 'the hour: 4 less the two of the last hour; a retry is not counted');
        $this->settings(['digest_per_hour' => '200', 'digest_per_day' => '5']);
        self::assertSame(2, $sender->allowance($now), 'the 24 hours: 5 less three');
        $this->settings(['digest_per_day' => '3']);
        self::assertSame(0, $sender->allowance($now), 'never below 0');

        $courier = $this->courier();
        $waiting = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['waiting', '2026-w43', 5], [$waiting['status'], $waiting['week'], $waiting['left']]);
        self::assertSame([], $courier->calls);

        // Once the 20-hour-old send is a day old, two go (4 less the two of 08:35); the day's cap then holds the next tick.
        $this->settings(['digest_per_day' => '4', 'digest_per_hour' => '3']);
        $two = $this->tick('2026-10-23 13:10', $courier);
        self::assertSame(['sending', 2, 3], [$two['status'], $two['sent'], $two['left']]);
        self::assertSame(['waiting', 3], array_values(array_intersect_key($this->tick('2026-10-23 13:25', $courier), ['status' => 1, 'left' => 1])));
        // The hour's cap: the next day, with the day's cap raised, three go in the hour and the fourth waits.
        $this->settings(['digest_per_day' => '500', 'digest_per_hour' => '1']);
        self::assertSame(1, $this->tick('2026-10-24 08:00', $courier)['sent']);
        self::assertSame('waiting', $this->tick('2026-10-24 08:15', $courier)['status'], 'the hour counts the tick before');
        self::assertSame(1, $this->tick('2026-10-24 09:01', $courier)['sent']);
    }

    public function testABatchStopsOnceTheTickHasSpentItsTime(): void
    {
        $this->article();
        $this->subscribers(6);
        $clock = 0.0;
        $courier = $this->courier();
        $courier->onDigest = static function () use (&$clock): void {
            $clock += 50.0;
        };
        $read = static function () use (&$clock): float {
            return $clock;
        };
        $report = $this->tick('2026-10-23 09:05', $courier, $read);
        self::assertSame(['sending', 3, 3], [$report['status'], $report['sent'], $report['left']], 'at 0, 50 and 100 seconds a message goes; at 150 the batch stops');
        $report = $this->tick('2026-10-23 09:20', $courier, $read);
        self::assertSame(['sent', 3, 0], [$report['status'], $report['sent'], $report['left']], 'each tick has its own time');
    }

    public function testEachOutcomeWritesItsRowAndSaysWhetherTheBatchGoesOn(): void
    {
        $this->article();
        [$ok, $bounce, $after, $unknown, $refusedAtRcpt, $last] = $this->subscribers(6);
        $courier = $this->courier();
        $courier->script = [Delivery::sent(), new Delivery(false, 'rcpt', 550, '5.1.1'), Delivery::sent(), new Delivery(false, 'body', 0, '', true)];
        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['status' => 'sending', 'week' => '2026-w43', 'sent' => 2, 'bounced' => 1, 'retry' => 0, 'failed' => 1, 'left' => 2, 'note' => null], $report);
        $digest = (int) $this->digestRow()['id'];
        $rows = $this->sendRows($digest);
        self::assertSame(['sent', 'bounced', 'sent', 'failed'], [$rows[$ok]['state'], $rows[$bounce]['state'], $rows[$after]['state'], $rows[$unknown]['state']]);
        self::assertSame((new Delivery(false, 'rcpt', 550, '5.1.1'))->summary(), $rows[$bounce]['reply']);
        self::assertSame((new Delivery(false, 'body', 0, '', true))->summary(), $rows[$unknown]['reply']);
        self::assertNull($rows[$bounce]['sent_at']);
        self::assertSame(1, (new Subscribers($this->app))->find($bounce)['bounces'], 'the bounce is counted on the subscriber');

        // A refusal before the message: retried at the next ticks, failed at its third claim, the batch stopping each time.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $courier->script = [new Delivery(false, 'rcpt', 451, '4.7.1')];
            $report = $this->tick('2026-10-23 ' . sprintf('%02d', 9 + $attempt) . ':05', $courier);
            self::assertSame($attempt < 3 ? [1, 0, 'sending', 2] : [0, 1, 'sending', 1], [$report['retry'], $report['failed'], $report['status'], $report['left']], 'attempt ' . $attempt);
            $row = $this->sendRows($digest)[$refusedAtRcpt];
            self::assertSame([$attempt < 3 ? 'retry' : 'failed', $attempt], [$row['state'], (int) $row['attempts']]);
        }
        $report = $this->tick('2026-10-23 13:05', $courier);
        self::assertSame(['sent', 1, 0], [$report['status'], $report['sent'], $report['left']]);
        self::assertSame('sent', $this->sendRows($digest)[$last]['state']);
        $to = array_count_values($courier->digestsTo());
        self::assertSame(3, $to['reader5@example.test'], 'three attempts, each refused before the message');
        foreach (['reader1@example.test', 'reader2@example.test', 'reader3@example.test', 'reader4@example.test', 'reader6@example.test'] as $email) {
            self::assertSame(1, $to[$email], $email . ' once: an unknown outcome is never sent again');
        }
        self::assertSame(['claimed' => 0, 'sent' => 3, 'retry' => 0, 'failed' => 2, 'bounced' => 1], (new Sends($this->app))->counts($digest));
    }

    public function testAnEmailTheMailerWouldNotBuildStopsTheDigest(): void
    {
        $this->article();
        $this->subscribers(3);
        $courier = $this->courier();
        $courier->script = [new Delivery(false, 'refused')];
        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['stopped', 0, 1, 2, Sender::NOTES['unbuildable']], [$report['status'], $report['sent'], $report['failed'], $report['left'], $report['note']]);
        $row = $this->digestRow();
        self::assertSame(['stopped', Sender::NOTES['unbuildable']], [$row['state'], $row['note']]);
        self::assertSame('idle', $this->tick('2026-10-23 09:20', $courier)['status']);
        self::assertCount(1, $courier->digestsTo());
    }

    public function testARowLeftClaimedIsNeverSentAgain(): void
    {
        $this->article();
        [$first] = $this->subscribers(3);
        $courier = $this->courier();
        $courier->script = [new \RuntimeException('the process died mid-message')];
        try {
            $this->tick('2026-10-23 09:05', $courier);
            self::fail('the courier\'s exception reaches the hook');
        } catch (\RuntimeException $e) {
            self::assertSame('the process died mid-message', $e->getMessage());
        }
        $digest = (int) $this->digestRow()['id'];
        self::assertSame('claimed', $this->sendRows($digest)[$first]['state']);
        $report = $this->tick('2026-10-23 09:20', $courier);
        self::assertSame(['sent', 2, 0], [$report['status'], $report['sent'], $report['left']]);
        self::assertSame(['reader1@example.test', 'reader2@example.test', 'reader3@example.test'], $courier->digestsTo(), 'the first only once, its outcome unknown');
        self::assertSame(1, (new Sends($this->app))->counts($digest)['claimed']);
    }

    public function testNobodyWhoLeftOrConfirmedAfterComposingIsSentIt(): void
    {
        $this->article();
        [, $second, $third] = $this->subscribers(4);
        $subscribers = new Subscribers($this->app);
        $this->settings(['digest_per_tick' => '1']);
        $courier = $this->courier();
        self::assertSame(1, $this->tick('2026-10-23 09:05', $courier)['sent']);

        $subscribers->unsubscribe((string) $subscribers->find($second)['selector'], 'one_click', self::ts('2026-10-23 09:10'));
        $selector = (string) $subscribers->find($third)['selector'];
        $subscribers->unsubscribe($selector, 'page', self::ts('2026-10-23 09:11'));
        $subscribers->request('reader3@example.test', 'c', 'p', self::ts('2026-10-23 09:12'));
        self::assertSame('confirmed', $subscribers->confirm($selector, (int) $subscribers->find($third)['generation'], self::ts('2026-10-23 09:13')));
        $late = $subscribers->request('late@example.test', 'c', 'p', self::ts('2026-10-23 09:12'))['subscriber'];
        $subscribers->confirm((string) $late['selector'], 0, self::ts('2026-10-23 09:13'));

        // The fourth unsubscribes while the batch is going: read again just before its message, it is passed over.
        $this->settings(['digest_per_tick' => '25']);
        $report = $this->tick('2026-10-23 09:20', $courier);
        self::assertSame(['sent', 1, 0], [$report['status'], $report['sent'], $report['left']]);
        self::assertSame(['reader1@example.test', 'reader4@example.test'], $courier->digestsTo());

        $this->db->exec('DELETE FROM news_digests');
        $this->article('2026-10-29 12:00');
        $courier = $this->courier();
        $ids = $this->db->all("SELECT id, selector FROM news_digest_subscribers WHERE state = 'confirmed' ORDER BY id");
        $courier->onDigest = static function () use ($subscribers, $ids): void {
            $subscribers->unsubscribe((string) $ids[1]['selector'], 'one_click', self::ts('2026-10-30 09:06'));
        };
        $this->tick('2026-10-30 09:05', $courier);
        self::assertNotContains($subscribers->find((int) $ids[1]['id'])['email'] ?? 'gone', $courier->digestsTo());
        self::assertCount(count($ids) - 1, $courier->digestsTo(), 'the one who left mid-batch is passed over');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_sends WHERE subscriber_id = ?', [(int) $ids[1]['id']]), 'with no row written');
    }

    public function testADigestStopsAfter48HoursOrWhenEveryArticleIsTakenDown(): void
    {
        $this->article();
        $this->subscribers(3);
        $this->settings(['digest_per_day' => '1']);
        $courier = $this->courier();
        self::assertSame('sending', $this->tick('2026-10-23 09:05', $courier)['status']);
        // 48 hours after its time, 09:00 on Friday, is 08:00 on Sunday: the clocks go back between them. It is measured
        // from the send time, never from when a late tick composed it.
        self::assertSame('sending', $this->tick('2026-10-25 08:00', $courier)['status'], 'exactly 48 hours after its time it still goes');
        $report = $this->tick('2026-10-25 08:01', $courier);
        self::assertSame(['stopped', 1, Sender::NOTES['limits']], [$report['status'], $report['left'], $report['note']]);
        self::assertSame(['stopped', Sender::NOTES['limits']], [$this->digestRow()['state'], $this->digestRow()['note']]);
        self::assertCount(2, $courier->digestsTo());

        $article = $this->article('2026-10-29 12:00');
        $this->settings(['digest_per_day' => '500', 'digest_per_tick' => '1']);
        self::assertSame('sending', $this->tick('2026-10-30 09:05', $courier)['status']);
        $this->db->exec("UPDATE news_articles SET status = 'draft' WHERE id = ?", [$article]);
        $report = $this->tick('2026-10-30 09:20', $courier);
        self::assertSame(['stopped', Sender::NOTES['taken_down']], [$report['status'], $report['note']]);
        self::assertSame('stopped', $this->digestRow('2026-w44')['state']);
        self::assertCount(3, $courier->digestsTo());
    }

    public function testABlockedMailboxComposesAndSendsNothing(): void
    {
        $this->article();
        $this->subscribers(2);
        $courier = $this->courier();
        // DigestMail refuses any unsubscribe address that is not a plain address, so each of these blocks before composing.
        foreach ([['driver' => 'mail'], ['unsubscribe_to' => ''], ['unsubscribe_to' => '   '], ['unsubscribe_to' => 'Unsubscribe <unsubscribe@example.test>'], ['unsubscribe_to' => 'unsubscribe']] as $mail) {
            $app = $this->appWith($mail);
            $why = (new Sender($app, $courier))->blocked();
            self::assertSame(($mail['driver'] ?? '') === 'mail' ? 'mail_driver' : 'no_mailto', $why);
            $report = $this->tick('2026-10-23 09:05', $courier, null, $app);
            self::assertSame(['blocked', $why], [$report['status'], $report['note']]);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_digests'));
        self::assertSame([], $courier->calls);
        self::assertNull((new Sender($this->appWith(['driver' => 'smtp']), $courier))->blocked());

        // Fixed within the day: that week's digest still goes.
        $report = $this->tick('2026-10-23 15:00', $courier);
        self::assertSame(['sent', 2], [$report['status'], $report['sent']]);
    }

    public function testNobodyEverGetsTwoCopiesThroughFailuresAndRestarts(): void
    {
        $this->article();
        $this->subscribers(12);
        $this->settings(['digest_per_tick' => '3']);
        $courier = $this->courier();
        $outcomes = [
            Delivery::sent(), new Delivery(false, 'rcpt', 451, '4.7.1'),
            Delivery::sent(), new Delivery(false, 'body', 0, '', true),
            new Delivery(false, 'connect'), Delivery::sent(), Delivery::sent(),
            new Delivery(false, 'rcpt', 550, '5.1.1'), Delivery::sent(),
            new Delivery(false, 'data', 421, '4.3.2'), Delivery::sent(),
        ];
        $courier->script = $outcomes;
        $minute = 5;
        for ($i = 0; $i < 10; $i++) {
            try {
                if ($i === 4) {
                    // A restart mid-batch: the process dies with its message in hand.
                    array_unshift($courier->script, new \RuntimeException('restarted'));
                }
                $this->tick(sprintf('2026-10-23 %02d:%02d', 9 + intdiv($minute, 60), $minute % 60), $courier);
            } catch (\RuntimeException $e) {
                self::assertSame('restarted', $e->getMessage());
            }
            $minute += 15;
        }
        // Each recipient's messages, in order: every one before the last was refused before any of it went.
        $answers = [];
        foreach ($courier->calls as $call) {
            $answers[$call['to']][] = $call['answer'];
        }
        self::assertNotSame([], $answers);
        $mailbox = static fn ($a): bool => $a instanceof Delivery && !$a->ok && in_array($a->stage, ['connect', 'greeting', 'ehlo', 'tls', 'auth', 'mail'], true);
        foreach ($answers as $email => $list) {
            foreach (array_slice($list, 0, -1) as $earlier) {
                self::assertInstanceOf(Delivery::class, $earlier, $email);
                self::assertTrue($earlier->retryable(), $email . ' was sent again only after a refusal before the message');
            }
            $row = $this->db->one('SELECT d.state, d.attempts FROM news_digest_sends d JOIN news_digest_subscribers s ON s.id = d.subscriber_id WHERE s.email = ?', [$email]);
            self::assertNotNull($row, $email);
            // One message a claim, but a refusal before the recipient was named gives its claim back.
            self::assertSame(count(array_filter($list, static fn ($a): bool => !$mailbox($a))), (int) $row['attempts'], $email . ': one counted attempt a message not refused by the mailbox itself');
        }
        self::assertSame(count($answers), (int) $this->db->value('SELECT COUNT(*) FROM news_digest_sends'), 'one row per recipient, whatever happened');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM (SELECT subscriber_id FROM news_digest_sends GROUP BY digest_id, subscriber_id HAVING COUNT(*) > 1) AS twice'));
    }

    public function testTheLogLineMasksNothing(): void
    {
        $this->article();
        $this->subscribers(2);
        $this->tick('2026-10-23 09:05', $this->courier());
        $log = (string) file_get_contents($this->app->storage->path('logs') . '/site-' . gmdate('Y-m-d') . '.log');
        self::assertStringContainsString('news digest', $log);
        self::assertStringContainsString('"sent":2', $log);
        self::assertStringNotContainsString('***', $log, 'no report key looks like a secret');
        self::assertStringNotContainsString('reader1@example.test', $log, 'no address in the log');
        foreach ($this->db->all('SELECT selector FROM news_digest_subscribers') as $row) {
            self::assertStringNotContainsString((string) $row['selector'], $log, 'no selector in the log');
        }
    }

    /**
     * Pausing and taking an article down are read before every message, not once a batch: a pause pressed mid-batch
     * holds the rest of it, and a takedown leaves the article out of every message after it.
     */
    public function testAPauseOrATakedownMidBatchIsReadBeforeTheNextMessage(): void
    {
        $this->article();
        $this->subscribers(10);
        $courier = $this->courier();
        $courier->onDigest = function () use ($courier): void {
            if (count($courier->digestsTo()) === 2) {
                $this->settings(['digest_enabled' => false]);
            }
        };
        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['paused', 2, 8], [$report['status'], $report['sent'], $report['left']]);
        self::assertCount(2, $courier->digestsTo(), 'nothing more once the pause is saved');
        self::assertSame('sending', $this->digestRow()['state']);

        $this->db->exec('DELETE FROM news_digests');
        $this->db->exec('DELETE FROM news_digest_sends');
        $this->settings(['digest_enabled' => true]);
        $first = $this->article('2026-10-29 11:00');
        $second = $this->article('2026-10-29 12:00');
        $courier = $this->courier();
        $courier->onDigest = function () use ($courier, $first, $second): void {
            $n = count($courier->digestsTo());
            if ($n === 1 || $n === 3) {
                $this->db->exec("UPDATE news_articles SET status = 'draft' WHERE id = ?", [$n === 1 ? $second : $first]);
            }
        };
        $report = $this->tick('2026-10-30 09:05', $courier);
        self::assertSame(['stopped', 3, 7, Sender::NOTES['taken_down']], [$report['status'], $report['sent'], $report['left'], $report['note']]);
        $ids = array_map(static fn (array $c): array => array_column($c['digest']['articles'], 'id'), $courier->calls);
        self::assertSame([[$second, $first], [$first], [$first]], $ids, 'each article in the messages before its takedown only');
        self::assertSame('stopped', $this->digestRow('2026-w44')['state']);
    }

    /**
     * A 4xx reply after the message says the server took none of it (RFC 5321 §4.2.5): the recipient is tried again
     * at a later tick, as at RCPT or DATA, never failed as an unknown outcome. A 5xx there is final.
     */
    public function testARefusalAfterTheMessageIsRetriedOnlyWhenItIsA4xx(): void
    {
        $this->article();
        [$first, $second] = $this->subscribers(3);
        $courier = $this->courier();
        $courier->script = [new Delivery(false, 'body', 451, '4.7.1')];
        $report = $this->tick('2026-10-23 09:05', $courier);
        self::assertSame(['sending', 0, 1, 0, 3], [$report['status'], $report['sent'], $report['retry'], $report['failed'], $report['left']]);
        $digest = (int) $this->digestRow()['id'];
        $row = $this->sendRows($digest)[$first];
        self::assertSame(['retry', 1, 'body 451 4.7.1'], [$row['state'], (int) $row['attempts'], $row['reply']]);

        $courier->script = [Delivery::sent(), new Delivery(false, 'body', 554, '5.7.1')];
        $report = $this->tick('2026-10-23 09:20', $courier);
        self::assertSame(['sending', 1, 0, 1, 1], [$report['status'], $report['sent'], $report['retry'], $report['failed'], $report['left']]);
        $rows = $this->sendRows($digest);
        self::assertSame(['sent', 2], [$rows[$first]['state'], (int) $rows[$first]['attempts']], 'sent at its second attempt');
        self::assertSame(['failed', 'body 554 5.7.1'], [$rows[$second]['state'], $rows[$second]['reply']]);

        $report = $this->tick('2026-10-23 09:35', $courier);
        self::assertSame(['sent', 1, 0], [$report['status'], $report['sent'], $report['left']]);
        self::assertSame(['reader1@example.test' => 2, 'reader2@example.test' => 1, 'reader3@example.test' => 1], array_count_values($courier->digestsTo()), 'a final refusal is not sent again');
    }

    /** A digest still paused 48 hours after its time stops with the pause's own note, never the note about the limits. */
    public function testADigestPausedPast48HoursStopsWithThePausesNote(): void
    {
        $this->article();
        $this->subscribers(3);
        $this->settings(['digest_per_tick' => '1']);
        $courier = $this->courier();
        self::assertSame('sending', $this->tick('2026-10-23 09:05', $courier)['status']);
        $this->settings(['digest_enabled' => false]);
        self::assertSame('paused', $this->tick('2026-10-23 09:20', $courier)['status']);
        self::assertSame('paused', $this->tick('2026-10-25 08:00', $courier)['status'], 'exactly 48 hours after its time it still waits');

        $report = $this->tick('2026-10-25 08:01', $courier);
        self::assertSame(['stopped', 2, Sender::NOTES['paused_late']], [$report['status'], $report['left'], $report['note']]);
        self::assertSame(['stopped', Sender::NOTES['paused_late']], [$this->digestRow()['state'], $this->digestRow()['note']]);
        $this->settings(['digest_enabled' => true]);
        self::assertSame('idle', $this->tick('2026-10-26 09:00', $courier)['status']);
        self::assertCount(1, $courier->digestsTo());
    }

    /**
     * A refusal before the recipient is named (the mailbox down, its password changed, its cap reached) is the
     * mailbox's: however many ticks it lasts, it uses up nobody's attempts, and once the server is back everyone is
     * sent the digest once.
     */
    public function testAMailboxOutageUsesUpNobodysAttempts(): void
    {
        $this->article();
        $ids = $this->subscribers(4);
        $courier = $this->courier();
        $minute = 5;
        foreach ([new Delivery(false, 'connect'), new Delivery(false, 'connect'), new Delivery(false, 'connect'), new Delivery(false, 'auth', 535, '5.7.8'), new Delivery(false, 'auth', 535, '5.7.8'), new Delivery(false, 'auth', 535, '5.7.8'), new Delivery(false, 'mail', 451, '4.7.1')] as $refusal) {
            $courier->script = [$refusal];
            $report = $this->tick(sprintf('2026-10-23 %02d:%02d', 9 + intdiv($minute, 60), $minute % 60), $courier);
            self::assertSame(['sending', 0, 1, 0, 4], [$report['status'], $report['sent'], $report['retry'], $report['failed'], $report['left']], $refusal->summary());
            $minute += 15;
        }
        $digest = (int) $this->digestRow()['id'];
        $row = $this->sendRows($digest)[$ids[0]];
        self::assertSame(['retry', 0, 'mail 451 4.7.1'], [$row['state'], (int) $row['attempts'], $row['reply']], 'the first recipient waits, none of its attempts used');
        self::assertSame(0, (new Sends($this->app))->counts($digest)['failed']);

        $report = $this->tick('2026-10-23 11:05', $courier);
        self::assertSame(['sent', 4, 0, 0], [$report['status'], $report['sent'], $report['failed'], $report['left']]);
        $to = array_count_values(array_map(static fn (array $c): string => $c['to'], array_filter($courier->calls, static fn (array $c): bool => $c['answer'] instanceof Delivery && $c['answer']->ok)));
        self::assertSame(['reader1@example.test' => 1, 'reader2@example.test' => 1, 'reader3@example.test' => 1, 'reader4@example.test' => 1], $to, 'each sent the digest once');
        self::assertSame(1, (int) $this->sendRows($digest)[$ids[0]]['attempts']);
    }

    /**
     * A refusal at RCPT that is about the sender (5.1.8, 5.1.7) or says nothing of the address (5.1.0) hits the whole
     * list at once: it is retried, never a bounce, so three weeks of it unsubscribe nobody and keep every address.
     */
    public function testASendersRefusalAtRcptNeverUnsubscribesTheList(): void
    {
        $ids = $this->subscribers(3);
        $courier = $this->courier();
        $courier->onDigest = static function () use ($courier): void {
            $courier->script[] = new Delivery(false, 'rcpt', 550, ['5.1.8', '5.1.7', '5.1.0'][count($courier->calls) % 3]);
        };
        $courier->script = [new Delivery(false, 'rcpt', 550, '5.1.8')];
        foreach (['2026-10-22 12:00' => '2026-10-23', '2026-10-29 12:00' => '2026-10-30', '2026-11-05 12:00' => '2026-11-06'] as $live => $friday) {
            $this->article($live);
            for ($hour = 9; $hour <= 12; $hour++) {
                $report = $this->tick($friday . ' ' . sprintf('%02d', $hour) . ':05', $courier);
                self::assertSame(0, $report['bounced'], $friday);
            }
        }
        $s = new Subscribers($this->app);
        foreach ($ids as $id) {
            $row = $s->find($id);
            self::assertNotNull($row);
            self::assertSame(['confirmed', 0], [$row['state'], $row['bounces']]);
            self::assertNotNull($row['email'], 'the address is kept');
        }
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM news_digest_sends WHERE state = 'bounced'"));
    }
}
