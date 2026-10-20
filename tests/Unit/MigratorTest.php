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
}
