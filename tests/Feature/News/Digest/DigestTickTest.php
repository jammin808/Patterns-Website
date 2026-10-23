<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News\Digest;

use Psc\Db\Database;
use Psc\Tests\TestCase;

/**
 * The digest's hook in tools/news.php (docs/NEWS.md §14.4): the tick's one JSON line carries the digest's report; a
 * digest step that fails leaves the session's report and the exit code as they were; and the digest command runs
 * that step alone, under the same lock.
 */
final class DigestTickTest extends TestCase
{
    private const REPORT_KEYS = ['status', 'week', 'sent', 'bounced', 'retry', 'failed', 'left', 'note'];

    private string $dir;
    private string $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->storage . '/cli';
        mkdir($this->dir, 0777, true);
        $this->config = $this->dir . '/config.php';
        file_put_contents($this->config, '<?php return ' . var_export([
            'app' => ['url' => 'https://patternsshowcontrol.test', 'env' => 'testing', 'secret' => str_repeat('cd', 32), 'force_https' => false],
            'storage' => ['path' => $this->dir . '/storage'],
            'db' => ['dsn' => 'sqlite:' . $this->dir . '/site.sqlite'],
            'mail' => ['driver' => 'log', 'unsubscribe_to' => 'unsubscribe@example.test'],
            'chat' => ['api_key' => ''],
            'news' => ['api_key' => '', 'import_stagger' => false],
        ], true) . ';');
    }

    /** @return array{0: int, 1: string} the exit code and what it printed */
    private function cli(string $command): array
    {
        $env = getenv();
        $env['PSC_CONFIG'] = $this->config;
        $process = proc_open([PHP_BINARY, PSC_ROOT . '/tools/news.php', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PSC_ROOT, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out . $err];
    }

    /** @return array<string, mixed> the one JSON line a command printed */
    private static function line(string $out): array
    {
        $lines = array_values(array_filter(explode("\n", trim($out))));
        self::assertCount(1, $lines, 'one line: ' . $out);
        $report = json_decode($lines[0], true);
        self::assertIsArray($report, $out);
        return $report;
    }

    public function testTheTickPrintsOneLineWithTheDigestsReport(): void
    {
        [$code, $out] = $this->cli('tick');
        self::assertSame(0, $code, $out);
        $report = self::line($out);
        self::assertSame('tick', $report['command']);
        self::assertArrayHasKey('usage_in', $report, 'the session\'s report is all there');
        self::assertIsArray($report['digest'] ?? null);
        self::assertSame(self::REPORT_KEYS, array_keys($report['digest']));
        self::assertNotSame('failed', $report['digest']['status']);
        self::assertNotSame('blocked', $report['digest']['status'], 'the log driver and an unsubscribe address are enough');

        $log = (string) file_get_contents($this->dir . '/storage/logs/site-' . gmdate('Y-m-d') . '.log');
        self::assertStringContainsString('"msg":"news digest"', $log);
        self::assertStringNotContainsString('***', $log);
    }

    public function testADigestStepThatFailsLeavesTheSessionsReportAndTheExitCode(): void
    {
        // The first tick may claim a due session slot (skipped: no key); the second is the one to compare with.
        $this->cli('tick');
        [$code, $out] = $this->cli('tick');
        self::assertSame(0, $code, $out);
        $before = self::line($out);
        self::assertNotSame('failed', $before['digest']['status']);

        // The digest's tables gone (a database the migration never reached): its step fails on its first query.
        $db = Database::connect('sqlite:' . $this->dir . '/site.sqlite');
        $db->pdo()->exec('DROP TABLE news_digest_sends');
        $db->pdo()->exec('DROP TABLE news_digests');

        [$code, $out] = $this->cli('tick');
        self::assertSame(0, $code, $out);
        $after = self::line($out);
        self::assertSame(['status' => 'failed'], $after['digest']);
        unset($before['digest'], $after['digest'], $before['t'], $after['t']);
        ksort($before);
        ksort($after);
        self::assertSame(array_keys($before), array_keys($after), 'every key of the session\'s report is still there');
        self::assertSame($before['status'], $after['status']);
        self::assertSame($before['imported'], $after['imported']);
        $log = (string) file_get_contents($this->dir . '/storage/logs/site-' . gmdate('Y-m-d') . '.log');
        self::assertStringContainsString('news digest', $log);

        [$code, $out] = $this->cli('digest');
        self::assertSame(0, $code, 'the digest command exits 0 unless it could not start');
        self::assertSame(['command' => 'digest', 'digest' => ['status' => 'failed']], self::line($out));
    }

    public function testTheDigestCommandRunsThatStepAloneUnderTheSameLock(): void
    {
        [$code, $out] = $this->cli('digest');
        self::assertSame(0, $code, $out);
        $report = self::line($out);
        self::assertSame(['command', 'digest'], array_keys($report), 'no import, no session, no heartbeat');
        self::assertSame(self::REPORT_KEYS, array_keys($report['digest']));
        $db = Database::connect('sqlite:' . $this->dir . '/site.sqlite');
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM news_imports'), 'the article files were not imported');
        self::assertNull($db->value("SELECT value FROM news_settings WHERE name = 'heartbeat'"), 'nor the heartbeat written');

        $lock = fopen($this->dir . '/storage/cache/news.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            [$code, $out] = $this->cli('digest');
            self::assertSame(0, $code);
            self::assertSame('busy', self::line($out)['status']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
