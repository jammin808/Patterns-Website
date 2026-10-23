<?php

declare(strict_types=1);

namespace Psc\Db;

use PDO;
use PDOStatement;

/**
 * A thin PDO wrapper: prepared statements only, exceptions on error, rows as associative arrays. Queries are
 * written in the SQL that MySQL and SQLite share; timestamps are integer Unix seconds.
 */
final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function connect(string $dsn, string $user = '', #[\SensitiveParameter] string $password = ''): self
    {
        $options = [PDO::ATTR_TIMEOUT => 5];
        if (str_starts_with($dsn, 'mysql:')) {
            // The driver's own class holds the key from PHP 8.4 (PDO's copy is deprecated from 8.5); both are 1002.
            $options[\PHP_VERSION_ID >= 80400 ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'";
        }
        return new self(new PDO($dsn, $user !== '' ? $user : null, $password !== '' ? $password : null, $options));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_BOOL,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, $v, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();
        return $rows;
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<int|string, mixed> $params */
    public function exec(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)) . ')';
        $this->run($sql, $row);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $set
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $set, array $where): int
    {
        $params = [];
        $sets = [];
        foreach ($set as $k => $v) {
            $sets[] = $k . ' = :s_' . $k;
            $params['s_' . $k] = $v;
        }
        $conds = [];
        foreach ($where as $k => $v) {
            $conds[] = $k . ' = :w_' . $k;
            $params['w_' . $k] = $v;
        }
        return $this->exec('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conds), $params);
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Escapes a LIKE needle so % and _ match literally (use with ESCAPE '!'). */
    public static function like(string $needle): string
    {
        return '%' . strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
