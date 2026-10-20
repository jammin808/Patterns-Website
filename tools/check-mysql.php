<?php

declare(strict_types=1);

/*
 * Applies every MySQL migration to the database in $PSC_TEST_MYSQL_DSN (CI's MySQL service) and exercises the
 * statements the site uses that differ between MySQL and SQLite. Exits non-zero on any failure.
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
$uid = $db->insert('users', ['email' => 'ci@example.test', 'name' => 'CI', 'password_hash' => 'x', 'role' => 'client', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$tid = $db->insert('tickets', ['user_id' => $uid, 'type' => 'feature', 'product' => 'stagewatch', 'title' => 'T', 'body' => 'B', 'visibility' => 'public', 'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('ticket_votes', ['ticket_id' => $tid, 'user_id' => $uid, 'created_at' => $now]);
$db->exec('UPDATE tickets SET votes = (SELECT COUNT(*) FROM ticket_votes WHERE ticket_id = ?) WHERE id = ?', [$tid, $tid]);
$limiter = new Psc\Support\RateLimiter($db, str_repeat('a', 64));
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
$store->addUsage(10, 5);
$store->addUsage(1, 1);
$like = $db->value("SELECT COUNT(*) FROM tickets WHERE title LIKE ? ESCAPE '!'", [Psc\Db\Database::like('T')]);
$page = Psc\Support\Paginator::class;
$count = (int) $db->value('SELECT COUNT(*) FROM (SELECT id FROM tickets) AS counted');
if ((int) $like !== 1 || $count !== 1 || $store->usageToday() !== 17 || count($store->history($cid, 5)) !== 1) {
    fwrite(STDERR, "query checks failed\n");
    exit(1);
}
$db->exec('DELETE FROM users WHERE id = ?', [$uid]);
if ((int) $db->value('SELECT COUNT(*) FROM tickets') !== 0) {
    fwrite(STDERR, "cascade failed\n");
    exit(1);
}
echo "mysql ok\n";
@unlink($marker);
