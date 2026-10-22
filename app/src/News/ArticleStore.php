<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;

/**
 * The only writer of the article tables (news_articles and its categories, links and follow-ups), and the reader
 * every page uses. Every save stores the draft as Draft::normalised() gives it (no em dash reaches the database),
 * fills in the SEO title and meta description when they were left empty, registers each link once by its
 * canonical key (the sources, then every other external address in the body, impact and items) keeping the click
 * counts of links that stay, counts the words and makes the slug unique (-2, -3 ...) and clear of the words the
 * routes use (Paths::isReserved()) and of every deleted article's (news_retired_slugs, isRetired()): a new
 * article's comes from Draft::slugBase(), and a stored one's changes only when a draft names one and never once the
 * article has been live (hasBeenLive()), so an address that has been shared always finds it, or answers that it is
 * gone, and never leads to another article. Each write is one transaction; a slug taken by a concurrent save is
 * retried with the next number.
 *
 * Live means status published and published_at at or before now; a published article with a later time is
 * scheduled. Rows come back with confirmed and speculation decoded to lists of {text, url}, plus categories (the
 * primary first), category (the primary, or '') and tag_list.
 */
final class ArticleStore
{
    /** The widths an article image is stored at, besides its share card (docs/NEWS.md §2.8). */
    public const IMAGE_WIDTHS = [480, 960, 1600];

    /** Where article images are stored, under storage. */
    public const IMAGE_DIR = 'uploads/news';

    /** A change within this many seconds of going live (or of being stored) is part of publishing, not an update. */
    public const UPDATED_AFTER = 600;

    private const SLUG_TRIES = 20;

    /** The primary category of the article a, for rows that are not hydrated. */
    private const PRIMARY = '(SELECT c.category FROM news_article_categories c WHERE c.article_id = a.id ORDER BY c.position, c.category LIMIT 1)';

    private const LIST_COLUMNS = 'a.id, a.slug, a.title, a.seo_title, a.dek, a.summary, a.verdict, a.tags, a.byline, a.status,'
        . ' a.published_at, a.origin, a.ai_written, a.story_key, a.comments_open, a.comment_count, a.word_count, a.created_at, a.updated_at';

    public function __construct(private readonly App $app)
    {
    }

    // ------------------------------------------------------------------------------------------------- writing

    /**
     * Stores a new article and returns its id. A published draft without a time goes live now. $userId is its
     * author (an administrator), $runId the desk's run that wrote it.
     */
    public function create(Draft $draft, ?int $userId, ?int $runId = null): int
    {
        $d = $draft->normalised();
        $now = time();
        $row = $this->columns($d) + [
            'status' => $d->status,
            'published_at' => $d->status === 'published' ? ($d->publishAt ?? $now) : $d->publishAt,
            'origin' => $d->origin,
            'author_id' => $userId,
            'run_id' => $runId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        return $this->db()->transaction(function (Database $db) use ($d, $row, $now): int {
            $base = $d->slug ?? $d->slugBase();
            for ($try = 0; ; $try++) {
                $row['slug'] = $this->uniqueSlug($db, $base, null, $try);
                try {
                    $id = $db->insert('news_articles', $row);
                    break;
                } catch (\PDOException $e) {
                    if (!self::isDuplicate($e) || $try >= self::SLUG_TRIES) {
                        throw $e;
                    }
                }
            }
            $this->writeCategories($db, $id, $d->categories);
            $this->writeLinks($db, $id, $d, $now);
            $this->writeRelated($db, $id, $this->followUpIds($db, $d->followUps, $id));
            return $id;
        });
    }

    /**
     * Changes an article's words, categories, sources and follow-ups, and returns the names of the fields that
     * changed (the article JSON's names: title, body, sources ...); nothing changed is [] and writes nothing. Its
     * status and time are changed only by publish() and unpublish(), its origin never, its slug only when the
     * draft names one and the article has never been live. An AI article stays AI-written. Throws for an article
     * that does not exist.
     *
     * $expectedUpdatedAt is the updated_at of the row the editor's form was made from. When it is given and the
     * article has been saved, published or unpublished since, nothing is written and the answer is null, so a stale
     * form never overwrites someone else's change; the check and the write are one statement. A save it allows
     * always moves updated_at past the expected one, so a form made from the row before a save is stale after it,
     * even within the same second.
     *
     * @return list<string>|null null: the article changed since $expectedUpdatedAt
     */
    public function update(int $id, Draft $draft, ?int $userId, ?int $expectedUpdatedAt = null): ?array
    {
        $db = $this->db();
        $old = $db->one('SELECT * FROM news_articles WHERE id = ?', [$id]);
        if ($old === null) {
            throw new \InvalidArgumentException('No article ' . $id);
        }
        if ($expectedUpdatedAt !== null && (int) $old['updated_at'] !== $expectedUpdatedAt) {
            return null;
        }
        $d = $draft->normalised();
        $cols = $this->columns($d);
        if ($old['origin'] === 'ai') {
            $cols['ai_written'] = 1;
        }
        if ($d->reviewReason === null) {
            unset($cols['review_reason']);
        }
        $changed = [];
        foreach ($cols as $k => $v) {
            if ($k !== 'word_count' && (string) ($old[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        $slug = null;
        if ($d->slug !== null && $d->slug !== $old['slug'] && !self::hasBeenLive($old)) {
            $changed[] = 'slug';
            $slug = $d->slug;
        }
        $categories = array_values(array_filter($d->categories, Categories::has(...)));
        if ($categories !== $this->categories($id)) {
            $changed[] = 'categories';
        }
        $sources = array_map(static fn (array $s): array => [$s['url'], $s['title'], $s['publisher'], $s['kind']], $this->draftSources($d));
        $stored = array_map(static fn (array $l): array => [(string) $l['url'], (string) ($l['title'] ?? ''), (string) ($l['publisher'] ?? ''), (string) $l['kind']], $this->sourceLinks($id));
        if ($sources !== $stored) {
            $changed[] = 'sources';
        }
        $followUps = $this->followUpIds($db, $d->followUps, $id);
        $before = array_map('intval', array_column($db->all('SELECT related_id FROM news_related WHERE article_id = ? ORDER BY related_id', [$id]), 'related_id'));
        sort($followUps);
        if ($followUps !== $before) {
            $changed[] = 'follow_up_of';
        }
        if ($changed === []) {
            return [];
        }
        $now = $expectedUpdatedAt !== null ? max(time(), $expectedUpdatedAt + 1) : time();
        $where = ['id' => $id] + ($expectedUpdatedAt !== null ? ['updated_at' => $expectedUpdatedAt] : []);
        $written = $db->transaction(function (Database $db) use ($id, $cols, $slug, $d, $categories, $followUps, $now, $where, $expectedUpdatedAt): bool {
            // The article's row is written first: when a save since has moved updated_at, it matches no row and
            // nothing else is written either.
            if ($slug !== null) {
                for ($try = 0; ; $try++) {
                    $cols['slug'] = $this->uniqueSlug($db, $slug, $id, $try);
                    try {
                        $rows = $db->update('news_articles', $cols + ['updated_at' => $now], $where);
                        break;
                    } catch (\PDOException $e) {
                        if (!self::isDuplicate($e) || $try >= self::SLUG_TRIES) {
                            throw $e;
                        }
                    }
                }
            } else {
                $rows = $db->update('news_articles', $cols + ['updated_at' => $now], $where);
            }
            if ($expectedUpdatedAt !== null && $rows !== 1) {
                return false;
            }
            $this->writeCategories($db, $id, $categories);
            $this->writeLinks($db, $id, $d, $now);
            $this->writeRelated($db, $id, $followUps);
            return true;
        });
        return $written ? $changed : null;
    }

    /**
     * Publishes an article at $at (a later time schedules it), or else at the time it was first published, or now.
     * False when it was already published at that time, or does not exist.
     *
     * Like every other write it moves updated_at past where it was (to now, or one second on when a save has already
     * put it there), so a form opened before it is stale; and a time the article already went live is kept in
     * first_live_at before a new time replaces it (hasBeenLive()).
     */
    public function publish(int $id, ?int $at = null): bool
    {
        $db = $this->db();
        $row = $db->one('SELECT status, published_at FROM news_articles WHERE id = ?', [$id]);
        if ($row === null) {
            return false;
        }
        $now = time();
        $first = $row['published_at'] !== null ? (int) $row['published_at'] : null;
        $when = $at ?? ($first !== null && $first <= $now ? $first : $now);
        if ($row['status'] === 'published' && $first === $when) {
            return false;
        }
        // first_live_at is set first: MySQL reads a column assigned earlier in the same SET as its new value.
        return $db->exec(
            "UPDATE news_articles SET first_live_at = COALESCE(first_live_at, CASE WHEN published_at <= ? THEN published_at ELSE NULL END),"
            . " status = 'published', published_at = ?, updated_at = CASE WHEN updated_at >= ? THEN updated_at + 1 ELSE ? END WHERE id = ?",
            [$now, $when, $now, $now, $id]
        ) === 1;
    }

    /**
     * Takes a published or scheduled article back to a draft, keeping its time if it went live then; a schedule that
     * never arrived is dropped, so publishing it later goes live then, not at the slot it missed. False when it was
     * not published. updated_at moves on as publish() moves it, and a time it went live is kept in first_live_at.
     */
    public function unpublish(int $id): bool
    {
        $now = time();
        return $this->db()->exec(
            "UPDATE news_articles SET first_live_at = COALESCE(first_live_at, CASE WHEN published_at <= ? THEN published_at ELSE NULL END),"
            . " status = 'draft', published_at = CASE WHEN published_at > ? THEN NULL ELSE published_at END,"
            . " updated_at = CASE WHEN updated_at >= ? THEN updated_at + 1 ELSE ? END WHERE id = ? AND status = 'published'",
            [$now, $now, $now, $now, $id]
        ) === 1;
    }

    /**
     * Deletes an article with its categories, links, clicks, follow-ups, images and comments, then its image
     * files, and returns the row as it was (for the audit), or null when there was none. Its slug is retired with it
     * (isRetired()), live or not, so no later article is given it. An imported article's news_imports row stays, so
     * the file never brings it back.
     *
     * @return array<string, mixed>|null
     */
    public function delete(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $images = $this->images($id);
        $slug = strtolower((string) $row['slug']);
        $this->db()->transaction(static function (Database $db) use ($id, $slug): void {
            $db->exec('DELETE FROM news_images WHERE article_id = ?', [$id]);
            $db->exec('DELETE FROM news_articles WHERE id = ?', [$id]);
            if ($db->value('SELECT 1 FROM news_retired_slugs WHERE slug = ?', [$slug]) === null) {
                $db->insert('news_retired_slugs', ['slug' => $slug, 'article_id' => $id, 'retired_at' => time()]);
            }
        });
        foreach ($images as $image) {
            $this->removeImageFiles($image);
        }
        return $row;
    }

    // ------------------------------------------------------------------------------------------------- reading

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $id > 0 ? $this->db()->one('SELECT * FROM news_articles WHERE id = ?', [$id]) : null;
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** @return array<string, mixed>|null the article whose slug this is (Paths::article()): live only, unless $liveOnly is false */
    public function findBySlug(string $slug, bool $liveOnly = true): ?array
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || strlen($slug) > 190) {
            return null;
        }
        $row = $liveOnly
            ? $this->db()->one("SELECT * FROM news_articles WHERE slug = ? AND status = 'published' AND published_at <= ?", [$slug, time()])
            : $this->db()->one('SELECT * FROM news_articles WHERE slug = ?', [$slug]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    /** Whether $slug was a deleted article's: never given again, and its addresses answer 410. */
    public function isRetired(string $slug): bool
    {
        $slug = strtolower(trim($slug));
        return $slug !== '' && $this->db()->value('SELECT 1 FROM news_retired_slugs WHERE slug = ?', [$slug]) !== null;
    }

    /**
     * The slug the store would give an article with this base now (uniqueSlug(), the article $id itself passed
     * over): what the editor's empty Address field stands for.
     */
    public function slugFor(string $base, ?int $id = null): string
    {
        return $this->uniqueSlug($this->db(), $base, $id, 0);
    }

    /**
     * The live articles among $slugs, by slug, each with its id, slug and primary category (for Paths::article()):
     * one query for every link a page draws to another article (Markdown::articleSlugs()).
     *
     * @param list<string> $slugs
     * @return array<string, array{id: int, slug: string, category: string}>
     */
    public function liveBySlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map(static fn (string $s): string => strtolower(trim($s)), $slugs), static fn (string $s): bool => $s !== '' && strlen($s) <= 190)));
        if ($slugs === []) {
            return [];
        }
        $out = [];
        $rows = $this->db()->all(
            'SELECT a.id, a.slug, ' . self::PRIMARY . ' AS category FROM news_articles a'
            . ' WHERE a.slug IN (' . self::marks($slugs) . ") AND a.status = 'published' AND a.published_at <= ?",
            [...$slugs, time()]
        );
        foreach ($rows as $row) {
            $out[strtolower((string) $row['slug'])] = ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'category' => (string) ($row['category'] ?? '')];
        }
        return $out;
    }

    /**
     * One page of live articles, the newest first: in a category, and matching a search of the headlines and
     * standfirsts (up to 100 characters). Each item carries its categories and its lead image (image, or null);
     * the body is left out. The page is not clamped: a page past the end has no items, so the caller can answer 404.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function live(?string $category, ?string $q, int $page = 1, int $perPage = 12): array
    {
        $db = $this->db();
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $where = ["a.status = 'published'", 'a.published_at <= ?'];
        $params = [time()];
        if ($category !== null && $category !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM news_article_categories c WHERE c.article_id = a.id AND c.category = ?)';
            $params[] = $category;
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $like = Database::like(mb_substr($q, 0, 100));
            $where[] = "(a.title LIKE ? ESCAPE '!' OR a.dek LIKE ? ESCAPE '!')";
            $params[] = $like;
            $params[] = $like;
        }
        $from = ' FROM news_articles a WHERE ' . implode(' AND ', $where);
        $total = (int) $db->value('SELECT COUNT(*)' . $from, $params);
        $items = $db->all('SELECT ' . self::LIST_COLUMNS . $from . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return ['items' => $this->hydrateList($items, true), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Articles of any status from the last $days (by published_at, else created_at; scheduled ones included), the
     * newest first: what the desk is shown and what duplicates are looked for among. Each row has id, slug, title,
     * dek, tags, story_key, status, published_at, created_at, origin, date (the time it is dated by), categories,
     * tag_list and source_keys (the canonical keys of its sources).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $days, int $limit): array
    {
        $db = $this->db();
        $rows = $db->all(
            'SELECT a.id, a.slug, a.title, a.dek, a.tags, a.story_key, a.status, a.published_at, a.created_at, a.origin,'
            . ' COALESCE(a.published_at, a.created_at) AS dated FROM news_articles a'
            . ' WHERE COALESCE(a.published_at, a.created_at) >= ? ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC LIMIT ' . max(1, $limit),
            [time() - $days * 86400]
        );
        $keys = $this->sourceKeys(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $rows = $this->hydrateList($rows, false);
        foreach ($rows as $i => $r) {
            $rows[$i]['date'] = (int) $r['dated'];
            unset($rows[$i]['dated']);
            $rows[$i]['source_keys'] = $keys[(int) $r['id']] ?? [];
        }
        return $rows;
    }

    /** @return array<string, int> category key => live articles, in Categories::KEYS order, only those with any */
    public function categoryCounts(): array
    {
        $rows = $this->db()->all(
            "SELECT c.category, COUNT(*) AS n FROM news_article_categories c JOIN news_articles a ON a.id = c.article_id"
            . " WHERE a.status = 'published' AND a.published_at <= ? GROUP BY c.category",
            [time()]
        );
        $counts = array_column($rows, 'n', 'category');
        $out = [];
        foreach (Categories::KEYS as $key) {
            if ((int) ($counts[$key] ?? 0) > 0) {
                $out[$key] = (int) $counts[$key];
            }
        }
        return $out;
    }

    /** @return list<string> the article's category keys, the primary first */
    public function categories(int $id): array
    {
        return array_map('strval', array_column($this->db()->all('SELECT category FROM news_article_categories WHERE article_id = ? ORDER BY position, category', [$id]), 'category'));
    }

    /**
     * Every link registered for the article: the sources first, in their order, then the other external links.
     *
     * @return list<array<string, mixed>> news_links rows
     */
    public function links(int $id): array
    {
        return $this->db()->all('SELECT * FROM news_links WHERE article_id = ? ORDER BY is_source DESC, position, id', [$id]);
    }

    /** @return array<string, int> url_key => news_links.id, for Markdown::render() */
    public function linkIds(int $id): array
    {
        $out = [];
        foreach ($this->db()->all('SELECT id, url_key FROM news_links WHERE article_id = ? ORDER BY id', [$id]) as $l) {
            $out[(string) $l['url_key']] ??= (int) $l['id'];
        }
        return $out;
    }

    /**
     * The canonical keys of each article's sources.
     *
     * @param list<int> $ids
     * @return array<int, list<string>> article id => url keys
     */
    public function sourceKeys(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db()->all('SELECT article_id, url_key FROM news_links WHERE is_source = 1 AND article_id IN (' . self::marks($ids) . ') ORDER BY position, id', $ids) as $l) {
            $out[(int) $l['article_id']][] = (string) $l['url_key'];
        }
        return $out;
    }

    /**
     * The articles this one follows up (earlier) and those that follow it up (later), the oldest first; live ones
     * only unless $liveOnly is false. Rows: id, slug, title, status, published_at and category (the primary, for
     * Paths::article()).
     *
     * @return array{earlier: list<array<string, mixed>>, later: list<array<string, mixed>>}
     */
    public function related(int $id, bool $liveOnly = true): array
    {
        $live = $liveOnly ? " AND a.status = 'published' AND a.published_at <= ?" : '';
        $params = $liveOnly ? [$id, time()] : [$id];
        $cols = 'a.id, a.slug, a.title, a.status, a.published_at, ' . self::PRIMARY . ' AS category';
        return [
            'earlier' => $this->db()->all("SELECT {$cols} FROM news_related r JOIN news_articles a ON a.id = r.related_id WHERE r.article_id = ?{$live} ORDER BY COALESCE(a.published_at, a.created_at), a.id", $params),
            'later' => $this->db()->all("SELECT {$cols} FROM news_related r JOIN news_articles a ON a.id = r.article_id WHERE r.related_id = ?{$live} ORDER BY COALESCE(a.published_at, a.created_at), a.id", $params),
        ];
    }

    /** @return list<array<string, mixed>> the article's news_images rows, the lead (position 0) first */
    public function images(int $id): array
    {
        return $this->db()->all('SELECT * FROM news_images WHERE article_id = ? ORDER BY position, id', [$id]);
    }

    /** @param array<string, mixed> $row */
    public function isLive(array $row, ?int $now = null): bool
    {
        return ($row['status'] ?? '') === 'published' && ($row['published_at'] ?? null) !== null && (int) $row['published_at'] <= ($now ?? time());
    }

    /**
     * Whether the article has ever been live: it went live at a time that has passed (published_at, which a takedown
     * keeps), or first_live_at kept that time before a reschedule moved it. From then on its slug never changes.
     *
     * @param array<string, mixed> $row
     */
    public static function hasBeenLive(array $row, ?int $now = null): bool
    {
        return ($row['first_live_at'] ?? null) !== null
            || (($row['published_at'] ?? null) !== null && (int) $row['published_at'] <= ($now ?? time()));
    }

    /**
     * When the article was updated after it went live: its last change, when that came more than UPDATED_AFTER after
     * it went live and after it was stored (an import dated earlier than it was stored has not changed since), else
     * null. The page's "Updated" line and its dateModified.
     *
     * @param array<string, mixed> $row
     */
    public static function updatedAfterLive(array $row): ?int
    {
        if (($row['published_at'] ?? null) === null) {
            return null;
        }
        $changed = (int) $row['updated_at'];
        return $changed - max((int) $row['published_at'], (int) $row['created_at']) > self::UPDATED_AFTER ? $changed : null;
    }

    /**
     * The article's last change that counts: updatedAfterLive(), else the time it went live (else its last save). What
     * the page's dateModified, the feeds and the sitemaps all give.
     *
     * @param array<string, mixed> $row
     */
    public static function lastChange(array $row): int
    {
        return self::updatedAfterLive($row) ?? (($row['published_at'] ?? null) !== null ? (int) $row['published_at'] : (int) $row['updated_at']);
    }

    /**
     * Up to $limit of the newest live articles in a category, never $exceptId: an article's "More in" list. Rows as
     * live() gives them (no body, no image).
     *
     * @return list<array<string, mixed>>
     */
    public function moreIn(string $category, int $exceptId, int $limit): array
    {
        if (!Categories::has($category) || $limit <= 0) {
            return [];
        }
        $rows = $this->db()->all(
            'SELECT ' . self::LIST_COLUMNS . " FROM news_articles a WHERE a.status = 'published' AND a.published_at <= ? AND a.id <> ?"
            . ' AND EXISTS (SELECT 1 FROM news_article_categories c WHERE c.article_id = a.id AND c.category = ?)'
            . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ' . $limit,
            [time(), $exceptId, $category]
        );
        return $this->hydrateList($rows, false);
    }

    /**
     * Each category with live articles, with the newest lastChange() among them, in Categories::KEYS order: when its
     * hub last changed.
     *
     * @return array<string, int> key => Unix time
     */
    public function categoryTimes(): array
    {
        $times = [];
        foreach ($this->db()->all(
            'SELECT c.category, a.published_at, a.updated_at, a.created_at FROM news_article_categories c JOIN news_articles a ON a.id = c.article_id'
            . " WHERE a.status = 'published' AND a.published_at <= ?",
            [time()]
        ) as $row) {
            $key = (string) $row['category'];
            $times[$key] = max($times[$key] ?? 0, self::lastChange($row));
        }
        $out = [];
        foreach (Categories::KEYS as $key) {
            if (isset($times[$key])) {
                $out[$key] = $times[$key];
            }
        }
        return $out;
    }

    /**
     * The London months with live articles, the newest first, each with the newest lastChange() among its articles:
     * the section's sitemaps, one a month.
     *
     * @return array<string, int> YYYY-MM => Unix time
     */
    public function months(): array
    {
        $zone = new \DateTimeZone(Schedule::ZONE);
        $out = [];
        foreach ($this->db()->all("SELECT a.published_at, a.updated_at, a.created_at FROM news_articles a WHERE a.status = 'published' AND a.published_at <= ?", [time()]) as $row) {
            $month = (new \DateTimeImmutable('@' . (int) $row['published_at']))->setTimezone($zone)->format('Y-m');
            $out[$month] = max($out[$month] ?? 0, self::lastChange($row));
        }
        krsort($out, SORT_STRING);
        return $out;
    }

    /**
     * The live articles that went live in one London month (YYYY-MM), the newest first, each with its lead image
     * (image, or null). Empty for a month that is not one.
     *
     * @return list<array<string, mixed>>
     */
    public function liveInMonth(string $month): array
    {
        $zone = new \DateTimeZone(Schedule::ZONE);
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $zone) : false;
        if ($start === false) {
            return [];
        }
        return $this->liveBetween($start->getTimestamp(), $start->modify('first day of next month')->getTimestamp());
    }

    /**
     * The live articles that went live at or after $since, the newest first, each with its lead image (image, or null).
     *
     * @return list<array<string, mixed>>
     */
    public function liveSince(int $since): array
    {
        return $this->liveBetween($since, PHP_INT_MAX);
    }

    /**
     * The stored article as a Draft, for an editor to change or a publish to be checked again: its sources from
     * its links, its follow-ups as ids, its current slug.
     */
    public function draft(int $id): ?Draft
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $d = new Draft((string) $row['origin']);
        $d->title = (string) $row['title'];
        $d->seoTitle = (string) ($row['seo_title'] ?? '');
        $d->dek = (string) $row['dek'];
        $d->summary = (string) ($row['summary'] ?? '');
        $d->body = (string) $row['body'];
        $d->confirmed = $row['confirmed'];
        $d->speculation = $row['speculation'];
        $d->impact = (string) ($row['impact'] ?? '');
        $d->verdict = (string) ($row['verdict'] ?? '');
        $d->verdictNote = (string) ($row['verdict_note'] ?? '');
        $d->categories = $row['categories'];
        $d->tags = $row['tag_list'];
        $d->sources = array_map(static fn (array $l): array => [
            'url' => (string) $l['url'],
            'title' => (string) ($l['title'] ?? ''),
            'publisher' => (string) ($l['publisher'] ?? ''),
            'kind' => (string) $l['kind'],
        ], $this->sourceLinks($id));
        $d->followUps = array_map(static fn (array $r): int => (int) $r['id'], $this->related($id, false)['earlier']);
        $d->storyKey = (string) ($row['story_key'] ?? '');
        $d->byline = (string) ($row['byline'] ?? '');
        $d->status = (string) $row['status'];
        $d->publishAt = $row['published_at'] !== null ? (int) $row['published_at'] : null;
        $d->commentsOpen = (int) $row['comments_open'] === 1;
        $d->aiWritten = (int) $row['ai_written'] === 1;
        $d->slug = (string) $row['slug'];
        $d->reviewReason = $row['review_reason'] !== null ? (string) $row['review_reason'] : null;
        return $d;
    }

    /**
     * The files an image is stored as, by the naming scheme the media routes serve: <base>-480.webp,
     * <base>-960.webp, <base>-1600.webp and the share card <base>-og.jpg. None for a base that is not 32 hex.
     *
     * @param array<string, mixed> $image a news_images row
     * @return list<string>
     */
    public static function imageFiles(array $image): array
    {
        $base = (string) ($image['base'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $base)) {
            return [];
        }
        $files = [];
        foreach (self::IMAGE_WIDTHS as $w) {
            $files[] = $base . '-' . $w . '.webp';
        }
        $files[] = $base . '-og.jpg';
        return $files;
    }

    /** Adds one click to the link's total and to its row for $day (Y-m-d), making that row on the day's first click. */
    public function countClick(int $linkId, string $day): void
    {
        $this->db()->transaction(static function (Database $db) use ($linkId, $day): void {
            $db->exec('UPDATE news_links SET clicks = clicks + 1 WHERE id = ?', [$linkId]);
            if ($db->exec('UPDATE news_link_clicks SET clicks = clicks + 1 WHERE link_id = ? AND day = ?', [$linkId, $day]) === 0) {
                try {
                    $db->insert('news_link_clicks', ['link_id' => $linkId, 'day' => $day, 'clicks' => 1]);
                } catch (\PDOException $e) {
                    // Another click made the day's row first: add to it.
                    if (!self::isDuplicate($e)) {
                        throw $e;
                    }
                    $db->exec('UPDATE news_link_clicks SET clicks = clicks + 1 WHERE link_id = ? AND day = ?', [$linkId, $day]);
                }
            }
        });
    }

    /** Whether a database error is a unique key refused (a slug, an import key, a slot taken by someone else). */
    public static function isDuplicate(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        return $driverCode === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    // ------------------------------------------------------------------------------------------------- internals

    private function db(): Database
    {
        return $this->app->db();
    }

    /**
     * The article columns a draft sets (status, time, origin, slug and ownership aside).
     *
     * @return array<string, mixed>
     */
    private function columns(Draft $d): array
    {
        $json = static fn (array $items): ?string => $items !== [] ? json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        $seoTitle = $d->seoTitleOrDefault();
        $summary = $d->summaryOrDefault();
        return [
            'title' => $d->title,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'dek' => $d->dek,
            'summary' => $summary !== '' ? $summary : null,
            'body' => $d->body,
            'confirmed' => $json($d->confirmed),
            'speculation' => $json($d->speculation),
            'impact' => $d->impact !== '' ? $d->impact : null,
            'verdict' => $d->verdict !== '' ? $d->verdict : null,
            'verdict_note' => $d->verdictNote !== '' ? $d->verdictNote : null,
            'tags' => $d->tags !== [] ? implode(',', $d->tags) : null,
            'byline' => $d->byline !== '' ? $d->byline : null,
            'ai_written' => $d->aiWritten ? 1 : 0,
            'review_reason' => $d->reviewReason,
            'story_key' => $d->storyKey !== '' ? $d->storyKey : null,
            'comments_open' => $d->commentsOpen ? 1 : 0,
            'word_count' => Prose::words(implode("\n\n", array_merge(
                [$d->dek, $d->body, $d->impact],
                array_column($d->confirmed, 'text'),
                array_column($d->speculation, 'text'),
            ))),
        ];
    }

    /**
     * The first free slug among $base, $base-2, $base-3 ... (Paths::numbered()), passing over the words the routes use,
     * the category keys and the slugs of deleted articles, and skipping $skip free ones (a retry after a clash).
     */
    private function uniqueSlug(Database $db, string $base, ?int $exceptId, int $skip): string
    {
        $base = $base !== '' ? $base : 'article';
        for ($n = 1; ; $n++) {
            $slug = Paths::numbered($base, $n);
            if (Paths::isReserved($slug) || $db->value('SELECT 1 FROM news_retired_slugs WHERE slug = ?', [$slug]) !== null) {
                continue;
            }
            $id = $db->value('SELECT id FROM news_articles WHERE slug = ?', [$slug]);
            if (($id === null || (int) $id === $exceptId) && $skip-- <= 0) {
                return $slug;
            }
        }
    }

    /**
     * The live articles that went live in [$from, $to), the newest first, with their lead images.
     *
     * @return list<array<string, mixed>>
     */
    private function liveBetween(int $from, int $to): array
    {
        $now = time();
        $rows = $this->db()->all(
            'SELECT ' . self::LIST_COLUMNS . " FROM news_articles a WHERE a.status = 'published' AND a.published_at <= ? AND a.published_at >= ? AND a.published_at < ?"
            . ' ORDER BY a.published_at DESC, a.id DESC',
            [$now, $from, min($to, $now + 1)]
        );
        return $this->hydrateList($rows, true);
    }

    /** @param list<string> $categories */
    private function writeCategories(Database $db, int $id, array $categories): void
    {
        $db->exec('DELETE FROM news_article_categories WHERE article_id = ?', [$id]);
        $position = 0;
        foreach (array_values(array_unique(array_filter($categories, Categories::has(...)))) as $category) {
            $db->insert('news_article_categories', ['article_id' => $id, 'category' => $category, 'position' => $position++]);
        }
    }

    /**
     * Registers the article's links by canonical key: the sources first (is_source 1, in order), then every other
     * external address in the body, impact and items (is_source 0). A link that stays keeps its row and its
     * clicks; one that went is removed.
     */
    private function writeLinks(Database $db, int $id, Draft $d, int $now): void
    {
        $wanted = [];
        $position = 0;
        foreach ($this->draftSources($d) as $s) {
            $wanted[(string) Urls::key($s['url'])] = ['url' => $s['url'], 'title' => $s['title'], 'publisher' => $s['publisher'], 'kind' => $s['kind'], 'is_source' => 1, 'position' => $position++];
        }
        $others = array_merge($d->inlineLinks(), array_column($d->confirmed, 'url'), array_column($d->speculation, 'url'));
        foreach ($others as $url) {
            $url = (string) $url;
            if (!preg_match('#^https?://#i', $url) || mb_strlen($url) > 2000 || Urls::isOwn($url, $this->app->baseUrl())) {
                continue;
            }
            $key = Urls::key($url);
            if ($key === null || isset($wanted[$key])) {
                continue;
            }
            $wanted[$key] = ['url' => $url, 'title' => '', 'publisher' => '', 'kind' => 'other', 'is_source' => 0, 'position' => $position++];
        }
        $existing = [];
        foreach ($db->all('SELECT id, url_key FROM news_links WHERE article_id = ? ORDER BY id', [$id]) as $l) {
            if (isset($existing[(string) $l['url_key']]) || !isset($wanted[(string) $l['url_key']])) {
                $db->exec('DELETE FROM news_links WHERE id = ?', [(int) $l['id']]);
                continue;
            }
            $existing[(string) $l['url_key']] = (int) $l['id'];
        }
        foreach ($wanted as $key => $w) {
            $fields = [
                'url' => $w['url'],
                'host' => (string) Urls::host($w['url']),
                'title' => $w['title'] !== '' ? mb_substr($w['title'], 0, 300) : null,
                'publisher' => $w['publisher'] !== '' ? mb_substr($w['publisher'], 0, 160) : null,
                'kind' => $w['kind'],
                'is_source' => $w['is_source'],
                'position' => $w['position'],
            ];
            if (isset($existing[$key])) {
                $db->update('news_links', $fields, ['id' => $existing[$key]]);
            } else {
                $db->insert('news_links', $fields + ['article_id' => $id, 'url_key' => $key, 'clicks' => 0, 'created_at' => $now]);
            }
        }
    }

    /**
     * The draft's sources that are addresses, once each by canonical key.
     *
     * @return list<array{url: string, title: string, publisher: string, kind: string}>
     */
    private function draftSources(Draft $d): array
    {
        $out = [];
        $seen = [];
        foreach ($d->sources as $s) {
            $key = Urls::key($s['url']);
            if ($key === null || isset($seen[$key]) || mb_strlen($s['url']) > 2000) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the news_links rows that are the article's sources, in order */
    private function sourceLinks(int $id): array
    {
        return $this->db()->all('SELECT * FROM news_links WHERE article_id = ? AND is_source = 1 ORDER BY position, id', [$id]);
    }

    /** @param list<int> $ids */
    private function writeRelated(Database $db, int $id, array $ids): void
    {
        $db->exec('DELETE FROM news_related WHERE article_id = ?', [$id]);
        foreach ($ids as $related) {
            $db->insert('news_related', ['article_id' => $id, 'related_id' => $related]);
        }
    }

    /**
     * Follow-ups (ids, or slugs from an import file) as the ids of articles that exist, never the article itself.
     *
     * @param list<int|string> $followUps
     * @return list<int>
     */
    private function followUpIds(Database $db, array $followUps, int $selfId): array
    {
        $ids = [];
        foreach ($followUps as $f) {
            $found = is_int($f)
                ? $db->value('SELECT id FROM news_articles WHERE id = ?', [$f])
                : $db->value('SELECT id FROM news_articles WHERE slug = ?', [strtolower((string) $f)]);
            if ($found !== null && (int) $found !== $selfId && !in_array((int) $found, $ids, true)) {
                $ids[] = (int) $found;
            }
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return $this->hydrateList([$row], false)[0];
    }

    /**
     * Adds categories, category, tag_list and the decoded items to each row, and with $images each row's lead
     * image (image: a news_images row, or null).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateList(array $rows, bool $images): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $categories = [];
        foreach ($this->db()->all('SELECT article_id, category FROM news_article_categories WHERE article_id IN (' . self::marks($ids) . ') ORDER BY article_id, position, category', $ids) as $c) {
            $categories[(int) $c['article_id']][] = (string) $c['category'];
        }
        $leads = [];
        if ($images) {
            foreach ($this->db()->all('SELECT * FROM news_images WHERE article_id IN (' . self::marks($ids) . ') ORDER BY article_id, position, id', $ids) as $img) {
                $leads[(int) $img['article_id']] ??= $img;
            }
        }
        foreach ($rows as $i => $r) {
            $id = (int) $r['id'];
            $rows[$i]['categories'] = $categories[$id] ?? [];
            $rows[$i]['category'] = $categories[$id][0] ?? '';
            $rows[$i]['tag_list'] = isset($r['tags']) && $r['tags'] !== null && $r['tags'] !== '' ? explode(',', (string) $r['tags']) : [];
            foreach (['confirmed', 'speculation'] as $list) {
                if (array_key_exists($list, $r)) {
                    $rows[$i][$list] = self::items($r[$list]);
                }
            }
            if ($images) {
                $rows[$i]['image'] = $leads[$id] ?? null;
            }
        }
        return $rows;
    }

    /** @return list<array{text: string, url: string}> */
    private static function items(mixed $json): array
    {
        $list = is_string($json) && $json !== '' ? json_decode($json, true) : [];
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item)) {
                $out[] = ['text' => (string) ($item['text'] ?? ''), 'url' => (string) ($item['url'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * Removes an image's files: through the Images class when it is installed, then by the naming scheme
     * (imageFiles()) under storage, so no file outlives its article either way. A failure is logged, never thrown:
     * the article is already gone.
     *
     * @param array<string, mixed> $image
     */
    private function removeImageFiles(array $image): void
    {
        $images = __NAMESPACE__ . '\\Images';
        try {
            if (class_exists($images) && method_exists($images, 'delete')) {
                (new $images($this->app))->delete($image);
            }
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news image removal');
        }
        foreach (self::imageFiles($image) as $name) {
            $path = $this->app->storage->path(self::IMAGE_DIR . '/' . $name);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
