<?php

declare(strict_types=1);

/*
 * The Industry Updates desk (docs/NEWS.md §6), run by a Hostinger cron job every 15 minutes:
 *   /usr/bin/php /home/<user>/domains/<domain>/public_html/tools/news.php tick
 *
 *   tick    write the heartbeat, reap runs whose process died (and tell the admins), import
 *           content/news/articles/*.json, run the queued or due session (which keeps the heartbeat fresh), refresh
 *           the latest-articles file, tell IndexNow and the WebSub hub what changed (production only), write the
 *           heartbeat again (each step on its own, so one failing never stops the others), and then, in a step of its own, the weekly digest (docs/NEWS.md §14.4): the week due composed, and one batch
 *           of the digest still sending, within the mailbox's caps (Psc\News\Digest\Sender)
 *   run     run a session now (refused while one is queued or running)
 *   import  only import the article files (and refresh the latest-articles file the other pages read)
 *   digest  only the weekly digest's step, as the tick runs it (for the owner and the tests)
 *
 * One process at a time: the lock is held for the whole command, and a second one exits at once. Prints one JSON
 * line; its usage keys are usage_in and usage_out, never "tokens", so the log does not mask them. The digest's step
 * runs after everything else the tick does, in a try/catch of its own, so it never delays a session or the
 * announcements and never stops the line; its report is the line's "digest" key and its own log line, "news digest".
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$command = $argv[1] ?? 'tick';
if (!in_array($command, ['tick', 'run', 'import', 'digest'], true)) {
    fwrite(STDERR, "Usage: php tools/news.php [tick|run|import|digest]\n");
    exit(2);
}

$lock = fopen($app->storage->dir('cache') . '/news.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['command' => $command, 'status' => 'busy', 'summary' => 'Another news command is running.']) . PHP_EOL;
    exit(0);
}

try {
    $now = new DateTimeImmutable('now');
    $pipeline = new Psc\News\Pipeline($app);
    if ($command === 'digest') {
        $report = [];
    } elseif ($command === 'import') {
        $result = (new Psc\News\Importer($app))->run();
        $report = [
            'imported' => count($result['imported']),
            'skipped' => count($result['skipped']),
            'import_failed' => count($result['failed']),
            'failed_files' => array_column($result['failed'], 'file'),
        ];
    } else {
        $model = Psc\News\AnthropicNewsModel::fromConfig($app);
        $report = $command === 'tick' ? $pipeline->tick($now, $model) : $pipeline->runNow($model, $now);
    }
    // The other pages' strips of the newest articles show what an import or a session by hand just published (the
    // tick refreshes them itself).
    if ($command === 'run' || $command === 'import') {
        try {
            $report['latest'] = Psc\News\Latest::refresh($app);
        } catch (Throwable $e) {
            $app->log->exception($e, 'news latest');
            $report['latest'] = false;
        }
    }
    // The weekly digest, after the session and the announcements, under the same lock. Its own clock reading: a
    // session may have run for many minutes since the tick began, and the caps count real times.
    if ($command === 'tick' || $command === 'digest') {
        try {
            $report['digest'] = (new Psc\News\Digest\Sender($app))->tick(new DateTimeImmutable('now'));
        } catch (Throwable $e) {
            $app->log->exception($e, 'news digest');
            $report['digest'] = ['status' => 'failed'];
        }
    }
    if ($command === 'run' || $command === 'import') {
        $app->log->info('news ' . $command, $report);
    }
    echo json_encode(['command' => $command] + $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $code = ($report['status'] ?? '') === 'failed' ? 1 : 0;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($code);
