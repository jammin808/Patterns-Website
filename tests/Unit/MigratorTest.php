<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Db\Database;
use Psc\Db\Migrator;

final class MigratorTest extends TestCase
{
    public function testSplitDropsCommentsAndSplitsStatements(): void
    {
        $sql = "-- a comment\nCREATE TABLE a (x INT);\n\nCREATE TABLE b (\n  y INT\n);\n";
        self::assertSame(['CREATE TABLE a (x INT)', "CREATE TABLE b (\n  y INT\n)"], Migrator::split($sql));
    }

    public function testMigrationsApplyOnceAndTheMarkerSkipsTheQuery(): void
    {
        $dir = sys_get_temp_dir() . '/psc-mig-' . bin2hex(random_bytes(4));
        $db = Database::connect('sqlite::memory:');
        $m = new Migrator($db, dirname(__DIR__, 2) . '/database/migrations', $dir . '/schema-version');
        $first = $m->migrate();
        self::assertContains('0001_init', $first);
        self::assertSame([], $m->migrate());
        self::assertFileExists($dir . '/schema-version');
        $m->ensure();
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM schema_migrations WHERE version = '0001_init'"));
        foreach (['users', 'tickets', 'releases', 'licences', 'chat_conversations', 'contact_messages'] as $table) {
            self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM {$table}"));
        }
        @unlink($dir . '/schema-version');
        @unlink($dir . '/schema-version.lock');
        @rmdir($dir);
    }

    /**
     * The first requests of a new site ran on the default SQLite file and wrote the marker; the MySQL configuration
     * uploaded afterwards then trusted that marker and never got its tables. A marker speaks only for the database
     * it was written for.
     */
    public function testAMarkerWrittenForOneDatabaseNeverSkipsAnother(): void
    {
        $dir = sys_get_temp_dir() . '/psc-mig-' . bin2hex(random_bytes(4));
        $root = dirname(__DIR__, 2) . '/database/migrations';
        $first = Database::connect('sqlite::memory:');
        (new Migrator($first, $root, $dir . '/schema-version', 'sqlite:/data/site.sqlite|'))->ensure();
        self::assertTrue((new Migrator($first, $root, $dir . '/schema-version', 'sqlite:/data/site.sqlite|'))->isCurrent());

        $second = Database::connect('sqlite::memory:');
        $other = new Migrator($second, $root, $dir . '/schema-version', 'mysql:host=127.0.0.1;dbname=psc|psc');
        self::assertFalse($other->isCurrent(), 'a fresh database holds no migrations, whatever the marker says');
        $other->ensure();
        self::assertTrue($other->isCurrent());
        self::assertSame(0, (int) $second->value('SELECT COUNT(*) FROM rate_limits'));

        // The same database again: the marker now speaks for it and the check is skipped.
        $third = Database::connect('sqlite::memory:');
        (new Migrator($third, $root, $dir . '/schema-version', 'mysql:host=127.0.0.1;dbname=psc|psc'))->ensure();
        self::assertFalse((new Migrator($third, $root, $dir . '/schema-version', ''))->isCurrent(), 'ensure() trusted the marker');

        @unlink($dir . '/schema-version');
        @unlink($dir . '/schema-version.lock');
        @rmdir($dir);
    }

    /**
     * A release whose migration is numbered below one already applied (two pieces of work numbered in parallel, the
     * higher one deployed first) once kept the old marker, which named only the newest version: the lower one was
     * never applied and the health check, which asked about the newest alone, said the schema was current.
     */
    public function testAVersionNumberedBelowTheNewestIsStillApplied(): void
    {
        $dir = sys_get_temp_dir() . '/psc-mig-' . bin2hex(random_bytes(4));
        $root = $dir . '/migrations';
        mkdir($root . '/sqlite', 0770, true);
        file_put_contents($root . '/sqlite/0001_a.sql', 'CREATE TABLE a (x INT);');
        file_put_contents($root . '/sqlite/0003_c.sql', 'CREATE TABLE c (x INT);');
        $db = Database::connect('sqlite::memory:');
        $m = new Migrator($db, $root, $dir . '/schema-version');
        $m->ensure();
        self::assertTrue($m->isCurrent());

        file_put_contents($root . '/sqlite/0002_b.sql', 'CREATE TABLE b (x INT);');
        self::assertFalse($m->isCurrent(), 'the health check sees the version that is not applied yet');
        $m->ensure();
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM b'), 'the marker no longer matches, so it is applied');
        self::assertTrue($m->isCurrent());
        self::assertSame(['0001_a', '0002_b', '0003_c'], array_map('strval', array_column($db->all('SELECT version FROM schema_migrations ORDER BY version'), 'version')));

        foreach (['0001_a', '0002_b', '0003_c'] as $f) {
            @unlink($root . '/sqlite/' . $f . '.sql');
        }
        @rmdir($root . '/sqlite');
        @rmdir($root);
        @unlink($dir . '/schema-version');
        @unlink($dir . '/schema-version.lock');
        @rmdir($dir);
    }

    /**
     * A deploy that writes its files one by one can add a migration while a request is migrating. The marker names
     * what that pass went through, never a later scan of the folder, so the late file is applied by the next request.
     */
    public function testAFileThatLandsDuringAMigrateIsAppliedByTheNextRequest(): void
    {
        $dir = sys_get_temp_dir() . '/psc-mig-' . bin2hex(random_bytes(4));
        $root = $dir . '/migrations';
        mkdir($root . '/sqlite', 0770, true);
        file_put_contents($root . '/sqlite/0001_a.sql', 'CREATE TABLE a (x INT);');
        $late = $root . '/sqlite/0002_b.sql';
        // The PDO the migrator runs its statements on: the deploy lands 0002 while 0001 is being applied.
        $pdo = new class ('sqlite::memory:', $late) extends \PDO {
            public function __construct(string $dsn, private readonly string $late)
            {
                parent::__construct($dsn);
            }

            public function exec(string $statement): int|false
            {
                if (str_starts_with($statement, 'CREATE TABLE a') && !is_file($this->late)) {
                    file_put_contents($this->late, 'CREATE TABLE b (x INT);');
                }
                return parent::exec($statement);
            }
        };
        $db = new Database($pdo);
        $m = new Migrator($db, $root, $dir . '/schema-version');
        self::assertSame(['0001_a'], $m->migrate(), 'the pass applies what it saw');
        self::assertFalse($m->isCurrent());
        $m->ensure();
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM b'), 'the next request applies the late file');
        self::assertTrue($m->isCurrent());

        @unlink($root . '/sqlite/0001_a.sql');
        @unlink($late);
        @rmdir($root . '/sqlite');
        @rmdir($root);
        @unlink($dir . '/schema-version');
        @unlink($dir . '/schema-version.lock');
        @rmdir($dir);
    }

    public function testMysqlAndSqliteSchemasDeclareTheSameTables(): void
    {
        $root = dirname(__DIR__, 2) . '/database/migrations';
        $tables = static function (string $file): array {
            preg_match_all('/CREATE TABLE (\w+)/', (string) file_get_contents($file), $m);
            sort($m[1]);
            return $m[1];
        };
        foreach (glob($root . '/mysql/*.sql') ?: [] as $mysql) {
            $sqlite = $root . '/sqlite/' . basename($mysql);
            self::assertFileExists($sqlite);
            self::assertSame($tables($mysql), $tables($sqlite), basename($mysql));
        }
    }

    /**
     * 0005 keeps the time each article live when it arrives went live (first_live_at from published_at), so a
     * reschedule after the deploy cannot free its slug; a scheduled article and a draft keep none. It also brings
     * the table of retired slugs.
     */
    public function testFirstLiveIsKeptForEveryArticleLiveWhen0005Arrives(): void
    {
        $root = dirname(__DIR__, 2) . '/database/migrations';
        $dir = sys_get_temp_dir() . '/psc-mig-' . bin2hex(random_bytes(4));
        mkdir($dir . '/sqlite', 0777, true);
        foreach (glob($root . '/sqlite/000[1-4]_*.sql') ?: [] as $file) {
            copy($file, $dir . '/sqlite/' . basename($file));
        }
        $db = Database::connect('sqlite::memory:');
        (new Migrator($db, $dir, $dir . '/schema-version'))->migrate();
        $now = time();
        foreach (['live' => ['published', $now - 3600], 'scheduled' => ['published', $now + 3600], 'draft' => ['draft', null]] as $slug => [$status, $at]) {
            $db->insert('news_articles', ['slug' => $slug, 'title' => $slug, 'body' => '', 'status' => $status, 'published_at' => $at, 'created_at' => $now - 9000, 'updated_at' => $now - 9000]);
        }
        copy($root . '/sqlite/0005_news_first_live.sql', $dir . '/sqlite/0005_news_first_live.sql');
        self::assertSame(['0005_news_first_live'], (new Migrator($db, $dir, $dir . '/schema-version'))->migrate());

        $first = array_column($db->all('SELECT slug, first_live_at FROM news_articles'), 'first_live_at', 'slug');
        self::assertSame(['live' => $now - 3600, 'scheduled' => null, 'draft' => null], array_map(static fn (mixed $v): ?int => $v !== null ? (int) $v : null, $first));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM news_retired_slugs'));

        foreach ([...(glob($dir . '/sqlite/*.sql') ?: []), $dir . '/schema-version', $dir . '/schema-version.lock'] as $file) {
            @unlink($file);
        }
        @rmdir($dir . '/sqlite');
        @rmdir($dir);
    }
}
