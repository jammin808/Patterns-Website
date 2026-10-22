<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;

/**
 * The desk's sessions, one news_runs row each (docs/NEWS.md §3, §4 Runs). A row moves queued → running →
 * done | skipped | failed, and every move is one conditional UPDATE from the state it expects, so two ticks can
 * never both start, finish or skip the same run. A slot is claimed by inserting its row: the unique slot key lets
 * exactly one tick have it, and a claimed slot is never claimed again, failed or not (a retry would bill the
 * searches twice).
 *
 * Usage and cost are written after every model call (record()), so a run that dies still shows what it spent.
 */
final class Runs
{
    public const STATUSES = ['queued', 'running', 'done', 'skipped', 'failed'];
    public const KINDS = ['scheduled', 'manual'];

    /** A running run is reaped this long after its deadline: its process has gone. */
    public const REAP_AFTER = 900;

    /** What a session is assumed to cost until there are enough sessions to measure (P90_FROM). */
    public const P90_DEFAULT = 2.50;
    public const P90_FROM = 10;

    /** The columns record() may write. */
    private const RECORDED = [
        'candidates', 'articles', 'held', 'usage_in', 'usage_out', 'cache_write', 'cache_read', 'searches', 'fetches',
        'cost_micros', 'models', 'summary', 'brief', 'refs', 'considered', 'log',
    ];

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Claims a slot: its run row, queued. Null when the slot was claimed already (by this tick earlier, or by
     * another one).
     */
    public function claim(string $slot, string $kind, ?int $userId): ?int
    {
        if (!in_array($kind, self::KINDS, true) || $slot === '' || strlen($slot) > 40) {
            throw new \InvalidArgumentException('Bad run slot or kind');
        }
        try {
            return $this->db()->insert('news_runs', ['slot' => $slot, 'kind' => $kind, 'status' => 'queued', 'requested_by' => $userId, 'created_at' => time()]);
        } catch (\PDOException $e) {
            if (ArticleStore::isDuplicate($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Queues a session to run at the next tick, unless one is queued or running already: one INSERT that adds the
     * row only while no such run exists, so two presses (or two administrators) queue one session.
     */
    public function queueManual(?int $userId): ?int
    {
        $now = time();
        $slot = 'manual-' . $now . '-' . bin2hex(random_bytes(3));
        $db = $this->db();
        $added = $db->exec(
            "INSERT INTO news_runs (slot, kind, status, requested_by, created_at) SELECT ?, 'manual', 'queued', ?, ? FROM (SELECT 1 AS one) AS once"
            . " WHERE NOT EXISTS (SELECT 1 FROM news_runs WHERE status IN ('queued', 'running'))",
            [$slot, $userId, $now]
        );
        if ($added !== 1) {
            return null;
        }
        $id = $db->value('SELECT id FROM news_runs WHERE slot = ?', [$slot]);
        return $id !== null ? (int) $id : null;
    }

    /** Starts a queued run with its deadline; false unless this call moved it from queued (exactly one row). */
    public function start(int $id, int $deadline): bool
    {
        return $this->db()->exec(
            "UPDATE news_runs SET status = 'running', started_at = ?, deadline_at = ? WHERE id = ? AND status = 'queued'",
            [time(), $deadline, $id]
        ) === 1;
    }

    /**
     * Writes a running run's progress: usage, cost, the models, the brief and references, the candidates, the log.
     * Columns that record() does not write are ignored. Lists are stored as JSON.
     *
     * @param array<string, mixed> $fields
     */
    public function record(int $id, array $fields): void
    {
        $set = self::columns($fields);
        if ($set !== []) {
            $this->db()->update('news_runs', $set, ['id' => $id]);
        }
    }

    /**
     * Ends a running run as done, skipped or failed with its report (the columns record() writes); false when it was
     * not running (reaped as stale, or finished by another process).
     *
     * @param array<string, mixed> $report
     */
    public function finish(int $id, string $status, array $report): bool
    {
        if (!in_array($status, ['done', 'skipped', 'failed'], true)) {
            throw new \InvalidArgumentException('Bad run status: ' . $status);
        }
        return $this->db()->update('news_runs', ['status' => $status, 'finished_at' => time()] + self::columns($report), ['id' => $id, 'status' => 'running']) === 1;
    }

    /** Ends a queued run that will not start (the desk is paused, has no key, or would pass its cap), saying why. */
    public function skip(int $id, string $summary): bool
    {
        return $this->db()->update('news_runs', ['status' => 'skipped', 'finished_at' => time(), 'summary' => self::cut($summary, 500)], ['id' => $id, 'status' => 'queued']) === 1;
    }

    /** Marks runs still running a quarter of an hour past their deadline as failed; returns how many. */
    public function reapStale(int $now): int
    {
        return $this->db()->exec(
            "UPDATE news_runs SET status = 'failed', finished_at = ?, summary = ? WHERE status = 'running' AND deadline_at < ?",
            [$now, 'Stopped: the session passed its deadline without finishing, so its process had ended.', $now - self::REAP_AFTER]
        );
    }

    /** The estimated spend of this London month's sessions, in USD. */
    public function spentThisMonth(?int $now = null): float
    {
        $month = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(Schedule::zone())->modify('first day of this month')->setTime(0, 0);
        return (int) $this->db()->value('SELECT COALESCE(SUM(cost_micros), 0) FROM news_runs WHERE created_at >= ?', [$month->getTimestamp()]) / 1000000;
    }

    /**
     * The 90th-percentile cost of the last 30 sessions that ran, in USD; P90_DEFAULT until there are P90_FROM of
     * them, so a new desk does not start by assuming sessions are free.
     */
    public function p90Cost(): float
    {
        $costs = array_map('intval', array_column($this->db()->all(
            'SELECT cost_micros FROM news_runs WHERE started_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 30'
        ), 'cost_micros'));
        if (count($costs) < self::P90_FROM) {
            return self::P90_DEFAULT;
        }
        sort($costs);
        return $costs[(int) ceil(0.9 * count($costs)) - 1] / 1000000;
    }

    /** @return list<array<string, mixed>> the newest runs, without their brief, references and log */
    public function recent(int $limit): array
    {
        return $this->db()->all(
            'SELECT id, slot, kind, status, requested_by, started_at, deadline_at, finished_at, candidates, articles, held,'
            . ' usage_in, usage_out, cache_write, cache_read, searches, fetches, cost_micros, models, summary, created_at'
            . ' FROM news_runs ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
        );
    }

    /**
     * How many of the latest sessions that ran wrote nothing, counting back to the last one that did.
     */
    public function consecutiveEmpty(): int
    {
        $n = 0;
        foreach ($this->db()->all('SELECT articles FROM news_runs WHERE started_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 50') as $row) {
            if ((int) $row['articles'] > 0) {
                break;
            }
            $n++;
        }
        return $n;
    }

    /**
     * One run with its stored lists decoded: refs (S1 => {url, title, via, page_age, cited}), considered and log.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $id > 0 ? $this->db()->one('SELECT * FROM news_runs WHERE id = ?', [$id]) : null;
        if ($row === null) {
            return null;
        }
        foreach (['refs', 'considered', 'log'] as $k) {
            $decoded = is_string($row[$k]) && $row[$k] !== '' ? json_decode($row[$k], true) : null;
            $row[$k] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /** @return array<string, mixed>|null the oldest run waiting to start */
    public function queued(): ?array
    {
        return $this->db()->one("SELECT * FROM news_runs WHERE status = 'queued' ORDER BY created_at, id LIMIT 1");
    }

    /** @return array<string, mixed>|null the run that is running now */
    public function running(): ?array
    {
        return $this->db()->one("SELECT * FROM news_runs WHERE status = 'running' ORDER BY started_at DESC, id DESC LIMIT 1");
    }

    /** @return array<string, mixed>|null the run that started most recently, of any kind */
    public function lastStarted(): ?array
    {
        return $this->db()->one('SELECT id, slot, kind, status, started_at FROM news_runs WHERE started_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 1');
    }

    /**
     * The slots claimed since $since, so Schedule::due() passes over them.
     *
     * @return list<string>
     */
    public function slotsSince(int $since): array
    {
        return array_map('strval', array_column($this->db()->all("SELECT slot FROM news_runs WHERE kind = 'scheduled' AND created_at >= ?", [$since]), 'slot'));
    }

    private function db(): Database
    {
        return $this->app->db();
    }

    /**
     * The writable columns of $fields, lists as JSON and texts cut to what their column holds.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function columns(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, self::RECORDED, true)) {
                continue;
            }
            $out[$k] = match ($k) {
                'models' => self::cut((string) $v, 160),
                'summary' => self::cut((string) $v, 500),
                'brief' => $v === null ? null : self::bytes((string) $v, 60000),
                'refs', 'considered', 'log' => is_array($v) ? self::json($v) : ($v === null ? null : (string) $v),
                default => max(0, (int) $v),
            };
        }
        return $out;
    }

    /** JSON that fits a TEXT column: a list that would not is shortened from its end. */
    private static function json(array $value): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = (string) json_encode($value, $flags);
        while (strlen($json) > 60000 && $value !== []) {
            array_pop($value);
            $json = (string) json_encode($value, $flags);
        }
        return $json;
    }

    private static function cut(string $text, int $max): string
    {
        return Prose::cut($text, $max);
    }

    private static function bytes(string $text, int $max): string
    {
        return strlen($text) > $max ? mb_strcut($text, 0, $max, 'UTF-8') : $text;
    }
}
