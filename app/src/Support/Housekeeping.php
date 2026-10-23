<?php

declare(strict_types=1);

namespace Psc\Support;

use Psc\App;
use Psc\Chat\ChatStore;
use Psc\News\ArticleStore;
use Psc\News\Digest\Digests;
use Psc\News\Digest\Subscribers;

/**
 * The daily clean-up (tools/cron.php). Each limit is the one the privacy policy promises (content/legal/privacy.md):
 * expired rate-limit windows and spent tokens go at once; assistant conversations after chat.retention_days;
 * contact messages after two years; download records, Industry Updates link clicks and the audit log after about
 * thirteen months; the AI desk's run records after six months; accounts never confirmed after thirty days; log and
 * mail files after ninety days; article images never attached to an article after a day, with their files. Session
 * files go once they are past the longest a session can live (remember-me is a database token, not a session file).
 * The weekly digest (docs/NEWS.md §14.2): a sign-up never confirmed goes after seven days (one that was asking to
 * come back returns to its suppression record instead); the suppression records of those who unsubscribed stay for as
 * long as the digest runs; each digest and its record of sends go after a year. Safe to run at any time and any
 * number of times.
 *
 * An image file goes only with the row it belongs to, never because the connected database does not name it: a
 * database that is the wrong one (the empty SQLite fallback while db.dsn is missing, a restored copy) names none of
 * them, and the files are the only copies.
 *
 * The report's keys go into the log as they are, so none may look like a secret to the Logger (no "token", "hash",
 * "key" ...), or its count would be masked.
 */
final class Housekeeping
{
    public const CONTACT_MESSAGE_DAYS = 730;
    public const DOWNLOAD_EVENT_DAYS = 400;
    public const AUDIT_DAYS = 400;
    public const UNCONFIRMED_ACCOUNT_DAYS = 30;
    public const FILE_DAYS = 90;
    public const NEWS_CLICK_DAYS = 400;
    public const NEWS_RUN_DAYS = 180;
    public const ORPHAN_IMAGE_DAYS = 1;
    /** A digest sign-up's confirmation link works this long (Tokens::CONFIRM_SECONDS); Subscribers::expire() keeps to it. */
    public const DIGEST_PENDING_DAYS = 7;
    public const DIGEST_KEEP_DAYS = 365;

    /** @return array<string, int> rows or files removed, by kind */
    public static function run(App $app, ?int $now = null): array
    {
        $db = $app->db();
        $now ??= time();
        $day = 86400;
        $report = [];
        $report['rate_limits'] = $app->limiter()->prune();
        $report['expired_credentials'] = $db->exec('DELETE FROM user_tokens WHERE expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)', [$now, $now - $day]);
        $report['chats'] = (new ChatStore($db))->prune($app->config->int('chat.retention_days', 30));
        $report['contact_messages'] = $db->exec('DELETE FROM contact_messages WHERE created_at < ?', [$now - self::CONTACT_MESSAGE_DAYS * $day]);
        $report['download_events'] = $db->exec('DELETE FROM download_events WHERE created_at < ?', [$now - self::DOWNLOAD_EVENT_DAYS * $day]);
        $report['audit'] = $db->exec('DELETE FROM audit_log WHERE created_at < ?', [$now - self::AUDIT_DAYS * $day]);
        $report['unverified_accounts'] = $db->exec("DELETE FROM users WHERE status = 'pending' AND email_verified_at IS NULL AND created_at < ?", [$now - self::UNCONFIRMED_ACCOUNT_DAYS * $day]);
        // A click row is one link's count for one day (day is Y-m-d), so the days compare as strings in both engines.
        $report['news_link_clicks'] = $db->exec('DELETE FROM news_link_clicks WHERE day < ?', [gmdate('Y-m-d', $now - self::NEWS_CLICK_DAYS * $day)]);
        $report['news_runs'] = $db->exec('DELETE FROM news_runs WHERE created_at < ?', [$now - self::NEWS_RUN_DAYS * $day]);
        [$report['news_images'], $report['news_image_files']] = self::orphanImages($app, $now - self::ORPHAN_IMAGE_DAYS * $day);
        $expired = (new Subscribers($app))->expire($now);
        $report['digest_unconfirmed'] = $expired['removed'];
        $report['digest_lapsed'] = $expired['lapsed'];
        $pruned = (new Digests($app))->prune($now - self::DIGEST_KEEP_DAYS * $day);
        $report['digest_sends'] = $pruned['sends'];
        $report['digests'] = $pruned['digests'];

        // A session is over by its absolute or idle limit, whichever is longer; an hour's margin covers a request
        // still running at the edge.
        $sessionAge = max($app->config->int('security.session_absolute_seconds', 43200), $app->config->int('security.session_idle_seconds', 7200)) + 3600;
        $report['sessions'] = self::removeOlder(glob($app->storage->path('sessions') . '/sess_*') ?: [], $now - $sessionAge);
        $report['old_files'] = self::removeOlder(array_merge(glob($app->storage->path('logs') . '/*.log') ?: [], glob($app->storage->path('mail') . '/*.eml') ?: []), $now - self::FILE_DAYS * $day);
        return $report;
    }

    /** @param list<string> $files */
    private static function removeOlder(array $files, int $cutoff): int
    {
        $removed = 0;
        foreach ($files as $f) {
            if ((int) @filemtime($f) < $cutoff && @unlink($f)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Article images uploaded before $cutoff that no article holds (an editor left before saving), with their files
     * and no others. A row an article took in the meantime is left alone. Returns [rows, files] removed.
     *
     * @return array{0: int, 1: int}
     */
    private static function orphanImages(App $app, int $cutoff): array
    {
        $db = $app->db();
        $rows = 0;
        $files = 0;
        foreach ($db->all('SELECT * FROM news_images WHERE article_id IS NULL AND created_at < ?', [$cutoff]) as $image) {
            if ($db->exec('DELETE FROM news_images WHERE id = ? AND article_id IS NULL', [(int) $image['id']]) !== 1) {
                continue;
            }
            $rows++;
            $files += self::removeImage($app, $image);
        }
        return [$rows, $files];
    }

    /**
     * Removes one image's files: through the Images class when it is installed (failures are logged, the files are
     * still removed below), then by the naming scheme. Returns how many files went.
     *
     * @param array<string, mixed> $image a news_images row
     */
    private static function removeImage(App $app, array $image): int
    {
        $images = 'Psc\\News\\Images';
        $before = [];
        foreach (ArticleStore::imageFiles($image) as $name) {
            $path = $app->storage->path(ArticleStore::IMAGE_DIR . '/' . $name);
            if (is_file($path)) {
                $before[] = $path;
            }
        }
        try {
            if (class_exists($images) && method_exists($images, 'delete')) {
                (new $images($app))->delete($image);
            }
        } catch (\Throwable $e) {
            $app->log->exception($e, 'housekeeping news image');
        }
        $removed = 0;
        foreach ($before as $path) {
            if (!is_file($path) || @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }
}
