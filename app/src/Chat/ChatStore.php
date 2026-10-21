<?php

declare(strict_types=1);

namespace Psc\Chat;

use Psc\Db\Database;

/**
 * Conversations and their messages, held server-side: the browser only ever sends its next message, so it cannot
 * rewrite what the assistant said earlier. Daily usage is counted for the token budget. Old conversations are
 * pruned after the retention period.
 */
final class ChatStore
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function create(string $id, ?int $userId, string $ipHash, string $page): void
    {
        $now = time();
        $this->db->insert('chat_conversations', [
            'id' => $id,
            'user_id' => $userId,
            'ip_hash' => $ipHash,
            'page' => mb_substr($page, 0, 190),
            'created_at' => $now,
            'last_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM chat_conversations WHERE id = ?', [$id]);
    }

    /**
     * The last $limit user/assistant messages, oldest first, each with the verdict stored beside it (null on a
     * visitor's message), so the answer model's history can leave out refused exchanges.
     *
     * @return list<array{role: string, content: string, verdict: string|null}>
     */
    public function history(string $id, int $limit): array
    {
        $rows = $this->db->all(
            "SELECT role, content, verdict FROM chat_messages WHERE conversation_id = ? AND role IN ('user', 'assistant') ORDER BY id DESC LIMIT " . max(1, $limit),
            [$id]
        );
        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = ['role' => (string) $r['role'], 'content' => (string) $r['content'], 'verdict' => $r['verdict'] !== null ? (string) $r['verdict'] : null];
        }
        return $out;
    }

    public function add(string $id, string $role, string $content, ?string $verdict = null, int $in = 0, int $out = 0): int
    {
        $msgId = $this->db->insert('chat_messages', [
            'conversation_id' => $id,
            'role' => $role,
            'content' => $content,
            'verdict' => $verdict,
            'tokens_in' => $in,
            'tokens_out' => $out,
            'created_at' => time(),
        ]);
        $this->db->exec(
            'UPDATE chat_conversations SET last_at = ?, tokens_in = tokens_in + ?, tokens_out = tokens_out + ?' . ($role === 'user' ? ', turns = turns + 1' : '') . ' WHERE id = ?',
            [time(), $in, $out, $id]
        );
        return $msgId;
    }

    /**
     * Prompt tokens written to the cache count at 1.25 and tokens read from it at 0.1 of an uncached input token,
     * the ratio at which they are billed, so the budget follows spend: every answer carries the long cached system
     * prompt, which the API reports apart from input_tokens.
     */
    public const CACHE_WRITE_WEIGHT = 1.25;
    public const CACHE_READ_WEIGHT = 0.1;

    /** Today's usage against chat.daily_token_budget: input and output tokens plus the weighted cached prompt. */
    public function usageToday(): int
    {
        $row = $this->db->one('SELECT tokens_in, tokens_out, cache_write, cache_read FROM chat_usage WHERE day = ?', [gmdate('Y-m-d')]);
        if ($row === null) {
            return 0;
        }
        return (int) $row['tokens_in'] + (int) $row['tokens_out']
            + (int) ceil((int) $row['cache_write'] * self::CACHE_WRITE_WEIGHT)
            + (int) ceil((int) $row['cache_read'] * self::CACHE_READ_WEIGHT);
    }

    public function addUsage(int $in, int $out, int $cacheWrite = 0, int $cacheRead = 0): void
    {
        $day = gmdate('Y-m-d');
        $this->db->transaction(function (Database $db) use ($day, $in, $out, $cacheWrite, $cacheRead): void {
            $n = $db->exec('UPDATE chat_usage SET requests = requests + 1, tokens_in = tokens_in + ?, tokens_out = tokens_out + ?, cache_write = cache_write + ?, cache_read = cache_read + ? WHERE day = ?', [$in, $out, $cacheWrite, $cacheRead, $day]);
            if ($n === 0) {
                $db->insert('chat_usage', ['day' => $day, 'requests' => 1, 'tokens_in' => $in, 'tokens_out' => $out, 'cache_write' => $cacheWrite, 'cache_read' => $cacheRead]);
            }
        });
    }

    public function prune(int $retentionDays): int
    {
        $cutoff = time() - max(1, $retentionDays) * 86400;
        $this->db->exec('DELETE FROM chat_messages WHERE conversation_id IN (SELECT id FROM chat_conversations WHERE last_at < ?)', [$cutoff]);
        return $this->db->exec('DELETE FROM chat_conversations WHERE last_at < ?', [$cutoff]);
    }
}
