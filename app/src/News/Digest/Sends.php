<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Db\Database;
use Psc\News\ArticleStore;

/**
 * Each digest's sends (docs/NEWS.md §14.2, §14.4): the only writer of news_digest_sends, one row per digest and
 * recipient. A row is claimed, and committed, before its message's DATA, and the pair's unique key means only one
 * claim ever holds it: a row left claimed (the process died, or its outcome is unknown) is never sent again, since
 * SMTP has no way to send a message only once. Only a refusal that leaves the server holding none of the message
 * (retry: before any of it went, or a 4xx reply to it) is claimed again, while fewer than MAX_ATTEMPTS of its claims
 * have counted: a refusal before the recipient was named (the mailbox's fault, never the address's) gives its claim
 * back (release()).
 *
 * The caps count the rows claimed in any state but retry: what the mailbox was asked to send.
 */
final class Sends
{
    public const MAX_ATTEMPTS = 3;

    public const STATES = ['claimed', 'sent', 'retry', 'failed', 'bounced'];

    /**
     * A subscriber (as s) a digest may still go to, taking its composed_at: confirmed, with an address, and confirmed
     * at or before the digest was composed (whoever confirms later starts with the next one).
     */
    private const STILL_DUE = "s.state = 'confirmed' AND s.email IS NOT NULL AND s.confirmed_at IS NOT NULL AND s.confirmed_at <= ?";

    public function __construct(private readonly App $app)
    {
    }

    /**
     * The subscriber rows still to send a digest to, by id: confirmed, with an address, confirmed at or before the
     * digest was composed (whoever confirms later starts with the next one), and with no send row for it but a retry
     * under MAX_ATTEMPTS.
     *
     * @param array<string, mixed> $digest a news_digests row
     * @return list<array<string, mixed>>
     */
    public function due(array $digest, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        return $this->db()->all('SELECT s.*' . self::dueFrom() . ' ORDER BY s.id LIMIT ' . $limit, [(int) $digest['composed_at'], (int) $digest['id']]);
    }

    /** How many recipients a digest is still due to (due() without its limit). */
    public function countDue(array $digest): int
    {
        return (int) $this->db()->value('SELECT COUNT(*)' . self::dueFrom(), [(int) $digest['composed_at'], (int) $digest['id']]);
    }

    /**
     * How many of a digest's retries will be claimed again: the recipients it is still due to (countDue()) that have a
     * row, which due() allows only for a retry under MAX_ATTEMPTS. A retry whose subscriber has unsubscribed since, or
     * confirmed again after the digest was composed, is never claimed again, so it is not counted.
     */
    public function countWaiting(array $digest): int
    {
        return (int) $this->db()->value(
            'SELECT COUNT(*)' . self::dueFrom() . ' AND EXISTS (SELECT 1 FROM news_digest_sends w WHERE w.digest_id = ? AND w.subscriber_id = s.id)',
            [(int) $digest['composed_at'], (int) $digest['id'], (int) $digest['id']]
        );
    }

    /**
     * Claims a recipient's send: a new claimed row, or a retry row claimed again. The row's id, or null when another
     * claim has it (the unique pair, or the retry's condition). Never inside a transaction, so the claim is committed
     * before the message goes.
     */
    public function claim(int $digestId, int $subscriberId, int $now): ?int
    {
        $db = $this->db();
        if ($db->pdo()->inTransaction()) {
            throw new \LogicException('A digest send is claimed outside any transaction, so the claim is committed before the message goes');
        }
        $row = $db->one('SELECT id, state, attempts FROM news_digest_sends WHERE digest_id = ? AND subscriber_id = ?', [$digestId, $subscriberId]);
        if ($row === null) {
            try {
                return $db->insert('news_digest_sends', ['digest_id' => $digestId, 'subscriber_id' => $subscriberId, 'state' => 'claimed', 'attempts' => 1, 'claimed_at' => $now]);
            } catch (\PDOException $e) {
                if (ArticleStore::isDuplicate($e)) {
                    return null;
                }
                throw $e;
            }
        }
        $id = (int) $row['id'];
        return $db->exec(
            "UPDATE news_digest_sends SET state = 'claimed', attempts = attempts + 1, claimed_at = ?, reply = NULL WHERE id = ? AND state = 'retry' AND attempts < " . self::MAX_ATTEMPTS,
            [$now, $id]
        ) === 1 ? $id : null;
    }

    /** The server answered 250 to the message. */
    public function sent(int $id, int $now): bool
    {
        return $this->db()->exec("UPDATE news_digest_sends SET state = 'sent', sent_at = ? WHERE id = ? AND state = 'claimed'", [$now, $id]) === 1;
    }

    /** A hard bounce (Delivery::hardBounce(): the address refused at RCPT as one that does not exist): never sent again. */
    public function bounced(int $id, string $reply): bool
    {
        return $this->settle($id, 'bounced', $reply);
    }

    /**
     * The message may or may not have gone, the server refused it for good (a 5xx reply to it), or it was never
     * buildable: never sent again.
     */
    public function failed(int $id, string $reply): bool
    {
        return $this->settle($id, 'failed', $reply);
    }

    /**
     * Refused before the recipient was named (the connection, the greeting, EHLO, TLS, AUTH or MAIL FROM): nothing
     * was said about the address, so the claim is given back, claimed again at a later tick without using up one of
     * its attempts. True when the row was claimed.
     */
    public function release(int $id, string $reply): bool
    {
        return $this->db()->exec(
            "UPDATE news_digest_sends SET state = 'retry', attempts = attempts - 1, reply = ? WHERE id = ? AND state = 'claimed' AND attempts > 0",
            [self::reply($reply), $id]
        ) === 1;
    }

    /**
     * Refused at RCPT or DATA, or with a 4xx reply to the message, so the server holds none of it: claimed again at a
     * later tick, or failed at its MAX_ATTEMPTS-th claim. The state written, or null when the row was not claimed.
     */
    public function retry(int $id, string $reply): ?string
    {
        $changed = $this->db()->exec(
            "UPDATE news_digest_sends SET state = CASE WHEN attempts >= " . self::MAX_ATTEMPTS . " THEN 'failed' ELSE 'retry' END, reply = ? WHERE id = ? AND state = 'claimed'",
            [self::reply($reply), $id]
        );
        if ($changed !== 1) {
            return null;
        }
        $state = $this->db()->value('SELECT state FROM news_digest_sends WHERE id = ?', [$id]);
        return is_string($state) ? $state : null;
    }

    /** The rows claimed at or after $since in any state but retry: what the mailbox was asked to send. */
    public function countSince(int $since): int
    {
        return (int) $this->db()->value("SELECT COUNT(*) FROM news_digest_sends WHERE claimed_at >= ? AND state <> 'retry'", [$since]);
    }

    /** @return array{claimed: int, sent: int, retry: int, failed: int, bounced: int} a digest's sends by state */
    public function counts(int $digestId): array
    {
        $out = array_fill_keys(self::STATES, 0);
        foreach ($this->db()->all('SELECT state, COUNT(*) AS n FROM news_digest_sends WHERE digest_id = ? GROUP BY state', [$digestId]) as $row) {
            if (isset($out[(string) $row['state']])) {
                $out[(string) $row['state']] = (int) $row['n'];
            }
        }
        return $out;
    }

    /**
     * One page of a digest's sends, by id, each with its subscriber's address (null once unsubscribed) and whether the
     * digest is still due to that subscriber (due(): a retry of one no longer due is never claimed again).
     *
     * @param array<string, mixed> $digest a news_digests row
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function page(array $digest, int $page, int $perPage): array
    {
        $db = $this->db();
        $digestId = (int) $digest['id'];
        $perPage = max(1, $perPage);
        $total = (int) $db->value('SELECT COUNT(*) FROM news_digest_sends WHERE digest_id = ?', [$digestId]);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $items = [];
        foreach ($db->all(
            'SELECT d.id, d.subscriber_id, d.state, d.attempts, d.claimed_at, d.sent_at, d.reply, s.email, CASE WHEN ' . self::STILL_DUE . ' THEN 1 ELSE 0 END AS due'
            . ' FROM news_digest_sends d LEFT JOIN news_digest_subscribers s ON s.id = d.subscriber_id WHERE d.digest_id = ? ORDER BY d.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            [(int) $digest['composed_at'], $digestId]
        ) as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'subscriber_id' => (int) $r['subscriber_id'],
                'state' => (string) $r['state'],
                'attempts' => (int) $r['attempts'],
                'claimed_at' => (int) $r['claimed_at'],
                'sent_at' => $r['sent_at'] !== null ? (int) $r['sent_at'] : null,
                'reply' => $r['reply'] !== null ? (string) $r['reply'] : null,
                'email' => $r['email'] !== null ? (string) $r['email'] : null,
                'due' => (int) $r['due'] === 1,
            ];
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function db(): Database
    {
        return $this->app->db();
    }

    /** due()'s FROM and WHERE, taking the digest's composed_at, then its id. */
    private static function dueFrom(): string
    {
        return ' FROM news_digest_subscribers s WHERE ' . self::STILL_DUE
            . ' AND NOT EXISTS (SELECT 1 FROM news_digest_sends d WHERE d.digest_id = ? AND d.subscriber_id = s.id'
            . " AND NOT (d.state = 'retry' AND d.attempts < " . self::MAX_ATTEMPTS . '))';
    }

    private function settle(int $id, string $state, string $reply): bool
    {
        return $this->db()->exec('UPDATE news_digest_sends SET state = ?, reply = ? WHERE id = ? AND state = ?', [$state, self::reply($reply), $id, 'claimed']) === 1;
    }

    /** Delivery::summary() is at most 40 characters; the column holds no more. */
    private static function reply(string $reply): string
    {
        return substr($reply, 0, 40);
    }
}
