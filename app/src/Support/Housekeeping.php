<?php

declare(strict_types=1);

namespace Psc\Support;

use Psc\App;
use Psc\Chat\ChatStore;

/**
 * The daily clean-up (tools/cron.php). Each limit is the one the privacy policy promises (content/legal/privacy.md):
 * expired rate-limit windows and spent tokens go at once; assistant conversations after chat.retention_days;
 * contact messages after two years; download records and the audit log after about thirteen months; accounts never
 * confirmed after thirty days; log and mail files after ninety days. Session files go once they are past the
 * longest a session can live (remember-me is a database token, not a session file). Safe to run at any time and any
 * number of times.
 */
final class Housekeeping
{
    public const CONTACT_MESSAGE_DAYS = 730;
    public const DOWNLOAD_EVENT_DAYS = 400;
    public const AUDIT_DAYS = 400;
    public const UNCONFIRMED_ACCOUNT_DAYS = 30;
    public const FILE_DAYS = 90;

    /** @return array<string, int> rows or files removed, by kind */
    public static function run(App $app, ?int $now = null): array
    {
        $db = $app->db();
        $now ??= time();
        $day = 86400;
        $report = [];
        $report['rate_limits'] = $app->limiter()->prune();
        $report['tokens'] = $db->exec('DELETE FROM user_tokens WHERE expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)', [$now, $now - $day]);
        $report['chats'] = (new ChatStore($db))->prune($app->config->int('chat.retention_days', 30));
        $report['contact_messages'] = $db->exec('DELETE FROM contact_messages WHERE created_at < ?', [$now - self::CONTACT_MESSAGE_DAYS * $day]);
        $report['download_events'] = $db->exec('DELETE FROM download_events WHERE created_at < ?', [$now - self::DOWNLOAD_EVENT_DAYS * $day]);
        $report['audit'] = $db->exec('DELETE FROM audit_log WHERE created_at < ?', [$now - self::AUDIT_DAYS * $day]);
        $report['unverified_accounts'] = $db->exec("DELETE FROM users WHERE status = 'pending' AND email_verified_at IS NULL AND created_at < ?", [$now - self::UNCONFIRMED_ACCOUNT_DAYS * $day]);

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
}
