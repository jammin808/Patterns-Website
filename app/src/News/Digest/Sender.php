<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Mail\Delivery;
use Psc\News\Settings;

/**
 * The digest's step of the news tick (docs/NEWS.md §14.4), run by tools/news.php after the desk's own work, under
 * the lock that command holds, so it never runs beside another tick. In order: nothing at all while sending is
 * blocked (PHP mail(), or no unsubscribe address); the week due now composed (or recorded as skipped while paused);
 * then one batch of the oldest digest still sending, within the per-tick, per-hour and rolling 24-hour caps and
 * TICK_SECONDS of the tick's time, with the pause and the articles still live read again before every message.
 *
 * Never twice: each recipient's send row is claimed and committed before the message goes (Sends::claim()), the
 * subscriber is read again just before, and a row whose outcome is unknown (no reply after the message, or the
 * process died) is never sent again. A refusal before any of the message went, or a 4xx reply to it (the server holds
 * none of it), is tried again at a later tick, and the batch stops at the first one, since Hostinger documents nothing
 * past its caps and a refusal reads as "stop for now". Only a refusal once the recipient was named (RCPT, DATA or the
 * message) counts against that recipient's attempts: one before it (the mailbox down, its password changed, its cap
 * reached) is the mailbox's, so an outage never uses up anyone's. The report's keys hold no word the Logger masks,
 * and no line holds an address, a token or a selector.
 */
final class Sender
{
    /** A batch sends nothing more once the tick has spent this long on it. */
    public const TICK_SECONDS = 120;

    /** A digest still sending this long after its send time stops, the rest unsent. */
    public const SEND_WITHIN = 172800;

    /** The stages before the recipient is named: a refusal there is the mailbox's, never the address's. */
    private const MAILBOX_STAGES = ['connect', 'greeting', 'ehlo', 'tls', 'auth', 'mail'];

    /** A digest row's note, in an admin's words. */
    public const NOTES = [
        'paused' => 'Skipped: the digest was paused at its time.',
        'paused_late' => 'Stopped: it was still paused 48 hours after its time, so the rest were not sent.',
        'limits' => 'Stopped: it could not reach everyone within 48 hours of its time, so the rest were not sent. If the sends say the mail server refused them, check the mailbox; otherwise raise the limits on the Settings tab, or ask for the digest\'s own mailbox (docs/DEPLOY.md).',
        'taken_down' => 'Stopped: every article in it was taken down.',
        'unbuildable' => 'Stopped: the email could not be built. The site log says why.',
    ];

    private readonly Courier $courier;
    private readonly Digests $digests;
    private readonly Sends $sends;
    private readonly Subscribers $subscribers;
    private readonly Composer $composer;

    /** @var \Closure(): float seconds on a steady clock */
    private readonly \Closure $clock;

    /** @param (\Closure(): float)|null $clock the batch's clock in seconds (tests pass one that runs fast) */
    public function __construct(private readonly App $app, ?Courier $courier = null, ?\Closure $clock = null)
    {
        $this->courier = $courier ?? DigestMail::courier($app);
        $this->digests = new Digests($app);
        $this->sends = new Sends($app);
        $this->subscribers = new Subscribers($app);
        $this->composer = new Composer($app);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * Why nothing may be sent, or null: mail_driver (mail.driver is mail, which Hostinger caps at 100 a day) or
     * no_mailto (mail.unsubscribe_to is empty or not an address: every digest must offer both ways out, and
     * DigestMail refuses to send one by the same rule).
     */
    public function blocked(): ?string
    {
        return match (true) {
            $this->app->config->string('mail.driver', 'log') === 'mail' => 'mail_driver',
            !DigestMail::hasUnsubscribeAddress($this->app) => 'no_mailto',
            default => null,
        };
    }

    /**
     * What this tick may send: the least of digest_per_tick, digest_per_hour less the last hour's sends and
     * digest_per_day less the last 24 hours'; never below 0.
     */
    public function allowance(int $now): int
    {
        $settings = new Settings($this->app);
        return max(0, min(
            (int) $settings->get('digest_per_tick'),
            (int) $settings->get('digest_per_hour') - $this->sends->countSince($now - 3600),
            (int) $settings->get('digest_per_day') - $this->sends->countSince($now - 86400),
        ));
    }

    /**
     * One tick's digest work. status: idle (nothing due or sending), blocked, skipped (a paused week recorded),
     * empty (a week with no new article), paused (a digest partly sent waits), waiting (the caps allow none now),
     * sending (recipients left for the next tick), sent (everyone handled) or stopped.
     *
     * @return array{status: string, week: ?string, sent: int, bounced: int, retry: int, failed: int, left: int, note: ?string}
     */
    public function tick(\DateTimeImmutable $now): array
    {
        $started = ($this->clock)();
        $at = $now->getTimestamp();
        $report = ['status' => 'idle', 'week' => null, 'sent' => 0, 'bounced' => 0, 'retry' => 0, 'failed' => 0, 'left' => 0, 'note' => null];
        $this->app->dbAlive();
        $blocked = $this->blocked();
        if ($blocked !== null) {
            $report = array_replace($report, ['status' => 'blocked', 'note' => $blocked]);
            $this->app->log->info('news digest', $report);
            return $report;
        }
        $settings = new Settings($this->app);
        $paused = $settings->get('digest_enabled') !== true;

        $due = Week::due($now, $settings, $this->digests->weeksSince($at - 8 * 86400));
        if ($due !== null) {
            if ($paused) {
                if ($this->composer->skip($due['week'], $due['send_at'], self::NOTES['paused'], $at) !== null) {
                    $report = array_replace($report, ['status' => 'skipped', 'week' => $due['week'], 'note' => self::NOTES['paused']]);
                }
            } elseif ($this->composer->compose($due['week'], $due['send_at'], $at)['state'] === 'empty') {
                $report = array_replace($report, ['status' => 'empty', 'week' => $due['week']]);
            }
        }

        $digest = $this->digests->sending();
        if ($digest !== null) {
            $report = $this->send($digest, $paused, $at, $started, $report);
        }
        $this->app->log->info('news digest', $report);
        return $report;
    }

    /**
     * One batch of a digest still sending.
     *
     * @param array<string, mixed> $digest
     * @param array<string, mixed> $report
     * @return array{status: string, week: ?string, sent: int, bounced: int, retry: int, failed: int, left: int, note: ?string}
     */
    private function send(array $digest, bool $paused, int $at, float $started, array $report): array
    {
        $id = (int) $digest['id'];
        $report['week'] = (string) $digest['week'];
        if ($at - (int) $digest['send_at'] > self::SEND_WITHIN) {
            return $this->stop($digest, $paused ? 'paused_late' : 'limits', $at, $report);
        }
        if ($paused) {
            return array_replace($report, ['status' => 'paused', 'left' => $this->sends->countDue($digest)]);
        }
        $shape = $this->composer->shape($digest, $at);
        if ($shape['articles'] === []) {
            return $this->stop($digest, 'taken_down', $at, $report);
        }
        $allowance = $this->allowance($at);
        if ($allowance === 0) {
            return array_replace($report, ['status' => 'waiting', 'left' => $this->sends->countDue($digest)]);
        }
        foreach ($this->sends->due($digest, $allowance) as $candidate) {
            if (($this->clock)() - $started >= self::TICK_SECONDS) {
                break;
            }
            // Read again before every message: a pause holds the rest of the batch, and an article taken down is left
            // out of every message after that.
            if ((new Settings($this->app))->get('digest_enabled') !== true) {
                return array_replace($report, ['status' => 'paused', 'left' => $this->sends->countDue($digest)]);
            }
            $live = $this->composer->live(array_column($shape['articles'], 'id'), $at);
            $shape['articles'] = array_values(array_filter($shape['articles'], static fn (array $a): bool => in_array($a['id'], $live, true)));
            if ($shape['articles'] === []) {
                return $this->stop($digest, 'taken_down', $at, $report);
            }
            // Read again just before sending: whoever unsubscribed, or confirmed again after this digest was composed,
            // since the list was read is passed over, with no row written.
            $subscriber = $this->subscribers->find((int) $candidate['id']);
            if ($subscriber === null || $subscriber['state'] !== 'confirmed' || $subscriber['email'] === null
                || $subscriber['confirmed_at'] === null || $subscriber['confirmed_at'] > (int) $digest['composed_at']) {
                continue;
            }
            $sendId = $this->sends->claim($id, (int) $subscriber['id'], $at);
            if ($sendId === null) {
                continue;
            }
            $delivery = $this->courier->digest($shape, $subscriber);
            if (!$this->settle($delivery, $sendId, (int) $subscriber['id'], $at, $report)) {
                if ($delivery->stage === 'refused') {
                    return $this->stop($digest, 'unbuildable', $at, $report);
                }
                break;
            }
        }
        $left = $this->sends->countDue($digest);
        if ($left === 0 && $this->digests->finish($id, $at)) {
            return array_replace($report, ['status' => 'sent', 'left' => 0]);
        }
        return array_replace($report, ['status' => 'sending', 'left' => $left]);
    }

    /**
     * Records one message's outcome on its send row and its subscriber (§14.4's table) and counts it in the report.
     * True when the batch goes on: the message was taken, or the address does not exist. Anything else stops the
     * batch: an unknown outcome is failed and never sent again; a refusal before the recipient was named gives its
     * claim back; one at RCPT or DATA, or a 4xx reply to the message, is retried at a later tick (failed at its last
     * attempt); a 5xx reply to the message, which is final, and a message the Mailer would not build are failed.
     *
     * @param array<string, mixed> $report
     */
    private function settle(Delivery $delivery, int $sendId, int $subscriberId, int $at, array &$report): bool
    {
        if ($delivery->ok) {
            $this->sends->sent($sendId, $at);
            $this->subscribers->delivered($subscriberId);
            $report['sent']++;
            return true;
        }
        $reply = $delivery->summary();
        if ($delivery->stage !== 'refused' && $delivery->hardBounce()) {
            $this->sends->bounced($sendId, $reply);
            $this->subscribers->bounced($subscriberId, $at);
            $report['bounced']++;
            return true;
        }
        if ($delivery->stage !== 'refused' && !$delivery->uncertain() && $delivery->retryable()) {
            if (in_array($delivery->stage, self::MAILBOX_STAGES, true)) {
                $this->sends->release($sendId, $reply);
                $report['retry']++;
                return false;
            }
            $report[$this->sends->retry($sendId, $reply) === 'retry' ? 'retry' : 'failed']++;
            return false;
        }
        // Unknown (no reply to the message), refused for good after it (a 5xx), refused by the Mailer, or anything else.
        $this->sends->failed($sendId, $reply);
        $report['failed']++;
        return false;
    }

    /**
     * Stops a digest with its note.
     *
     * @param array<string, mixed> $digest
     * @param array<string, mixed> $report
     * @return array{status: string, week: ?string, sent: int, bounced: int, retry: int, failed: int, left: int, note: ?string}
     */
    private function stop(array $digest, string $why, int $at, array $report): array
    {
        $left = $this->sends->countDue($digest);
        $this->digests->stop((int) $digest['id'], self::NOTES[$why], $at);
        return array_replace($report, ['status' => 'stopped', 'left' => $left, 'note' => self::NOTES[$why]]);
    }
}
