<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\News\Importer;
use Psc\News\Pipeline;
use Psc\News\Runs;
use Psc\News\Settings;
use Psc\Tests\Fakes\FakeNewsModel;
use Psc\Tests\TestCase;

/**
 * The cron tick (docs/NEWS.md §4 CLI, §6.1): each step on its own, a slot claimed exactly once, the hour between
 * sessions, a paused desk's slots recorded as skipped, stale runs reaped, the heartbeat, the log line, and
 * tools/news.php itself with its lock.
 */
final class TickTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(['news' => ['api_key' => 'sk-test', 'import_stagger' => false]]);
    }

    private function at(string $london): \DateTimeImmutable
    {
        return new \DateTimeImmutable($london, new \DateTimeZone('Europe/London'));
    }

    /** @return array<string, mixed> */
    private function tick(string $london, ?FakeNewsModel $model = null): array
    {
        return (new Pipeline($this->app))->tick($this->at($london), $model ?? new FakeNewsModel());
    }

    /** @return list<array<string, mixed>> */
    private function runRows(): array
    {
        return $this->db->all('SELECT * FROM news_runs ORDER BY id');
    }

    public function testATickRunsTheDueSlotOnce(): void
    {
        $report = $this->tick('2026-10-22 06:05');
        self::assertSame(['done', '2026-10-22 06:00', 1], [$report['status'], $report['slot'], $report['articles']]);
        foreach (['usage_in', 'usage_out', 'cache_write', 'cache_read', 'searches', 'fetches', 'cost_usd'] as $key) {
            self::assertArrayHasKey($key, $report);
        }
        self::assertArrayNotHasKey('tokens', $report);
        self::assertGreaterThan(0, $report['usage_in']);
        $rows = $this->runRows();
        self::assertSame([['2026-10-22 06:00', 'scheduled', 'done']], array_map(static fn (array $r): array => [$r['slot'], $r['kind'], $r['status']], $rows));

        // Fifteen minutes later the same slot is still in its window, but it is claimed: nothing runs.
        $model = new FakeNewsModel();
        $again = $this->tick('2026-10-22 06:20', $model);
        self::assertSame(['idle', null], [$again['status'], $again['run']]);
        self::assertSame([], $model->calls);
        self::assertCount(1, $this->runRows());

        // The afternoon session runs at its own time (the morning run is dated to its slot: the rows hold real times).
        $this->db->exec('UPDATE news_runs SET started_at = ?', [$this->at('2026-10-22 06:05')->getTimestamp()]);
        self::assertSame('2026-10-22 14:30', $this->tick('2026-10-22 14:31')['slot']);
        self::assertCount(2, $this->runRows());
    }

    public function testASlotIsClaimedExactlyOnce(): void
    {
        $runs = new Runs($this->app);
        self::assertNotNull($runs->claim('2026-10-22 06:00', 'scheduled', null));
        self::assertNull($runs->claim('2026-10-22 06:00', 'scheduled', null), 'a second tick cannot claim it');
        $report = $this->tick('2026-10-22 06:05');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_runs'));
        self::assertSame('done', $report['status'], 'the queued claim is the one that runs');
    }

    public function testNoSessionStartsWithinAnHourOfAnother(): void
    {
        $runs = new Runs($this->app);
        $manual = $runs->queueManual(null);
        self::assertNotNull($manual);
        $this->db->exec("UPDATE news_runs SET status = 'done', started_at = ?, finished_at = ? WHERE id = ?", [$this->at('2026-10-22 05:40')->getTimestamp(), $this->at('2026-10-22 05:55')->getTimestamp(), $manual]);

        self::assertSame('idle', $this->tick('2026-10-22 06:05')['status'], 'the manual run started 25 minutes ago');
        self::assertSame('idle', $this->tick('2026-10-22 06:35')['status']);
        $report = $this->tick('2026-10-22 06:45');
        self::assertSame(['done', '2026-10-22 06:00'], [$report['status'], $report['slot']], 'an hour on, the slot is still in its window');
    }

    public function testAQueuedManualRunGoesBeforeTheSchedule(): void
    {
        $manual = (new Runs($this->app))->queueManual(7);
        $report = $this->tick('2026-10-22 06:05');
        self::assertSame([$manual, 'done'], [$report['run'], $report['status']]);
        // The rows hold real times: the manual run is dated to the tick that ran it.
        $this->db->exec('UPDATE news_runs SET started_at = ?', [$this->at('2026-10-22 06:05')->getTimestamp()]);
        self::assertSame('idle', $this->tick('2026-10-22 06:20')['status'], 'the scheduled slot waits an hour after it');
        self::assertSame('2026-10-22 06:00', $this->tick('2026-10-22 07:10')['slot'], 'and then runs, still in its window');
    }

    public function testASlotClaimedByATickThatDiedIsNotRunLate(): void
    {
        $runs = new Runs($this->app);
        $id = (int) $runs->claim('2026-10-21 14:30', 'scheduled', null);
        $this->db->exec('UPDATE news_runs SET created_at = ? WHERE id = ?', [time() - 3 * 3600, $id]);
        $model = new FakeNewsModel();
        $report = $this->tick('2026-10-22 12:00', $model);
        self::assertSame('idle', $report['status']);
        self::assertSame([], $model->calls);
        self::assertSame('skipped', $runs->find($id)['status']);
        self::assertStringContainsString('its time has passed', (string) $runs->find($id)['summary']);
    }

    public function testAPausedDeskRecordsItsDueSlotAsSkipped(): void
    {
        (new Settings($this->app))->save(['enabled' => '0'], null);
        $model = new FakeNewsModel();
        $report = $this->tick('2026-10-22 06:05', $model);
        self::assertSame(['skipped', 'paused'], [$report['status'], $report['reason']]);
        self::assertSame([['2026-10-22 06:00', 'skipped', Pipeline::SKIPS['paused']]], array_map(static fn (array $r): array => [$r['slot'], $r['status'], $r['summary']], $this->runRows()));
        self::assertSame([], $model->calls);
        self::assertSame('idle', $this->tick('2026-10-22 06:20', $model)['status'], 'the slot is not skipped twice');
    }

    public function testACalendarDayOffRunsNothing(): void
    {
        self::assertSame([], (new Settings($this->app))->save(['date_overrides' => [$this->at('tomorrow')->format('Y-m-d') => 'off']], null)['errors']);
        $report = $this->tick($this->at('tomorrow')->format('Y-m-d') . ' 06:05');
        self::assertSame('idle', $report['status']);
        self::assertSame([], $this->runRows());
    }

    public function testAStaleRunIsReaped(): void
    {
        $runs = new Runs($this->app);
        $id = $runs->claim('2026-10-21 14:30', 'scheduled', null);
        self::assertTrue($runs->start((int) $id, time() - 1000));
        $report = $this->tick('2026-10-22 12:00');
        self::assertSame(1, $report['reaped']);
        $row = $runs->find((int) $id);
        self::assertSame('failed', $row['status']);
        self::assertStringStartsWith('Stopped: the session passed its deadline', (string) $row['summary']);
        self::assertNotNull($row['finished_at']);
    }

    public function testOneFailingStepDoesNotStopTheOthers(): void
    {
        $runs = new Runs($this->app);
        $id = $runs->claim('2026-10-21 14:30', 'scheduled', null);
        self::assertTrue($runs->start((int) $id, time() - 1000));
        $this->refuseWrites('runs_frozen', 'UPDATE', 'news_runs');

        $report = $this->tick('2026-10-22 12:00');
        self::assertSame(['reap'], array_keys($report['errors']));
        self::assertSame(count(glob(Importer::directory($this->app) . '/*.json') ?: []), $report['imported'] + $report['import_failed'], 'the import still ran');
        self::assertGreaterThan(0, $report['imported']);
        $tick = (new Settings($this->app))->lastTick();
        self::assertNotNull($tick, 'the heartbeat was still written');
        self::assertSame('idle', $tick['status']);
    }

    public function testTheHeartbeatSaysWhatTheTickDid(): void
    {
        $before = time();
        $report = $this->tick('2026-10-22 06:05');
        $tick = (new Settings($this->app))->lastTick();
        self::assertSame([$report['run'], 'done'], [$tick['run'], $tick['status']]);
        self::assertGreaterThanOrEqual($before, $tick['t']);
    }

    public function testTheLogLineMasksNothing(): void
    {
        $this->tick('2026-10-22 06:05');
        $lines = [];
        foreach (glob($this->storage . '/logs/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry) && $entry['msg'] === 'news tick') {
                    $lines[] = $line;
                    self::assertGreaterThan(0, $entry['ctx']['usage_in']);
                    self::assertGreaterThan(0, $entry['ctx']['usage_out']);
                    self::assertGreaterThan(0, $entry['ctx']['cost_usd']);
                }
            }
        }
        self::assertCount(1, $lines);
        self::assertStringNotContainsString('***', $lines[0]);
    }

    public function testTheCommandLinePrintsOneJsonLineAndHoldsItsLock(): void
    {
        $dir = $this->storage . '/cli';
        mkdir($dir, 0777, true);
        $config = $dir . '/config.php';
        file_put_contents($config, '<?php return ' . var_export([
            'app' => ['url' => 'https://patternsshowcontrol.test', 'env' => 'testing', 'secret' => str_repeat('cd', 32), 'force_https' => false],
            'storage' => ['path' => $dir . '/storage'],
            'db' => ['dsn' => 'sqlite:' . $dir . '/site.sqlite'],
            'mail' => ['driver' => 'log'],
            'chat' => ['api_key' => ''],
            'news' => ['api_key' => '', 'import_stagger' => false],
        ], true) . ';');

        [$code, $out] = self::cli($config, 'import');
        self::assertSame(0, $code, $out);
        $lines = array_values(array_filter(explode("\n", trim($out))));
        self::assertCount(1, $lines, 'one line');
        $report = json_decode($lines[0], true);
        self::assertSame('import', $report['command']);
        self::assertSame(count(glob(Importer::directory($this->app) . '/*.json') ?: []), $report['imported'] + $report['import_failed']);
        self::assertGreaterThan(0, $report['imported']);

        [$code, $out] = self::cli($config, 'tick');
        self::assertSame(0, $code, $out);
        $report = json_decode(trim($out), true);
        self::assertSame(0, $report['imported'], 'each file is imported once');
        self::assertContains($report['status'], ['idle', 'skipped'], 'without a key a due slot is skipped');
        self::assertSame(0, $report['usage_in']);
        self::assertArrayHasKey('usage_out', $report);

        // While another command holds the lock, a tick exits at once.
        $lock = fopen($dir . '/storage/cache/news.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            [$code, $out] = self::cli($config, 'tick');
            self::assertSame(0, $code);
            self::assertSame('busy', json_decode(trim($out), true)['status']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        [$code, $out] = self::cli($config, 'nonsense');
        self::assertSame(2, $code);
    }

    /** @return array{0: int, 1: string} the exit code and what it printed */
    private static function cli(string $config, string $command): array
    {
        $env = getenv();
        $env['PSC_CONFIG'] = $config;
        $process = proc_open([PHP_BINARY, PSC_ROOT . '/tools/news.php', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PSC_ROOT, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out . $err];
    }
}
