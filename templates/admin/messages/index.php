<?php
/**
 * Contact-form messages in three tabs (new, read, archived), newest first. Each message shows who sent it, a
 * reply link, what it is about and the message itself (plain text: nl2br(e()), never Markdown), with buttons
 * that move it to another tab.
 *
 * @var \Psc\Support\Paginator $pager
 * @var string $status the open tab
 * @var array<string, string> $statuses
 * @var array<string, int> $counts per status
 * @var array<string, string> $topics
 * @var array<string, string> $products
 * @var string $replySubject
 */
$time = static fn (mixed $ts): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(date_short((int) $ts) . ', ' . date('H:i', (int) $ts)) . '</time>'
    : '';
$moves = [
    'new' => ['read' => 'Mark as read', 'archived' => 'Archive'],
    'read' => ['new' => 'Mark as new', 'archived' => 'Archive'],
    'archived' => ['new' => 'Mark as new', 'read' => 'Move to read'],
];
$empty = [
    'new' => 'No new messages. You are all caught up.',
    'read' => 'No read messages. Messages you mark as read are kept here.',
    'archived' => 'Nothing archived yet. Archive a message once it needs nothing more from you.',
];
$tabUrl = static fn (string $s): string => '/portal/admin/messages/' . ($s !== 'new' ? '?status=' . rawurlencode($s) : '');
$tabTitle = ['new' => 'New messages', 'read' => 'Read messages', 'archived' => 'Archived messages'];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Messages</h1>
    <p class="pt-lede">Messages sent through the contact form. Reply by email, then mark each one read or archive it.</p>
</header>

<nav class="pt-tabs" aria-label="Message folders">
    <?php foreach ($statuses as $key => $label): ?>
        <a href="<?= e($tabUrl($key)) ?>"<?= $key === $status ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="pt-muted">(<?= (int) ($counts[$key] ?? 0) ?>)</span></a>
    <?php endforeach; ?>
</nav>

<section class="pt-section" aria-labelledby="messages-list">
    <h2 class="pt-h2" id="messages-list"><?= e($tabTitle[$status] ?? 'Messages') ?></h2>
    <?php if ($pager->items === []): ?>
        <div class="pt-empty">
            <p><?= e($empty[$status] ?? 'No messages here.') ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($pager->items as $m):
            $id = (int) $m['id'];
            $topic = (string) $m['topic'];
            $product = (string) ($m['product'] ?? '');
            $company = (string) ($m['company'] ?? '');
            $mailto = \Psc\Controllers\Admin\AdminController::mailto((string) $m['email'], $replySubject); ?>
            <article class="pt-card" id="message-<?= $id ?>" aria-labelledby="message-<?= $id ?>-title">
                <h3 class="pt-card-title" id="message-<?= $id ?>-title"><?= e($m['name']) ?><?= $company !== '' ? ' · ' . e($company) : '' ?></h3>
                <p class="pt-card-meta">
                    <span class="pt-badge pt-badge--<?= e((string) $m['status']) ?>"><?= e($statuses[(string) $m['status']] ?? $m['status']) ?></span>
                    <?= e($topics[$topic] ?? $topic) ?> · received <?= $time($m['created_at']) ?>
                </p>
                <dl class="pt-kv">
                    <dt>Email</dt>
                    <dd><?php if ($mailto !== null): ?><a href="<?= e($mailto) ?>"><?= e($m['email']) ?></a><?php else: ?><?= e($m['email']) ?><?php endif; ?></dd>
                    <dt>Company</dt>
                    <dd><?= $company !== '' ? e($company) : '<span class="pt-muted">Not given</span>' ?></dd>
                    <dt>About</dt>
                    <dd><?= e($topics[$topic] ?? $topic) ?></dd>
                    <dt>Product</dt>
                    <dd><?= $product !== '' ? e($products[$product] ?? $product) : '<span class="pt-muted">Not given</span>' ?></dd>
                    <dt>Reference</dt>
                    <dd class="pt-mono">#<?= $id ?></dd>
                </dl>
                <div class="pt-card-body">
                    <p><?= nl2br(e($m['message'])) ?></p>
                </div>
                <div class="pt-card-footer">
                    <form method="post" action="/portal/admin/messages/<?= $id ?>/" class="pt-actions">
                        <?= csrf_field() ?>
                        <?php if ($pager->page > 1): ?><input type="hidden" name="page" value="<?= (int) $pager->page ?>"><?php endif; ?>
                        <?php if ($mailto !== null): ?><a class="pt-btn pt-btn--primary pt-btn--small" href="<?= e($mailto) ?>">Reply by email</a><?php endif; ?>
                        <?php foreach ($moves[(string) $m['status']] ?? [] as $to => $label): ?>
                            <button type="submit" name="status" value="<?= e($to) ?>" class="pt-btn pt-btn--secondary pt-btn--small"><?= e($label) ?><span class="u-visually-hidden"> (message from <?= e($m['name']) ?>)</span></button>
                        <?php endforeach; ?>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?= $pager->links() ?>
    <?php endif; ?>
</section>
