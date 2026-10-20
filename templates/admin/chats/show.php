<?php
/**
 * One assistant conversation, in order: the visitor's messages, the assistant's answers with their verdict, and
 * any thumbs up or down the visitor left, shown as feedback. Everything is plain text (nl2br(e())).
 *
 * @var array<string, mixed> $conversation with user_name and user_email (null for a visitor)
 * @var list<array<string, mixed>> $messages
 * @var array<string, string> $verdicts
 * @var array<string, string> $verdictBadges verdict => badge modifier
 */
$time = static fn (mixed $ts, bool $long = false): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e($long ? date_short((int) $ts) . ', ' . date('H:i:s', (int) $ts) : date('H:i:s', (int) $ts)) . '</time>'
    : '';
$c = $conversation;
$roles = ['user' => 'Visitor', 'assistant' => 'Assistant', 'feedback' => 'Feedback'];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration · Assistant chats</p>
    <h1 class="pt-title">Assistant conversation</h1>
    <p class="pt-lede">Started <?= $time($c['created_at'], true) ?><?= ($c['page'] ?? '') !== '' ? ' on ' . e($c['page']) : '' ?>.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/chats/">Back to all conversations</a>
    </div>
</header>

<section class="pt-section" aria-labelledby="chat-facts">
    <h2 class="pt-h2" id="chat-facts">About this conversation</h2>
    <dl class="pt-kv">
        <dt>Who</dt>
        <dd>
            <?php if ($c['user_id'] !== null && $c['user_email'] !== null): ?>
                <a href="/portal/admin/users/<?= (int) $c['user_id'] ?>/"><?= e($c['user_name']) ?></a> <span class="pt-muted"><?= e($c['user_email']) ?></span>
            <?php elseif ($c['user_id'] !== null): ?>
                A signed-in client whose account no longer exists
            <?php else: ?>
                A visitor who was not signed in
            <?php endif; ?>
        </dd>
        <dt>Page</dt>
        <dd class="pt-mono"><?= ($c['page'] ?? '') !== '' ? e($c['page']) : '<span class="pt-muted">Not recorded</span>' ?></dd>
        <dt>Started</dt>
        <dd><?= $time($c['created_at'], true) ?></dd>
        <dt>Last message</dt>
        <dd><?= $time($c['last_at'], true) ?></dd>
        <dt>Turns</dt>
        <dd><?= (int) $c['turns'] ?></dd>
        <dt>Tokens</dt>
        <dd><?= e(number_format((int) $c['tokens_in'])) ?> in, <?= e(number_format((int) $c['tokens_out'])) ?> out</dd>
    </dl>
</section>

<section class="pt-section" aria-labelledby="chat-transcript">
    <h2 class="pt-h2" id="chat-transcript">Transcript</h2>
    <?php if ($messages === []): ?>
        <div class="pt-empty"><p>This conversation has no messages.</p></div>
    <?php else: ?>
        <div class="pt-thread">
            <?php foreach ($messages as $m):
                $role = (string) $m['role'];
                $verdict = (string) ($m['verdict'] ?? '');
                $class = match ($role) {
                    'assistant' => 'pt-comment pt-comment--staff',
                    'feedback' => 'pt-comment pt-comment--internal',
                    default => 'pt-comment',
                }; ?>
                <article class="<?= e($class) ?>" id="chat-message-<?= (int) $m['id'] ?>">
                    <p class="pt-comment-meta">
                        <strong><?= e($roles[$role] ?? ucfirst($role)) ?></strong>
                        <?php if ($verdict !== ''): ?>
                            <span class="pt-badge pt-badge--<?= e($verdictBadges[$verdict] ?? 'closed') ?>"><?= e($verdicts[$verdict] ?? $verdict) ?></span>
                        <?php endif; ?>
                        · <?= $time($m['created_at']) ?>
                        <?php if ((int) $m['tokens_in'] + (int) $m['tokens_out'] > 0): ?>
                            · <span class="pt-small pt-muted"><?= e(number_format((int) $m['tokens_in'])) ?> in, <?= e(number_format((int) $m['tokens_out'])) ?> out</span>
                        <?php endif; ?>
                    </p>
                    <div class="pt-comment-body">
                        <?php if ($role === 'feedback'):
                            $fb = \Psc\Controllers\Admin\AdminController::feedback((string) $m['content']); ?>
                            <p><?= e(match ($fb['rating']) { 'up' => 'Thumbs up for the answer above.', 'down' => 'Thumbs down for the answer above.', default => 'Feedback on the answer above.' }) ?></p>
                            <?php if ($fb['note'] !== ''): ?><p><?= nl2br(e($fb['note'])) ?></p><?php endif; ?>
                        <?php else: ?>
                            <p><?= nl2br(e($m['content'])) ?></p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
