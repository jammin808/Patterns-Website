<?php

declare(strict_types=1);

namespace Psc\Support;

use Psc\App;

/** Who did what in the portal: sign-ins, password changes, role and status changes, licence and release actions. */
final class Audit
{
    /** @param array<string, mixed> $meta */
    public static function log(App $app, ?int $userId, string $action, ?string $subject = null, array $meta = []): void
    {
        try {
            $app->db()->insert('audit_log', [
                'user_id' => $userId,
                'action' => mb_substr($action, 0, 64),
                'subject' => $subject !== null ? mb_substr($subject, 0, 64) : null,
                'meta' => $meta !== [] ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'ip_hash' => $app->ipHash($app->clientIp()),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            $app->log->exception($e, 'audit');
        }
    }
}
