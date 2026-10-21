<?php

declare(strict_types=1);

namespace Psc\Db;

/**
 * Applies database/migrations/<driver>/NNNN_name.sql files in order, once each, under a lock so two first
 * requests cannot race. A marker file holds the newest applied version, so a request on an up-to-date schema
 * costs one stat and no query.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsRoot,
        private readonly string $markerFile,
    ) {
    }

    public function ensure(): void
    {
        $latest = $this->latestAvailable();
        if ($latest === null) {
            return;
        }
        if (is_file($this->markerFile) && trim((string) @file_get_contents($this->markerFile)) === $latest) {
            return;
        }
        $this->migrate();
    }

    /** @return list<string> versions applied by this call */
    public function migrate(): array
    {
        $applied = [];
        $this->withLock(function () use (&$applied): void {
            $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at INTEGER NOT NULL)');
            $done = array_flip(array_map('strval', array_column($this->db->all('SELECT version FROM schema_migrations'), 'version')));
            foreach ($this->files() as $version => $file) {
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
        $latest = $this->latestAvailable();
        if ($latest !== null) {
            $dir = dirname($this->markerFile);
            if (is_dir($dir) || @mkdir($dir, 0770, true)) {
                @file_put_contents($this->markerFile, $latest, LOCK_EX);
            }
        }
        return $applied;
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

    private function latestAvailable(): ?string
    {
        $files = $this->files();
        if ($files === []) {
            return null;
        }
        return (string) array_key_last($files);
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
