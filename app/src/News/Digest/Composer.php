<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Db\Database;
use Psc\News\ArticleStore;
use Psc\News\Categories;
use Psc\News\Paths;
use Psc\News\Schedule;
use Psc\News\Settings;

/**
 * What a week's digest holds (docs/NEWS.md §14.3, §14.4): its window, the articles that first went live in it, and
 * the week's row in news_digests. An article counts from the first time it went live (a takedown and a republish
 * never make it new again), and the windows of digests in consecutive weeks follow one another without a gap or an
 * overlap, so no article is in two digests and none that went live between them is left out. A window never reaches
 * back more than 7 days, so the articles of a week skipped (paused) or missed are not sent later.
 *
 * The digest shape every Courier method takes: {id: ?int, week, send_at, window_start, window_end, articles: list of
 * {id, title, dek, category, category_name, path}, left_out, sample}.
 */
final class Composer
{
    public const MAX_ARTICLES = 15;
    public const WEEK = 604800;

    /** The preview's stand-in when no article has gone live since the last digest: the newest this many. */
    public const SAMPLE_ARTICLES = 3;

    private readonly Digests $digests;

    public function __construct(private readonly App $app)
    {
        $this->digests = new Digests($app);
    }

    /**
     * The articles a digest sent at $sendAt covers: first live at or after start and before end. The end is the send
     * time. The start is where the last composed digest's window ended when that digest was the week before's, so a
     * weekday moved earlier or later leaves neither a gap nor an overlap; otherwise (the first digest, or a skipped or
     * missed week between them) the later of the week before's send time (weekBefore()) and that window's end. A
     * window never reaches back more than 7 days, so a skipped or missed week's articles are sent later only when a
     * weekday moved earlier brings some of them within those 7 days.
     *
     * @return array{start: int, end: int}
     */
    public function window(int $sendAt): array
    {
        $start = $this->weekBefore($sendAt);
        $last = $this->digests->lastComposed();
        if ($last !== null) {
            $start = $last['week'] === Week::key($start) ? (int) $last['window_end'] : max($start, (int) $last['window_end']);
        }
        return ['start' => min($start, $sendAt), 'end' => $sendAt];
    }

    /**
     * Which of these article ids are live at $now (published, their time passed), in one query: the sender reads it
     * before every message, so an article taken down mid-batch is left out of every message after that.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function live(array $ids, int $now): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_map('intval', $ids));
        $rows = $this->db()->all(
            "SELECT id FROM news_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= ? AND id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')',
            [$now, ...$ids]
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * The live articles (published, their time passed) first live in [start, end), the newest first, at most
     * MAX_ARTICLES, and how many more there were. An article's first time live is the earlier of first_live_at (kept
     * when a reschedule or a takedown would lose it) and published_at.
     *
     * @return array{ids: list<int>, left_out: int}
     */
    public function pick(int $start, int $end, int $now): array
    {
        $rows = $this->db()->all(
            'SELECT id FROM (SELECT id, CASE WHEN first_live_at IS NOT NULL AND first_live_at < published_at THEN first_live_at ELSE published_at END AS live_at'
            . " FROM news_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= ?) AS live"
            . ' WHERE live_at >= ? AND live_at < ? ORDER BY live_at DESC, id DESC',
            [$now, $start, $end]
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        return ['ids' => array_slice($ids, 0, self::MAX_ARTICLES), 'left_out' => max(0, count($ids) - self::MAX_ARTICLES)];
    }

    /**
     * Writes a week's row: empty (finished now, nothing to send) when no article went live in its window, otherwise
     * sending, with its articles and the confirmed subscribers now as its recipients. When another tick wrote the
     * week first, that row.
     *
     * @return array<string, mixed>
     */
    public function compose(string $week, int $sendAt, int $now): array
    {
        $existing = $this->digests->forWeek($week);
        if ($existing !== null) {
            return $existing;
        }
        $window = $this->window($sendAt);
        $picked = $this->pick($window['start'], $window['end'], $now);
        $empty = $picked['ids'] === [];
        $row = $this->digests->create([
            'week' => $week,
            'send_at' => $sendAt,
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'articles' => $picked['ids'],
            'left_out' => $picked['left_out'],
            'state' => $empty ? 'empty' : 'sending',
            'note' => null,
            'recipients' => $empty ? 0 : (int) $this->db()->value("SELECT COUNT(*) FROM news_digest_subscribers WHERE state = 'confirmed' AND email IS NOT NULL AND confirmed_at <= ?", [$now]),
            'composed_at' => $now,
            'finished_at' => $empty ? $now : null,
        ]);
        return $row ?? (array) $this->digests->forWeek($week);
    }

    /**
     * Writes a week skipped at its time (the digest was paused): its window for the record, no articles. Those
     * articles are not sent later: the next digest covers the 7 days before its own time. Null when another tick
     * wrote the week first.
     *
     * @return array<string, mixed>|null
     */
    public function skip(string $week, int $sendAt, string $note, int $now): ?array
    {
        $window = $this->window($sendAt);
        return $this->digests->create([
            'week' => $week,
            'send_at' => $sendAt,
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'articles' => null,
            'left_out' => 0,
            'state' => 'skipped',
            'note' => $note,
            'recipients' => 0,
            'composed_at' => $now,
            'finished_at' => $now,
        ]);
    }

    /**
     * A digest's articles that are still live, in its order: an article taken down after composing is left out of
     * every message sent after that.
     *
     * @param array<string, mixed> $digest a news_digests row (articles decoded)
     * @return list<array{id: int, title: string, dek: string, category: string, category_name: string, path: string}>
     */
    public function articles(array $digest, ?int $now = null): array
    {
        $now ??= time();
        $store = new ArticleStore($this->app);
        $out = [];
        foreach (is_array($digest['articles'] ?? null) ? $digest['articles'] : [] as $id) {
            $article = $store->find((int) $id);
            if ($article === null || $article['status'] !== 'published' || $article['published_at'] === null || (int) $article['published_at'] > $now) {
                continue;
            }
            $out[] = self::item($article);
        }
        return $out;
    }

    /**
     * A digest row as the shape the Courier takes, with its articles still live.
     *
     * @param array<string, mixed> $digest a news_digests row
     * @return array<string, mixed>
     */
    public function shape(array $digest, ?int $now = null): array
    {
        return [
            'id' => (int) $digest['id'],
            'week' => (string) $digest['week'],
            'send_at' => (int) $digest['send_at'],
            'window_start' => (int) $digest['window_start'],
            'window_end' => (int) $digest['window_end'],
            'articles' => $this->articles($digest, $now),
            'left_out' => (int) $digest['left_out'],
            'sample' => false,
        ];
    }

    /**
     * The next digest (Week::next()) as it stands now, with id null: the articles first live since its window
     * started. With none yet, the newest SAMPLE_ARTICLES live articles and sample true, so the preview and a test
     * always have something to show.
     *
     * @return array<string, mixed>
     */
    public function preview(\DateTimeImmutable $now): array
    {
        $at = $now->getTimestamp();
        $next = Week::next($now, new Settings($this->app), $this->digests->weeksSince($at - 8 * 86400));
        $window = $this->window($next['send_at']);
        $picked = $this->pick($window['start'], $window['end'], $at);
        $ids = $picked['ids'];
        $sample = $ids === [];
        if ($sample) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db()->all(
                "SELECT id FROM news_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= ? ORDER BY published_at DESC, id DESC LIMIT " . self::SAMPLE_ARTICLES,
                [$at]
            ));
        }
        return [
            'id' => null,
            'week' => $next['week'],
            'send_at' => $next['send_at'],
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'articles' => $this->articles(['articles' => $ids], $at),
            'left_out' => $sample ? 0 : $picked['left_out'],
            'sample' => $sample,
        ];
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function db(): Database
    {
        return $this->app->db();
    }

    /**
     * The same London time on the date 7 days before $at: a week by the calendar, so a clock change between them moves
     * neither end. A send time by the settings reaches back to the week before's send time by the same rule, so one
     * the spring change moved (a Sunday 01:30 that went at 02:00) reaches back to 01:30 the Sunday before, not 02:00.
     */
    private function weekBefore(int $at): int
    {
        $local = (new \DateTimeImmutable('@' . $at))->setTimezone(Schedule::zone());
        $before = $local->modify('-7 days');
        $settings = new Settings($this->app);
        if (Week::sendAt(Week::key($at), $settings) === $at) {
            $slot = Week::sendAt(Week::key($before->getTimestamp()), $settings);
            if ($slot !== null) {
                return $slot;
            }
        }
        $same = Schedule::at($before->format('Y-m-d'), $local->format('H:i'));
        return $same !== null ? $same + (int) $local->format('s') : $at - self::WEEK;
    }

    /**
     * @param array<string, mixed> $article an ArticleStore row
     * @return array{id: int, title: string, dek: string, category: string, category_name: string, path: string}
     */
    private static function item(array $article): array
    {
        $category = (string) ($article['category'] ?? '');
        return [
            'id' => (int) $article['id'],
            'title' => (string) $article['title'],
            'dek' => (string) $article['dek'],
            'category' => $category,
            'category_name' => Categories::name($category),
            'path' => Paths::article($article),
        ];
    }
}
