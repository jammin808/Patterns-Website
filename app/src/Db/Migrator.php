<?php

declare(strict_types=1);

namespace Psc\Db;

/**
 * Applies database/migrations/<driver>/NNNN_name.sql files in order, once each, under a lock so two first
 * requests cannot race. A marker file names every version applied, so a request on an up-to-date schema costs one
 * stat and no query, and a release that adds any version, even one numbered below the newest, migrates.
 */
final class Migrator
{
    /**
     * @param string $database what identifies the database (its DSN and user): the marker records which database it
     *                         speaks for, so a site moved to another database (a config uploaded after the first
     *                         requests ran on the default SQLite file) migrates that one instead of trusting a marker
     *                         written for the old one
     */
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsRoot,
        private readonly string $markerFile,
        private readonly string $database = '',
    ) {
    }

    public function ensure(): void
    {
        $versions = array_keys($this->files());
        if ($versions === []) {
            return;
        }
        if (is_file($this->markerFile) && trim((string) @file_get_contents($this->markerFile)) === $this->marker($versions)) {
            return;
        }
        $this->migrate();
    }

    /**
     * Whether the database itself holds every migration this release ships (asks the database, not the marker): each
     * version, so one numbered below the newest and not yet applied is reported too.
     */
    public function isCurrent(): bool
    {
        $versions = array_keys($this->files());
        if ($versions === []) {
            return true;
        }
        try {
            $done = array_map('strval', array_column($this->db->all('SELECT version FROM schema_migrations'), 'version'));
        } catch (\PDOException) {
            return false;
        }
        return array_diff($versions, $done) === [];
    }

    /** @return list<string> versions applied by this call */
    public function migrate(): array
    {
        $applied = [];
        // The marker names only the versions this locked pass went through: a file that lands after its scan is
        // missing from the marker, so the next ensure() migrates it.
        $versions = [];
        $this->withLock(function () use (&$applied, &$versions): void {
            $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at INTEGER NOT NULL)');
            $done = array_flip(array_map('strval', array_column($this->db->all('SELECT version FROM schema_migrations'), 'version')));
            $files = $this->files();
            $versions = array_map('strval', array_keys($files));
            foreach ($files as $version => $file) {
                if (isset($done[$version])) {
                    continue;
                }
                $sql = (string) file_get_contents($file);
                foreach (self::split($sql) as $statement) {
                    $this->db->pdo()->exec($statement);
                }
                $this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => time()]);
                $applied[] = $version;
            }
        });
        if ($versions !== []) {
            $dir = dirname($this->markerFile);
            if (is_dir($dir) || @mkdir($dir, 0770, true)) {
                @file_put_contents($this->markerFile, $this->marker($versions), LOCK_EX);
            }
        }
        return $applied;
    }

    /**
     * The marker's content: the newest version, a fingerprint of every version (so a file added below the newest
     * changes it) and a fingerprint of the database they were applied to.
     *
     * @param non-empty-list<string> $versions sorted
     */
    private function marker(array $versions): string
    {
        return end($versions) . ' ' . substr(hash('sha256', implode("\n", $versions)), 0, 16) . ' ' . substr(hash('sha256', $this->database), 0, 16);
    }

    /** @return array<string, string> version => file, sorted */
    private function files(): array
    {
        $dir = $this->migrationsRoot . '/' . ($this->db->driver() === 'mysql' ? 'mysql' : 'sqlite');
        $out = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $out[basename($file, '.sql')] = $file;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function withLock(callable $fn): void
    {
        if ($this->db->driver() === 'mysql') {
            $got = (int) $this->db->value("SELECT GET_LOCK('psc_migrate', 30)");
            if ($got !== 1) {
                throw new \RuntimeException('Could not take the migration lock');
            }
            try {
                $fn();
            } finally {
                $this->db->value("SELECT RELEASE_LOCK('psc_migrate')");
            }
            return;
        }
        $lockFile = $this->markerFile . '.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $h = fopen($lockFile, 'c');
        if ($h === false) {
            $fn();
            return;
        }
        try {
            flock($h, LOCK_EX);
            $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** @return list<string> statements split on semicolons at line ends, comments dropped */
    public static function split(string $sql): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $lines[] = $line;
        }
        $out = [];
        foreach (preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}
