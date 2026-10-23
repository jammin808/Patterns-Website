<?php

declare(strict_types=1);

/*
 * Applies every MySQL migration to the database in $PSC_TEST_MYSQL_DSN (CI's MySQL service) and exercises the
 * statements the site uses that differ between MySQL and SQLite. Exits non-zero on any failure. It counts and removes
 * only the rows it writes, so it can run again on a database that is kept (or that an earlier failed run left behind).
 *   PSC_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=psc;charset=utf8mb4' PSC_TEST_MYSQL_USER=root php tools/check-mysql.php
 */

define('PSC_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $c): void {
    if (str_starts_with($c, 'Psc\\')) {
        require PSC_ROOT . '/app/src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
    }
});

$dsn = getenv('PSC_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "PSC_TEST_MYSQL_DSN is not set\n");
    exit(2);
}
$db = Psc\Db\Database::connect($dsn, (string) getenv('PSC_TEST_MYSQL_USER'), (string) getenv('PSC_TEST_MYSQL_PASSWORD'));
$marker = sys_get_temp_dir() . '/psc-mysql-check-' . getmypid();
$applied = (new Psc\Db\Migrator($db, PSC_ROOT . '/database/migrations', $marker))->migrate();
echo 'applied: ' . implode(', ', $applied) . PHP_EOL;

$now = time();
$db->exec('DELETE FROM users WHERE email = ?', ['ci@example.test']);
$uid = $db->insert('users', ['email' => 'ci@example.test', 'name' => 'CI', 'password_hash' => 'x', 'role' => 'client', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$tid = $db->insert('tickets', ['user_id' => $uid, 'type' => 'feature', 'product' => 'stagewatch', 'title' => 'T', 'body' => 'B', 'visibility' => 'public', 'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('ticket_votes', ['ticket_id' => $tid, 'user_id' => $uid, 'created_at' => $now]);
$db->exec('UPDATE tickets SET votes = (SELECT COUNT(*) FROM ticket_votes WHERE ticket_id = ?) WHERE id = ?', [$tid, $tid]);
$limiter = new Psc\Support\RateLimiter($db, str_repeat('a', 64));
$limiter->clear('ci:x');
$limiter->hits('ci:x', 60);
$limiter->hits('ci:x', 60);
if ($limiter->peek('ci:x') !== 2) {
    fwrite(STDERR, "rate limiter mismatch\n");
    exit(1);
}
$store = new Psc\Chat\ChatStore($db);
$cid = Psc\Chat\ChatStore::newId();
$store->create($cid, $uid, 'h', '/');
$store->add($cid, 'user', 'hello');
$usageBefore = $store->usageToday();
$store->addUsage(10, 5);
// Cached prompt tokens count at their weight: 8 written x 1.25 + 100 read x 0.1 = 20 on top of 1 + 1.
$store->addUsage(1, 1, 8, 100);
$like = $db->value("SELECT COUNT(*) FROM tickets WHERE user_id = ? AND title LIKE ? ESCAPE '!'", [$uid, Psc\Db\Database::like('T')]);
$page = Psc\Support\Paginator::class;
$count = (int) $db->value('SELECT COUNT(*) FROM (SELECT id FROM tickets WHERE user_id = ?) AS counted', [$uid]);
if ((int) $like !== 1 || $count !== 1 || $store->usageToday() - $usageBefore !== 37 || count($store->history($cid, 5)) !== 1) {
    fwrite(STDERR, "query checks failed\n");
    exit(1);
}
$db->exec('DELETE FROM users WHERE id = ?', [$uid]);
if ((int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$uid]) !== 0) {
    fwrite(STDERR, "cascade failed\n");
    exit(1);
}
// An UPDATE that reads a column it also sets (ArticleStore::publish()): first_live_at is assigned before published_at,
// because MySQL reads a column assigned earlier in the same SET as its new value and SQLite never does.
$db->exec('DELETE FROM news_articles WHERE slug = ?', ['ci-check-article']);
$aid = $db->insert('news_articles', ['slug' => 'ci-check-article', 'title' => 'CI', 'body' => 'B', 'status' => 'published', 'published_at' => $now - 60, 'created_at' => $now, 'updated_at' => $now + 5]);
$db->exec(
    "UPDATE news_articles SET first_live_at = COALESCE(first_live_at, CASE WHEN published_at <= ? THEN published_at ELSE NULL END),"
    . " status = 'published', published_at = ?, updated_at = CASE WHEN updated_at >= ? THEN updated_at + 1 ELSE ? END WHERE id = ?",
    [$now, $now + 3600, $now, $now, $aid]
);
$article = $db->one('SELECT first_live_at, published_at, updated_at FROM news_articles WHERE id = ?', [$aid]);
$db->exec('DELETE FROM news_articles WHERE id = ?', [$aid]);
if ($article === null || (int) $article['first_live_at'] !== $now - 60 || (int) $article['published_at'] !== $now + 3600 || (int) $article['updated_at'] !== $now + 6) {
    fwrite(STDERR, "article publish update failed\n");
    exit(1);
}
$db->exec('DELETE FROM chat_conversations WHERE id = ?', [$cid]);
$limiter->clear('ci:x');

// The weekly digest's statements that differ between the engines (docs/NEWS.md §14), through its own classes: an
// unsubscribed row asked again by an unproven registration moves its generation on (assigned before state, which
// MySQL would otherwise read as already pending) and keeps its record in the prior_ columns (copied before the
// columns it copies are overwritten), a claim given back and claimed again, the retry CASE at the third attempt,
// countDue()'s NOT EXISTS, countWaiting()'s EXISTS inside it, page()'s CASE in its select list and prune()'s subquery.
$storage = sys_get_temp_dir() . '/psc-mysql-check-storage-' . getmypid();
$app = Psc\App::boot(Psc\Config::fromArray(PSC_ROOT, [
    'app' => ['url' => 'https://patternsshowcontrol.test', 'env' => 'testing', 'debug' => false, 'secret' => str_repeat('cd', 32), 'force_https' => false],
    'storage' => ['path' => $storage],
    'mail' => ['driver' => 'log', 'unsubscribe_to' => 'unsubscribe@example.test'],
    'db' => ['auto_migrate' => false],
    'chat' => ['api_key' => ''],
]));
$app->useDatabase($db);
$tokens = Psc\News\Digest\Tokens::fromApp($app);
$addresses = ['ci-digest-back@example.test', 'ci-digest-send@example.test'];
$clean = static function () use ($db, $tokens, $addresses): void {
    $db->exec("DELETE FROM news_digests WHERE week = '1999-w01'");
    foreach ($addresses as $address) {
        $db->exec('DELETE FROM news_digest_subscribers WHERE email_hash = ?', [$tokens->emailHash($address)]);
        $db->exec('DELETE FROM users WHERE email = ?', [$address]);
    }
};
$clean();
$subscribers = new Psc\News\Digest\Subscribers($app);
$back = $subscribers->request($addresses[0], 'c', 'p', $now - 60)['subscriber'];
$subscribers->confirm((string) $back['selector'], 0, $now - 60);
$subscribers->unsubscribe((string) $back['selector'], 'page', $now - 30);
$pending = $db->insert('users', ['email' => $addresses[0], 'name' => 'CI', 'password_hash' => 'x', 'role' => 'client', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
$answer = $subscribers->subscribeAccount((array) $db->one('SELECT * FROM users WHERE id = ?', [$pending]), 'registration', 'c', 'p', $now);
$row = $subscribers->find((int) $back['id']);
if ($answer !== 'pending' || $row === null || [$row['state'], $row['source'], $row['generation'], $row['user_id'], $row['prior_source'], $row['prior_requested_at']] !== ['pending', 'registration', 1, $pending, 'page', $now - 60]) {
    $clean();
    fwrite(STDERR, "digest generation update failed\n");
    exit(1);
}
$old = $now - 400 * 86400;
$reader = $subscribers->request($addresses[1], 'c', 'p', $old - 60)['subscriber'];
$subscribers->confirm((string) $reader['selector'], 0, $old - 60);
$digestId = $db->insert('news_digests', ['week' => '1999-w01', 'send_at' => $old, 'window_start' => $old - 604800, 'window_end' => $old, 'articles' => '[]', 'left_out' => 0, 'state' => 'sending', 'recipients' => 1, 'composed_at' => $old]);
$digest = (array) (new Psc\News\Digest\Digests($app))->find($digestId);
$sends = new Psc\News\Digest\Sends($app);
$states = [$sends->countDue($digest)];
$sendId = (int) $sends->claim($digestId, (int) $reader['id'], $now);
$states[] = $sends->release($sendId, 'connect') ? (int) $db->value('SELECT attempts FROM news_digest_sends WHERE id = ?', [$sendId]) : -1;
for ($i = 0; $i < 3; $i++) {
    $states[] = $sends->claim($digestId, (int) $reader['id'], $now) === $sendId ? $sends->retry($sendId, 'rcpt 451 4.7.1') : 'unclaimed';
    $states[] = $sends->countWaiting($digest);
}
$states[] = $sends->countDue($digest);
$states[] = $sends->page($digest, 1, 10)['items'][0]['due'] ?? null;
$pruned = (new Psc\News\Digest\Digests($app))->prune($now - 365 * 86400);
$left = (int) $db->value('SELECT COUNT(*) FROM news_digest_sends WHERE id = ?', [$sendId]);
$clean();
@array_map('unlink', glob($storage . '/*/*') ?: []);
@array_map('rmdir', glob($storage . '/*') ?: []);
@rmdir($storage);
if ($states !== [1, 0, 'retry', 1, 'retry', 1, 'failed', 0, 0, true] || $pruned['sends'] < 1 || $pruned['digests'] < 1 || $left !== 0) {
    fwrite(STDERR, 'digest sends failed: ' . json_encode([$states, $pruned, $left]) . "\n");
    exit(1);
}
echo "mysql ok\n";
@unlink($marker);
