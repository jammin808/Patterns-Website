<?php

declare(strict_types=1);

/*
 * Housekeeping, run daily by a Hostinger cron job:
 *   /usr/bin/php /home/<user>/domains/<domain>/public_html/tools/cron.php
 * Removes what the privacy policy says is kept no longer (Psc\Support\Housekeeping): expired rate-limit windows,
 * spent and expired one-time tokens, assistant conversations past their retention, contact messages after two
 * years, download records and the audit log after about thirteen months, accounts never confirmed, ended session
 * files and old log files, and for Industry Updates: link click counts after about thirteen months, the desk's run
 * records after six months, and images left without an article. Safe to run at any time and any number of times.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$report = Psc\Support\Housekeeping::run($app);
$app->log->info('cron', $report);
echo json_encode($report) . PHP_EOL;
