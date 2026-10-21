<?php

declare(strict_types=1);

namespace Psc\Support;

use Psc\Db\Database;

/**
 * Fixed-window counters in the database: hit() records one attempt in a bucket and says whether it is within the
 * limit. Buckets are hashed so no address or email is stored in clear.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly string $secret)
    {
    }

    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        return $this->hits($bucket, $windowSeconds) <= $limit;
    }

    /** Records one hit and returns the count in the current window (including this one). */
    public function hits(string $bucket, int $windowSeconds): int
    {
        $key = $this->key($bucket);
        $now = time();
        return $this->db->transaction(function (Database $db) use ($key, $now, $windowSeconds): int {
            $row = $db->one('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?', [$key]);
            if ($row === null) {
                $db->insert('rate_limits', ['bucket' => $key, 'hits' => 1, 'reset_at' => $now + $windowSeconds]);
                return 1;
            }
            if ((int) $row['reset_at'] <= $now) {
                $db->update('rate_limits', ['hits' => 1, 'reset_at' => $now + $windowSeconds], ['bucket' => $key]);
                return 1;
            }
            $db->exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$key]);
            return (int) $row['hits'] + 1;
        });
    }

    /** The count in the current window without recording a hit. */
    public function peek(string $bucket): int
    {
        $row = $this->db->one('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?', [$this->key($bucket)]);
        if ($row === null || (int) $row['reset_at'] <= time()) {
            return 0;
        }
        return (int) $row['hits'];
    }

    public function clear(string $bucket): void
    {
        $this->db->exec('DELETE FROM rate_limits WHERE bucket = ?', [$this->key($bucket)]);
    }

    public function prune(): int
    {
        return $this->db->exec('DELETE FROM rate_limits WHERE reset_at <= ?', [time()]);
    }

    private function key(string $bucket): string
    {
        $prefix = strstr($bucket, ':', true);
        $prefix = $prefix === false ? 'b' : substr($prefix, 0, 24);
        return $prefix . ':' . hash_hmac('sha256', $bucket, $this->secret);
    }
}
