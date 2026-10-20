<?php

declare(strict_types=1);

/*
 * Housekeeping, run daily by a Hostinger cron job:
 *   /usr/bin/php /home/<user>/domains/<domain>/public_html/tools/cron.php
 * Prunes expired rate-limit windows, spent and expired one-time tokens, assistant conversations past their
 * retention, old session files and old log files. Safe to run at any time and any number of times.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$db = $app->db();
$now = time();
$report = [];

$report['rate_limits'] = $app->limiter()->prune();
$report['tokens'] = $db->exec('DELETE FROM user_tokens WHERE expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)', [$now, $now - 86400]);
$report['chats'] = (new Psc\Chat\ChatStore($db))->prune($app->config->int('chat.retention_days', 30));
$report['audit'] = $db->exec('DELETE FROM audit_log WHERE created_at < ?', [$now - 400 * 86400]);
$report['unverified_accounts'] = $db->exec("DELETE FROM users WHERE status = 'pending' AND email_verified_at IS NULL AND created_at < ?", [$now - 30 * 86400]);

$removed = 0;
$maxAge = max($app->config->int('security.session_absolute_seconds', 43200), 86400 * $app->config->int('security.remember_days', 30));
foreach (glob($app->storage->path('sessions') . '/sess_*') ?: [] as $f) {
    if (filemtime($f) < $now - $maxAge) {
        $removed += @unlink($f) ? 1 : 0;
    }
}
$report['sessions'] = $removed;

$removed = 0;
foreach (array_merge(glob($app->storage->path('logs') . '/*.log') ?: [], glob($app->storage->path('mail') . '/*.eml') ?: []) as $f) {
    if (filemtime($f) < $now - 90 * 86400) {
        $removed += @unlink($f) ? 1 : 0;
    }
}
$report['old_files'] = $removed;

$app->log->info('cron', $report);
echo json_encode($report) . PHP_EOL;
