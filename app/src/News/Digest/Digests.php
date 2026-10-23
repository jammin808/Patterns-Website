<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Db\Database;
use Psc\News\ArticleStore;

/**
 * The weekly digests (docs/NEWS.md §14.2, §14.3): the only writer of news_digests, one row per London week that
 * reached its send time, whatever came of it. The week's unique key means two ticks never both write a week, and
 * each change of state is one conditional UPDATE from sending.
 *
 * A row's articles are a JSON list of article ids, the newest first: [] for an empty week, NULL for a skipped one.
 */
final class Digests
{
    public const STATES = ['sending', 'sent', 'empty', 'skipped', 'stopped'];

    private const INT_COLUMNS = ['id', 'send_at', 'window_start', 'window_end', 'left_out', 'recipients', 'composed_at', 'finished_at'];

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $id > 0 ? self::row($this->db()->one('SELECT * FROM news_digests WHERE id = ?', [$id])) : null;
    }

    /** @return array<string, mixed>|null */
    public function forWeek(string $week): ?array
    {
        return self::row($this->db()->one('SELECT * FROM news_digests WHERE week = ?', [$week]));
    }

    /**
     * The weeks with a row sent at or composed since $since: those Week::due() passes over.
     *
     * @return list<string>
     */
    public function weeksSince(int $since): array
    {
        return array_map(static fn (array $r): string => (string) $r['week'], $this->db()->all('SELECT week FROM news_digests WHERE send_at >= ? OR composed_at >= ? ORDER BY send_at', [$since, $since]));
    }

    /**
     * The newest digest that was composed (sending, sent, stopped or empty): the next one's window starts where its
     * ends when it was the week before's. A skipped week is never it, so after one the next window reaches back 7
     * days and no more: with the weekday unchanged, a skipped week's articles go into no digest.
     *
     * @return array<string, mixed>|null
     */
    public function lastComposed(): ?array
    {
        return self::row($this->db()->one("SELECT * FROM news_digests WHERE state IN ('sending', 'sent', 'stopped', 'empty') ORDER BY send_at DESC, id DESC LIMIT 1"));
    }

    /** @return array<string, mixed>|null the oldest digest still sending */
    public function sending(): ?array
    {
        return self::row($this->db()->one("SELECT * FROM news_digests WHERE state = 'sending' ORDER BY composed_at, id LIMIT 1"));
    }

    /**
     * The newest digests first, each with its sends' counts (Sends::counts()) under counts and, under unsent, the
     * recipients a stopped one never reached (unsent()).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit): array
    {
        $sends = new Sends($this->app);
        $out = [];
        foreach ($this->db()->all('SELECT * FROM news_digests ORDER BY send_at DESC, id DESC LIMIT ' . max(1, $limit)) as $r) {
            $row = (array) self::row($r);
            $row['counts'] = $sends->counts((int) $row['id']);
            $row['unsent'] = self::unsent($row, $row['counts']);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The recipients a stopped digest never reached, from its own record: its recipients when composed less every
     * send with an outcome (sent, failed, bounced or unknown). A send left to retry, a recipient never tried and one
     * passed over as they left all count, and the number holds as the list changes later. 0 for any other state.
     *
     * @param array<string, mixed> $digest a news_digests row
     * @param array{claimed: int, sent: int, retry: int, failed: int, bounced: int} $counts its Sends::counts()
     */
    public static function unsent(array $digest, array $counts): int
    {
        if (($digest['state'] ?? null) !== 'stopped') {
            return 0;
        }
        return max(0, (int) $digest['recipients'] - $counts['sent'] - $counts['failed'] - $counts['bounced'] - $counts['claimed']);
    }

    /**
     * Writes a week's row in one INSERT: the row, or null when the week has one already (another tick wrote it).
     *
     * @param array<string, mixed> $row week, send_at, window_start, window_end, articles (a list of ids, or null),
     *                                  left_out, state, note, recipients, composed_at, finished_at
     * @return array<string, mixed>|null
     */
    public function create(array $row): ?array
    {
        if (!in_array($row['state'] ?? null, self::STATES, true)) {
            throw new \InvalidArgumentException('Unknown digest state');
        }
        $articles = $row['articles'] ?? null;
        $row['articles'] = is_array($articles) ? json_encode(array_values(array_map('intval', $articles)), JSON_THROW_ON_ERROR) : null;
        try {
            $id = $this->db()->insert('news_digests', $row);
        } catch (\PDOException $e) {
            if (ArticleStore::isDuplicate($e)) {
                return null;
            }
            throw $e;
        }
        return $this->find($id);
    }

    /** Every recipient handled: sending to sent. */
    public function finish(int $id, int $now): bool
    {
        return $this->db()->exec("UPDATE news_digests SET state = 'sent', finished_at = ? WHERE id = ? AND state = 'sending'", [$now, $id]) === 1;
    }

    /** Sending to stopped, the rest unsent, with the reason in an admin's words (Sender::NOTES). */
    public function stop(int $id, string $note, int $now): bool
    {
        return $this->db()->exec("UPDATE news_digests SET state = 'stopped', note = ?, finished_at = ? WHERE id = ? AND state = 'sending'", [mb_substr($note, 0, 300), $now, $id]) === 1;
    }

    /**
     * Housekeeping: digests composed before $before go with their sends (the sends first, counted, then the digests).
     *
     * @return array{sends: int, digests: int}
     */
    public function prune(int $before): array
    {
        $db = $this->db();
        $sends = $db->exec('DELETE FROM news_digest_sends WHERE digest_id IN (SELECT id FROM news_digests WHERE composed_at < ?)', [$before]);
        $digests = $db->exec('DELETE FROM news_digests WHERE composed_at < ?', [$before]);
        return ['sends' => $sends, 'digests' => $digests];
    }

    private function db(): Database
    {
        return $this->app->db();
    }

    /**
     * A row with its whole numbers as ints and articles decoded: a list of ids, or null for a skipped week.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private static function row(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }
        $ids = is_string($row['articles'] ?? null) ? json_decode($row['articles'], true) : null;
        $row['articles'] = is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : null;
        return $row;
    }
}
