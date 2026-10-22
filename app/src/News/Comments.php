<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;
use Psc\Support\Names;

/**
 * Industry Updates comments (docs/NEWS.md §9): plain text from signed-in, active accounts, on live articles only.
 * A comment shows its author's short name (Names::publicName) and, for an administrator, the STAFF_BADGE, an element
 * of its own that no name can produce. It is checked here for every route (the API and the comment page alike): 2 to
 * 2000 characters, at most two links, no email address; the same words from the same person on the same article
 * within a day are the comment already posted. Posting is rate limited per account and per network, in hashed
 * rate-limit buckets; no network address is stored.
 *
 * A comment waits as pending while the desk's comments setting is "approval", or when an account younger than a day
 * includes a link; only its author sees it. Anyone signed in may report someone else's visible comment once; the
 * HIDE_AT-th report hides it until an administrator looks (never a staff comment), and the first report emails the
 * administrators. Its author may delete any of their comments, in any status, also once the article is not live
 * (the privacy policy's promise), and still sees a hidden one, marked so, to do that. comment_count on the article
 * is the number of visible comments, recounted from the table in the same transaction as every change that can
 * move it.
 *
 * Every transaction that writes an article's comments or reports takes that article's row first (lockArticles), so
 * writers on one article queue there; InnoDB would otherwise deadlock two of them on the foreign key's shared lock and
 * the recount's next-key locks, and roll one back.
 */
final class Comments
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 2000;
    public const MAX_LINKS = 2;

    /** The same words from the same person on the same article within this time are the comment already posted. */
    public const REPEAT_SECONDS = 86400;

    /** An account younger than this whose comment includes a link waits for an administrator. */
    public const NEW_ACCOUNT_SECONDS = 86400;

    /** The report that hides a comment until an administrator looks. */
    public const HIDE_AT = 3;

    /** The most visible comments an article shows (the newest). */
    public const PAGE_MAX = 500;

    /** Rate limits: [most, seconds]. */
    public const LIMIT_USER = [5, 600];
    public const LIMIT_USER_DAY = [30, 86400];
    public const LIMIT_NETWORK_DAY = [20, 86400];
    public const LIMIT_REPORTS_DAY = [20, 86400];

    public const STATUSES = ['visible', 'pending', 'hidden'];

    /** The primary category of the article a, for Paths::article() on rows that join it. */
    private const PRIMARY = '(SELECT ac.category FROM news_article_categories ac WHERE ac.article_id = a.id ORDER BY ac.position, ac.category LIMIT 1)';

    public const STAFF_BADGE = 'Patterns team';
    public const PENDING_NOTE = 'Only you can see this until it is approved.';
    public const HIDDEN_NOTE = 'Hidden by a moderator. Only you can see this, and you can delete it.';
    public const CLOSED_ARTICLE = 'Comments are closed on this article.';
    public const CLOSED_SITE = 'Comments are closed at the moment.';

    public const TOO_SHORT = 'Write a comment of at least 2 characters.';
    public const NO_EMAIL = 'Remove the email address. Comments are public, and addresses on public pages are collected for spam.';
    public const TOO_MANY_LINKS = 'Use 2 links or fewer. Links show as plain text, so the name of the page works just as well.';
    public const SLOW_DOWN = 'You have posted several comments in the last few minutes. Please wait a few minutes, then try again.';
    public const DAY_LIMIT = 'You have posted the most comments allowed in a day. Please try again tomorrow.';
    public const NETWORK_LIMIT = 'Many comments have come from your network today. Please try again tomorrow.';
    public const REPORT_LIMIT = 'You have reported the most comments allowed in a day. Please try again tomorrow, or tell us through the contact page.';
    public const HIDDEN_REPEAT = 'You posted these words earlier and they were hidden. If you think that was a mistake, tell us through the contact page.';
    public const NOT_LIVE = 'This article is not published, so it takes no comments.';
    public const NOT_LIVE_OWN = 'This article is not published at the moment, so it takes no comments. You can still delete yours.';

    private const COLUMNS = 'c.id, c.article_id, c.user_id, c.body, c.status, c.reports, c.created_at, c.updated_at,'
        . ' u.name AS author_name, u.email AS author_email, u.role AS author_role';

    private ?string $mode = null;

    public function __construct(private readonly App $app)
    {
    }

    // ------------------------------------------------------------------------------------------------ the rules

    /**
     * A comment's text as stored: UTF-8, Unix line ends, no control characters but tab and newline, no invisible or
     * reordering characters (Names::INVISIBLE), no trailing spaces, at most one blank line in a row; trimmed.
     */
    public static function clean(string $body): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $body);
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '';
        }
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = (string) preg_replace(Names::INVISIBLE, '', $s);
        $s = (string) preg_replace('/\h+$/mu', '', $s);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
        return trim($s);
    }

    /** What is wrong with a cleaned comment, in words that say what to do; null when it may be posted. */
    public static function problem(string $body): ?string
    {
        $length = mb_strlen($body);
        if ($length < self::MIN_LENGTH) {
            return self::TOO_SHORT;
        }
        if ($length > self::MAX_LENGTH) {
            return 'Keep your comment to ' . number_format(self::MAX_LENGTH) . ' characters or fewer. It is ' . number_format($length) . ' now.';
        }
        if (Prose::emails($body)) {
            return self::NO_EMAIL;
        }
        if (self::links($body) > self::MAX_LINKS) {
            return self::TOO_MANY_LINKS;
        }
        return null;
    }

    /**
     * How many web addresses the text holds (from http://, https:// or www. to the next space, so the www. inside
     * https://www.… is the same address); they are shown as text, never as links.
     */
    public static function links(string $body): int
    {
        return (int) preg_match_all('~(?<![\p{L}\p{N}])(?:https?://|www\.)\S*~iu', $body);
    }

    /**
     * The name a person's comments show: the first name and the initial of the last (Names::publicName).
     *
     * @param array<string, mixed> $user a users row: name and email
     */
    public static function publicName(array $user): string
    {
        return Names::publicName($user);
    }

    /** @param array<string, mixed> $user */
    public static function isStaff(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /**
     * Why the article takes no new comments, as the sentence its box shows, or null while it does: its own switch
     * (comments_open), then the desk's comments setting.
     *
     * @param array<string, mixed> $article
     */
    public function closedReason(array $article): ?string
    {
        if ((int) ($article['comments_open'] ?? 0) !== 1) {
            return self::CLOSED_ARTICLE;
        }
        return $this->mode() === 'closed' ? self::CLOSED_SITE : null;
    }

    /** The desk's comments setting: open, approval or closed. */
    public function mode(): string
    {
        return $this->mode ??= (string) (new Settings($this->app))->get('comments');
    }

    // ---------------------------------------------------------------------------------------------- writing

    /**
     * Posts $body for $user on the article. On success, status is the comment's (visible or pending) and repeat says
     * whether these were words already posted (that comment is returned and nothing is stored). Otherwise status is
     * why: not_found (not a live article), closed, invalid (errors holds body => what to fix), rate_limited or
     * hidden (the same words were posted and hidden); message says it in words.
     *
     * @param array<string, mixed> $user the signed-in account (a users row)
     * @return array{ok: bool, status: string, comment?: array<string, mixed>, repeat?: bool, errors: array<string, string>, message: string}
     */
    public function post(int $articleId, array $user, string $body): array
    {
        $article = $this->liveArticle($articleId);
        if ($article === null) {
            return self::refused('not_found', self::NOT_LIVE);
        }
        $closed = $this->closedReason($article);
        if ($closed !== null) {
            return self::refused('closed', $closed);
        }
        $body = self::clean($body);
        $problem = self::problem($body);
        if ($problem !== null) {
            return self::refused('invalid', $problem, ['body' => $problem]);
        }
        $uid = (int) $user['id'];
        $now = time();
        $repeat = $this->repeatOf($articleId, $uid, $body, $now);
        if ($repeat !== null) {
            if ($repeat['status'] === 'hidden') {
                return self::refused('hidden', self::HIDDEN_REPEAT);
            }
            return ['ok' => true, 'status' => (string) $repeat['status'], 'comment' => $repeat, 'repeat' => true, 'errors' => [], 'message' => ''];
        }
        $limiter = $this->app->limiter();
        if (!$limiter->hit('news-comment:' . $uid, self::LIMIT_USER[0], self::LIMIT_USER[1])) {
            return self::refused('rate_limited', self::SLOW_DOWN);
        }
        if (!$limiter->hit('news-comment-day:' . $uid, self::LIMIT_USER_DAY[0], self::LIMIT_USER_DAY[1])) {
            return self::refused('rate_limited', self::DAY_LIMIT);
        }
        if (!$limiter->hit('news-comment-ip:' . $this->app->clientIp(), self::LIMIT_NETWORK_DAY[0], self::LIMIT_NETWORK_DAY[1])) {
            return self::refused('rate_limited', self::NETWORK_LIMIT);
        }
        // Staff comments never wait; anyone else's do in approval mode, and a new account's when it links somewhere.
        $young = $now - (int) ($user['created_at'] ?? 0) < self::NEW_ACCOUNT_SECONDS;
        $pending = !self::isStaff($user) && ($this->mode() === 'approval' || ($young && self::links($body) > 0));
        $status = $pending ? 'pending' : 'visible';
        $id = $this->db()->transaction(function (Database $db) use ($articleId, $uid, $body, $status, $now): int {
            self::lockArticles($db, [$articleId]);
            $id = $db->insert('news_comments', [
                'article_id' => $articleId,
                'user_id' => $uid,
                'body' => $body,
                'status' => $status,
                'reports' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($status === 'visible') {
                self::recountArticles($db, [$articleId]);
            }
            return $id;
        });
        if ($pending) {
            $this->tellAdminsPending($article, $user, $id, $body);
        }
        return ['ok' => true, 'status' => $status, 'comment' => (array) $this->find($id), 'repeat' => false, 'errors' => [], 'message' => ''];
    }

    /**
     * Deletes a comment for good, in any status and whether or not its article is live: the author's own, or any for
     * an administrator. False when it is gone already or the actor may not delete it.
     *
     * @param array<string, mixed> $actor a users row
     */
    public function remove(int $commentId, array $actor): bool
    {
        $db = $this->db();
        $row = $db->one('SELECT id, article_id, user_id FROM news_comments WHERE id = ?', [$commentId]);
        if ($row === null || (!self::isStaff($actor) && (int) $row['user_id'] !== (int) ($actor['id'] ?? 0))) {
            return false;
        }
        // A comment's article and author never change, so the read above holds; its status may not (an approval
        // can land in between), so the article is recounted whatever the status was.
        return $db->transaction(static function (Database $db) use ($row): bool {
            self::lockArticles($db, [(int) $row['article_id']]);
            if ($db->exec('DELETE FROM news_comments WHERE id = ?', [(int) $row['id']]) !== 1) {
                return false;
            }
            self::recountArticles($db, [(int) $row['article_id']]);
            return true;
        });
    }

    /**
     * Reports someone else's visible comment for $user: reported, or why not: already (this person reported it
     * before), own, staff (administrators hide comments from the moderation page instead), limited (too many reports
     * today) or missing (no such visible comment). The HIDE_AT-th report hides a comment not written by staff; the
     * first one emails the administrators.
     *
     * @param array<string, mixed> $user the signed-in account
     */
    public function report(int $commentId, array $user): string
    {
        $comment = $this->find($commentId);
        if ($comment === null || $comment['status'] !== 'visible') {
            return 'missing';
        }
        $uid = (int) $user['id'];
        if ((int) $comment['user_id'] === $uid) {
            return 'own';
        }
        if (self::isStaff($user)) {
            return 'staff';
        }
        $db = $this->db();
        if ($db->value('SELECT 1 FROM news_comment_reports WHERE comment_id = ? AND user_id = ?', [$commentId, $uid]) !== null) {
            return 'already';
        }
        if (!$this->app->limiter()->hit('news-report:' . $uid, self::LIMIT_REPORTS_DAY[0], self::LIMIT_REPORTS_DAY[1])) {
            return 'limited';
        }
        $now = time();
        try {
            $result = $db->transaction(static function (Database $db) use ($comment, $commentId, $uid, $now): array {
                self::lockArticles($db, [(int) $comment['article_id']]);
                $db->insert('news_comment_reports', ['comment_id' => $commentId, 'user_id' => $uid, 'created_at' => $now]);
                $db->exec('UPDATE news_comments SET reports = reports + 1 WHERE id = ?', [$commentId]);
                $reports = (int) $db->value('SELECT reports FROM news_comments WHERE id = ?', [$commentId]);
                $hidden = false;
                // Hidden on the report that reaches the mark, so a comment an administrator shows again stays shown.
                if ($reports === self::HIDE_AT && $comment['author_role'] !== 'admin') {
                    $hidden = $db->update('news_comments', ['status' => 'hidden', 'updated_at' => $now], ['id' => $commentId, 'status' => 'visible']) === 1;
                    if ($hidden) {
                        self::recountArticles($db, [(int) $comment['article_id']]);
                    }
                }
                return ['reports' => $reports, 'hidden' => $hidden];
            });
        } catch (\PDOException $e) {
            // The same person's report sent twice at once: the other request recorded it.
            if (ArticleStore::isDuplicate($e)) {
                return 'already';
            }
            throw $e;
        }
        if ($result['reports'] === 1) {
            $this->tellAdminsReported($comment, false);
        } elseif ($result['hidden']) {
            $this->tellAdminsReported($comment, true);
        }
        return 'reported';
    }

    /**
     * Moves a comment from one status to another for moderation, only from the status the caller read (so a change
     * made by someone else in the meantime is never overwritten). False when it was not in $from.
     */
    public function moveStatus(int $commentId, string $from, string $to): bool
    {
        if (!in_array($from, self::STATUSES, true) || !in_array($to, self::STATUSES, true) || $from === $to) {
            return false;
        }
        $articleId = $this->articleOf($commentId);
        if ($articleId === null) {
            return false;
        }
        return $this->db()->transaction(static function (Database $db) use ($commentId, $articleId, $from, $to): bool {
            self::lockArticles($db, [$articleId]);
            if ($db->update('news_comments', ['status' => $to, 'updated_at' => time()], ['id' => $commentId, 'status' => $from]) !== 1) {
                return false;
            }
            if ($from === 'visible' || $to === 'visible') {
                self::recountArticles($db, [$articleId]);
            }
            return true;
        });
    }

    /** Forgets a comment's reports (an administrator has looked); the same people may report it again. Returns how many. */
    public function clearReports(int $commentId): int
    {
        $articleId = (int) $this->articleOf($commentId);
        return $this->db()->transaction(static function (Database $db) use ($commentId, $articleId): int {
            self::lockArticles($db, [$articleId]);
            $cleared = $db->exec('DELETE FROM news_comment_reports WHERE comment_id = ?', [$commentId]);
            $db->exec('UPDATE news_comments SET reports = 0 WHERE id = ?', [$commentId]);
            return $cleared;
        });
    }

    /**
     * Deletes everything an account wrote or reported here, for the account's deletion: its comments and its
     * reports, then recounts the visible comments of every article it commented on (in any status, as one may have
     * been approved since it was read) and the reports of the comments it had reported. Runs inside the caller's
     * transaction when there is one. Returns how many of each went.
     *
     * @return array{comments: int, reports: int}
     */
    public function deleteForAccount(int $userId): array
    {
        return $this->db()->transaction(static function (Database $db) use ($userId): array {
            $articles = array_map('intval', array_column($db->all('SELECT DISTINCT article_id FROM news_comments WHERE user_id = ?', [$userId]), 'article_id'));
            $reported = array_map('intval', array_column($db->all('SELECT comment_id FROM news_comment_reports WHERE user_id = ?', [$userId]), 'comment_id'));
            $reportedOn = $reported !== []
                ? array_map('intval', array_column($db->all('SELECT DISTINCT article_id FROM news_comments WHERE id IN (' . self::marks($reported) . ')', $reported), 'article_id'))
                : [];
            self::lockArticles($db, array_merge($articles, $reportedOn));
            $reports = $db->exec('DELETE FROM news_comment_reports WHERE user_id = ?', [$userId]);
            $comments = $db->exec('DELETE FROM news_comments WHERE user_id = ?', [$userId]);
            if ($reported !== []) {
                $db->exec(
                    'UPDATE news_comments SET reports = (SELECT COUNT(*) FROM news_comment_reports r WHERE r.comment_id = news_comments.id)'
                    . ' WHERE id IN (' . self::marks($reported) . ')',
                    $reported
                );
            }
            self::recountArticles($db, $articles);
            return ['comments' => $comments, 'reports' => $reports];
        });
    }

    /**
     * Takes each article's row for the rest of the transaction, in ascending id order, before any comment or report
     * of it is written: the one order every writer here uses (a write that changes nothing still holds the row lock
     * on InnoDB, and starts SQLite's write lock). The caller is inside a transaction.
     *
     * @param list<int> $articleIds
     */
    private static function lockArticles(Database $db, array $articleIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        foreach ($ids as $id) {
            $db->exec('UPDATE news_articles SET comment_count = comment_count WHERE id = ?', [$id]);
        }
    }

    /**
     * Sets each article's comment_count to its visible comments.
     *
     * @param list<int> $articleIds
     */
    public static function recountArticles(Database $db, array $articleIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $articleIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $db->exec(
            "UPDATE news_articles SET comment_count = (SELECT COUNT(*) FROM news_comments c WHERE c.article_id = news_articles.id AND c.status = 'visible')"
            . ' WHERE id IN (' . self::marks($ids) . ')',
            $ids
        );
    }

    // ---------------------------------------------------------------------------------------------- reading

    /**
     * A comment with its author, or null. Rows carry the comment's columns, name (the public name), staff, and the
     * author's author_name and author_email for administrators' pages; public pages print name and staff only.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $commentId): ?array
    {
        $row = $commentId > 0 ? $this->db()->one('SELECT ' . self::COLUMNS . ' FROM news_comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [$commentId]) : null;
        return $row !== null ? self::shape($row) : null;
    }

    /**
     * The article's visible comments, the oldest first (the newest PAGE_MAX of them): what its page shows everyone.
     *
     * @return list<array<string, mixed>>
     */
    public function visible(int $articleId): array
    {
        $rows = $this->db()->all(
            'SELECT ' . self::COLUMNS . " FROM news_comments c JOIN users u ON u.id = c.user_id WHERE c.article_id = ? AND c.status = 'visible'"
            . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . self::PAGE_MAX,
            [$articleId]
        );
        return array_map(self::shape(...), array_reverse($rows));
    }

    /**
     * The person's comments a page drawn at $since may lack: every pending one, every one a moderator hid (only its
     * author sees it, to delete it), and the visible ones posted or approved since. The oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(int $articleId, int $userId, int $since): array
    {
        $rows = $this->db()->all(
            'SELECT ' . self::COLUMNS . ' FROM news_comments c JOIN users u ON u.id = c.user_id WHERE c.article_id = ? AND c.user_id = ?'
            . " AND (c.status IN ('pending', 'hidden') OR (c.status = 'visible' AND (c.created_at >= ? OR c.updated_at >= ?))) ORDER BY c.created_at, c.id",
            [$articleId, $userId, $since, $since]
        );
        return array_map(self::shape(...), $rows);
    }

    /**
     * The person's comments on the article, the oldest first: every one, or with $unlisted only the ones everyone
     * else's list lacks (pending, and hidden by a moderator). Only their author is shown these.
     *
     * @return list<array<string, mixed>>
     */
    public function authored(int $articleId, int $userId, bool $unlisted = false): array
    {
        $rows = $this->db()->all(
            'SELECT ' . self::COLUMNS . ' FROM news_comments c JOIN users u ON u.id = c.user_id WHERE c.article_id = ? AND c.user_id = ?'
            . ($unlisted ? " AND c.status IN ('pending', 'hidden')" : '') . ' ORDER BY c.created_at, c.id',
            [$articleId, $userId]
        );
        return array_map(self::shape(...), $rows);
    }

    /**
     * The person's newest comments on any article, in every status, for their account page: each with its article's
     * slug, title, primary category and whether it is live (article_live), so the page links to each comment page and
     * names an article only while it is live (a draft's headline is no one's to read).
     *
     * @return list<array<string, mixed>>
     */
    public function ofAccount(int $userId, int $limit): array
    {
        $rows = $this->db()->all(
            'SELECT c.id, c.article_id, c.body, c.status, c.created_at, a.slug, a.title, a.status AS article_status, a.published_at,'
            . ' ' . self::PRIMARY . ' AS category'
            . ' FROM news_comments c JOIN news_articles a ON a.id = c.article_id WHERE c.user_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, $limit),
            [$userId]
        );
        $store = new ArticleStore($this->app);
        return array_map(static fn (array $r): array => $r + ['article_live' => $store->isLive(['status' => $r['article_status'], 'published_at' => $r['published_at']])], $rows);
    }

    /** @return list<int> the ids of the person's comments on the article, in every status (each is theirs to delete) */
    public function own(int $articleId, int $userId): array
    {
        return array_map('intval', array_column($this->db()->all('SELECT id FROM news_comments WHERE article_id = ? AND user_id = ? ORDER BY id', [$articleId, $userId]), 'id'));
    }

    /**
     * Which of the comment ids a page shows are no longer visible on the article (hidden or deleted since).
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function removedOf(int $articleId, array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))), 0, self::PAGE_MAX);
        if ($ids === []) {
            return [];
        }
        $still = array_map('intval', array_column($this->db()->all(
            "SELECT id FROM news_comments WHERE article_id = ? AND status = 'visible' AND id IN (" . self::marks($ids) . ')',
            array_merge([$articleId], $ids)
        ), 'id'));
        return array_values(array_diff($ids, $still));
    }

    /**
     * A comment as the API sends it to the person viewing: never the author's full name or email.
     *
     * @param array<string, mixed> $comment a row from find(), visible(), mine() or authored()
     * @return array{id: int, name: string, staff: bool, body: string, status: string, pending: bool, own: bool, time: string, time_label: string}
     */
    public static function toPublic(array $comment, int $viewerId): array
    {
        $at = (int) $comment['created_at'];
        return [
            'id' => (int) $comment['id'],
            'name' => (string) $comment['name'],
            'staff' => (bool) $comment['staff'],
            'body' => (string) $comment['body'],
            'status' => (string) $comment['status'],
            'pending' => $comment['status'] === 'pending',
            'own' => $viewerId > 0 && (int) $comment['user_id'] === $viewerId,
            'time' => date(DATE_ATOM, $at),
            'time_label' => self::timeLabel($at),
        ];
    }

    /** "22 Oct 2026, 14:05", in the site's time zone: a cached page shows a time that stays true. */
    public static function timeLabel(int $ts): string
    {
        return date_short($ts) . ', ' . date('H:i', $ts);
    }

    /**
     * Everything an account wrote or reported here, for its data copy: every comment in every status, and every
     * report it made (the comment and article, never the other person's words).
     *
     * @return array{news_comments: list<array<string, mixed>>, news_comment_reports: list<array<string, mixed>>}
     */
    public function forExport(int $userId): array
    {
        $db = $this->db();
        $base = $this->app->baseUrl();
        $comments = array_map(static fn (array $c): array => [
            'id' => (int) $c['id'],
            'article' => (string) $c['title'],
            'url' => $base . Paths::article($c),
            'body' => (string) $c['body'],
            'status' => (string) $c['status'],
            'created_at' => $c['created_at'],
            'updated_at' => $c['updated_at'],
        ], $db->all('SELECT c.id, c.body, c.status, c.created_at, c.updated_at, a.title, a.slug, ' . self::PRIMARY . ' AS category FROM news_comments c JOIN news_articles a ON a.id = c.article_id WHERE c.user_id = ? ORDER BY c.id', [$userId]));
        $reports = array_map(static fn (array $r): array => [
            'comment_id' => (int) $r['comment_id'],
            'article' => (string) $r['title'],
            'url' => $base . Paths::article($r),
            'created_at' => $r['created_at'],
        ], $db->all(
            'SELECT r.comment_id, r.created_at, a.title, a.slug, ' . self::PRIMARY . ' AS category FROM news_comment_reports r JOIN news_comments c ON c.id = r.comment_id'
            . ' JOIN news_articles a ON a.id = c.article_id WHERE r.user_id = ? ORDER BY r.created_at, r.comment_id',
            [$userId]
        ));
        return ['news_comments' => $comments, 'news_comment_reports' => $reports];
    }

    /**
     * The article $articleId when it is live, else null.
     *
     * @return array<string, mixed>|null
     */
    public function liveArticle(int $articleId): ?array
    {
        $store = new ArticleStore($this->app);
        $article = $store->find($articleId);
        return $article !== null && $store->isLive($article) ? $article : null;
    }

    // ---------------------------------------------------------------------------------------------- internals

    private function db(): Database
    {
        return $this->app->db();
    }

    /** The comment's article (which never changes), read before a transaction locks it; null when it is gone. */
    private function articleOf(int $commentId): ?int
    {
        $id = $this->db()->value('SELECT article_id FROM news_comments WHERE id = ?', [$commentId]);
        return $id !== null ? (int) $id : null;
    }

    /**
     * @param array<string, string> $errors
     * @return array{ok: false, status: string, errors: array<string, string>, message: string}
     */
    private static function refused(string $status, string $message, array $errors = []): array
    {
        return ['ok' => false, 'status' => $status, 'errors' => $errors, 'message' => $message];
    }

    /**
     * The person's comment with exactly these words on the article within REPEAT_SECONDS, compared in PHP so both
     * database engines' collations agree.
     *
     * @return array<string, mixed>|null
     */
    private function repeatOf(int $articleId, int $userId, string $body, int $now): ?array
    {
        $rows = $this->db()->all('SELECT id, body FROM news_comments WHERE article_id = ? AND user_id = ? AND created_at >= ? ORDER BY id DESC', [$articleId, $userId, $now - self::REPEAT_SECONDS]);
        foreach ($rows as $row) {
            if ((string) $row['body'] === $body) {
                return $this->find((int) $row['id']);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['name'] = Names::publicName(['name' => $row['author_name'] ?? '', 'email' => $row['author_email'] ?? '']);
        $row['staff'] = ($row['author_role'] ?? '') === 'admin';
        return $row;
    }

    /**
     * The administrators' email on a comment's first report, and again when reports hide it.
     *
     * @param array<string, mixed> $comment
     */
    private function tellAdminsReported(array $comment, bool $hidden): void
    {
        $article = (new ArticleStore($this->app))->find((int) $comment['article_id']);
        if ($article === null) {
            return;
        }
        $this->app->auth()->notifyAdmins($hidden ? 'A reported comment was hidden' : 'A comment was reported', 'news-comment-admin', [
            'kind' => $hidden ? 'hidden' : 'reported',
            'article' => ['title' => (string) $article['title'], 'url' => $this->app->baseUrl() . Paths::article($article)],
            'authorName' => (string) $comment['author_name'],
            'authorEmail' => (string) $comment['author_email'],
            'body' => (string) $comment['body'],
            'url' => $this->app->baseUrl() . '/portal/admin/news/comments/?tab=reported#comment-' . (int) $comment['id'],
        ]);
    }

    /**
     * The administrators' email when a comment waits for approval: at most one a day (Settings::claimEmail), so
     * a busy article in approval mode cannot flood the inbox; the moderation page lists every waiting comment.
     *
     * @param array<string, mixed> $article
     * @param array<string, mixed> $user
     */
    private function tellAdminsPending(array $article, array $user, int $commentId, string $body): void
    {
        try {
            if (!(new Settings($this->app))->claimEmail('comment_pending')) {
                return;
            }
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news comment pending email');
            return;
        }
        $this->app->auth()->notifyAdmins('A comment is waiting for approval', 'news-comment-admin', [
            'kind' => 'pending',
            'article' => ['title' => (string) $article['title'], 'url' => $this->app->baseUrl() . Paths::article($article)],
            'authorName' => (string) ($user['name'] ?? ''),
            'authorEmail' => (string) ($user['email'] ?? ''),
            'body' => $body,
            'url' => $this->app->baseUrl() . '/portal/admin/news/comments/?tab=pending#comment-' . $commentId,
        ]);
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
