<?php
/**
 * One ticket: its facts, the description, the conversation, the reply form when the viewer may reply, the vote on
 * a public feature request, and, for staff, the "Manage this ticket" form. Internal notes reach this template only
 * when the viewer is staff.
 *
 * @var array<string, mixed> $ticket
 * @var list<array<string, mixed>> $comments each with author (display name) and files
 * @var list<array<string, mixed>> $ticketFiles
 * @var bool $isAdmin
 * @var bool $isOwner
 * @var string $ownerLabel
 * @var array<string, mixed>|null $owner the owner's account (staff only)
 * @var bool $canComment
 * @var bool $canAttach
 * @var bool $canVote
 * @var bool $voted
 * @var array<string, string> $errors
 * @var array<string, string> $oldComment
 * @var array<string, string> $oldManage
 * @var array<string, string> $types
 * @var array<string, string> $statuses
 * @var array<string, string> $statusHelp
 * @var array<string, string> $severities
 * @var array<string, string> $visibilities
 * @var array<string, string> $products
 * @var list<string> $allowed
 * @var int $maxMb
 * @var int $maxFiles
 */
$id = (int) $ticket['id'];
$type = (string) $ticket['type'];
$status = (string) $ticket['status'];
$visibility = (string) $ticket['visibility'];
$severity = (string) ($ticket['severity'] ?? '');
$path = '/portal/tickets/' . $id . '/';
$errors ??= [];
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$field = fn (array $p): string => $this->render('portal/tickets/_field', $p);
$time = fn (mixed $ts, string $format = 'ago'): string => $this->render('portal/tickets/_time', ['ts' => $ts, 'format' => $format]);
// Headings someone writes in a body or reply sit under this page's h2 sections, so every one of them is an h3.
// md() escapes all the text it is given, so these tags can only be its own heading markup.
$prose = static fn (string $text): string => str_replace(['<h2>', '</h2>', '<h4>', '</h4>'], ['<h3>', '</h3>', '<h3>', '</h3>'], md($text));
$visibilityText = $visibility === 'public'
    ? ($type === 'feature' ? 'Public: every signed-in client can read it and its files, vote and reply.' : 'Public: every signed-in client can read it, its replies and its files.')
    : 'Private: only the person who opened it and the team can see it.';
$replyHelp = ($visibility === 'public' ? 'This ticket is public: other clients can read your reply' . ($canAttach ? ' and open any files you attach' : '') . '. ' : '')
    . 'You can use simple formatting: **bold**, *italic*, lists and links.';
if ($isAdmin) {
    [$backHref, $backLabel] = ['/portal/admin/tickets/', 'Back to all tickets'];
} elseif ($isOwner) {
    [$backHref, $backLabel] = ['/portal/tickets/', 'Back to your tickets'];
} else {
    [$backHref, $backLabel] = ['/portal/ideas/', 'Back to feature requests'];
}
$fileError = $err('attachments');
$accept = implode(',', array_map(static fn (string $x): string => '.' . $x, $allowed));
$m = static fn (string $k, string $default): string => (string) ($oldManage[$k] ?? $default);
?>
<header class="pt-header">
    <p class="pt-eyebrow"><?= e($types[$type] ?? 'Ticket') ?> <span class="pt-mono">#<?= $id ?></span></p>
    <h1 class="pt-title"><?= e($ticket['title']) ?></h1>
    <p class="pt-lede">
        <span class="pt-badge pt-badge--<?= e($status) ?>"><?= e($statuses[$status] ?? $status) ?></span>
        <span class="pt-badge pt-badge--<?= e($type) ?>"><?= e($types[$type] ?? $type) ?></span>
        <span class="pt-badge pt-badge--<?= e($visibility) ?>"><?= e($visibilities[$visibility] ?? $visibility) ?></span>
        <?= e($products[(string) $ticket['product']] ?? $ticket['product']) ?> · opened <?= $time($ticket['created_at']) ?>
    </p>
    <div class="pt-header-actions">
        <?php if ($canVote): ?>
            <div id="vote"><?= $this->render('portal/tickets/_vote', ['id' => $id, 'title' => (string) $ticket['title'], 'votes' => (int) $ticket['votes'], 'voted' => $voted, 'return' => $path]) ?></div>
        <?php endif; ?>
        <a class="pt-btn pt-btn--ghost" href="<?= e($backHref) ?>"><?= e($backLabel) ?></a>
    </div>
</header>

<?php if ($canVote && $voted): ?>
    <p class="pt-small pt-muted">You have voted for this. Press the vote button again to take your vote back.</p>
<?php endif; ?>

<section class="pt-section" aria-labelledby="details-heading">
    <h2 class="pt-h2" id="details-heading">Details</h2>
    <dl class="pt-kv">
        <dt>Status</dt>
        <dd><?= e($statuses[$status] ?? $status) ?> — <?= e($statusHelp[$status] ?? '') ?></dd>
        <dt>Product</dt>
        <dd><?= e($products[(string) $ticket['product']] ?? $ticket['product']) ?></dd>
        <?php if ($type === 'bug' && $severity !== ''): ?>
            <dt>Severity</dt>
            <dd><?= e($severities[$severity] ?? $severity) ?></dd>
        <?php endif; ?>
        <?php if (!empty($ticket['app_version'])): ?>
            <dt>Version</dt>
            <dd class="pt-mono"><?= e($ticket['app_version']) ?></dd>
        <?php endif; ?>
        <?php if (!empty($ticket['environment'])): ?>
            <dt>Windows and hardware</dt>
            <dd><?= nl2br(e($ticket['environment']), false) ?></dd>
        <?php endif; ?>
        <?php if ($type === 'feature'): ?>
            <dt>Votes</dt>
            <dd><?= (int) $ticket['votes'] ?></dd>
        <?php endif; ?>
        <dt>Opened by</dt>
        <dd>
            <bdi><?= e($ownerLabel) ?></bdi>
            <?php if ($isAdmin && $owner !== null): ?>
                · <a href="/portal/admin/users/<?= (int) $owner['id'] ?>/"><?= e($owner['email']) ?></a>
            <?php endif; ?>
        </dd>
        <dt>Opened</dt>
        <dd><?= $time($ticket['created_at'], 'date') ?></dd>
        <dt>Last activity</dt>
        <dd><?= $time($ticket['last_activity_at']) ?></dd>
        <dt>Who can see it</dt>
        <dd><?= e($visibilityText) ?></dd>
    </dl>
</section>

<section class="pt-section" aria-labelledby="description-heading">
    <h2 class="pt-h2" id="description-heading">Description</h2>
    <div class="pt-prose"><?= $prose((string) $ticket['body']) ?></div>
    <?= $this->render('portal/tickets/_files', ['files' => $ticketFiles, 'label' => 'Files attached to this ticket']) ?>
</section>

<section class="pt-section" id="conversation" aria-labelledby="conversation-heading">
    <h2 class="pt-h2" id="conversation-heading">Conversation</h2>
    <?php if ($comments === []): ?>
        <p class="pt-muted">No replies yet.<?= $isOwner && !$isAdmin ? ' You will get an email when the team replies.' : '' ?></p>
    <?php else: ?>
        <div class="pt-thread">
            <?php foreach ($comments as $c):
                $staff = (int) $c['is_staff'] === 1;
                $internal = (int) $c['is_internal'] === 1;
                $classes = 'pt-comment' . ($staff ? ' pt-comment--staff' : '') . ($internal ? ' pt-comment--internal' : ''); ?>
                <article class="<?= e($classes) ?>" id="comment-<?= (int) $c['id'] ?>">
                    <p class="pt-comment-meta">
                        <strong><bdi><?= e($c['author']) ?></bdi></strong>
                        <?php if ($staff): ?><span class="pt-badge pt-badge--admin">Team</span><?php endif; ?>
                        <?php if ($internal): ?><span class="pt-badge pt-badge--private">Internal note</span> <span class="pt-small">Only staff can see this.</span><?php endif; ?>
                        · <?= $time($c['created_at']) ?>
                    </p>
                    <div class="pt-comment-body pt-prose"><?= $prose((string) $c['body']) ?></div>
                    <?= $this->render('portal/tickets/_files', ['files' => $c['files'], 'label' => 'Files attached to this reply']) ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" id="reply" aria-labelledby="reply-heading">
    <h2 class="pt-h2" id="reply-heading">Add a reply</h2>
    <?php if ($canComment): ?>
        <form class="pt-form" method="post" action="<?= e($path) ?>comments/"<?= $canAttach ? ' enctype="multipart/form-data"' : '' ?> novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="comment">
            <?= $field(['name' => 'body', 'id' => 'reply-body', 'label' => 'Your reply', 'kind' => 'textarea', 'value' => (string) ($oldComment['body'] ?? ''), 'error' => $err('body'), 'help' => $replyHelp, 'required' => true, 'attrs' => ['rows' => 6, 'maxlength' => 5000]]) ?>
            <?php if ($canAttach): ?>
                <div class="pt-field">
                    <label for="reply-attachments">Attach files <span class="pt-muted">(optional)</span></label>
                    <input type="file" id="reply-attachments" name="attachments[]" multiple accept="<?= e($accept) ?>" aria-describedby="reply-attachments-help<?= $fileError !== null ? ' reply-attachments-error' : '' ?>"<?= $fileError !== null ? ' aria-invalid="true"' : '' ?>>
                    <p class="pt-help" id="reply-attachments-help">Up to <?= (int) $maxFiles ?> files, <?= (int) $maxMb ?> MB each. Accepted: <?= e(implode(', ', $allowed)) ?>.</p>
                    <?php if ($fileError !== null): ?><p class="pt-error" id="reply-attachments-error"><?= e($fileError) ?></p><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <div class="pt-field pt-field--inline">
                    <input type="checkbox" id="reply-internal" name="internal" value="1"<?= ($oldComment['internal'] ?? '') === '1' ? ' checked' : '' ?>>
                    <label for="reply-internal">Internal note: only staff will see it, and no email is sent</label>
                </div>
            <?php endif; ?>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary">Post reply</button>
            </div>
        </form>
    <?php else: ?>
        <p class="pt-muted">Only the person who opened this ticket and the team can reply to it.</p>
    <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
    <section class="pt-section" id="manage" aria-labelledby="manage-heading">
        <h2 class="pt-h2" id="manage-heading">Manage this ticket</h2>
        <form class="pt-form" method="post" action="/portal/admin/tickets/<?= $id ?>/" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="manage">
            <?= $field(['name' => 'status', 'id' => 'manage-status', 'label' => 'Status', 'kind' => 'select', 'value' => $m('status', $status), 'error' => $err('status'), 'help' => 'The owner gets an email when the status changes.', 'required' => true, 'options' => $statuses]) ?>
            <?= $field(['name' => 'visibility', 'id' => 'manage-visibility', 'label' => 'Who can see it', 'kind' => 'select', 'value' => $m('visibility', $visibility), 'error' => $err('visibility'), 'help' => 'Public: every signed-in client can read the ticket, its replies and its files. Public feature requests also appear on the board.', 'required' => true, 'options' => ['private' => 'Private — the owner and staff', 'public' => 'Public — every signed-in client']]) ?>
            <?php if ($type === 'bug'): ?>
                <?= $field(['name' => 'severity', 'id' => 'manage-severity', 'label' => 'Severity', 'kind' => 'select', 'value' => $m('severity', $severity), 'error' => $err('severity'), 'required' => true, 'options' => $severities]) ?>
            <?php endif; ?>
            <?= $field(['name' => 'reply', 'id' => 'manage-reply', 'label' => 'Message to go with the change', 'kind' => 'textarea', 'value' => $m('reply', ''), 'error' => $err('reply'), 'help' => 'Added to the conversation and emailed to the owner, unless it is an internal note.', 'attrs' => ['rows' => 4, 'maxlength' => 5000]]) ?>
            <div class="pt-field pt-field--inline">
                <input type="checkbox" id="manage-internal" name="internal" value="1"<?= ($oldManage['internal'] ?? '') === '1' ? ' checked' : '' ?>>
                <label for="manage-internal">Internal note: only staff will see the message, and it is never emailed</label>
            </div>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary">Save changes</button>
            </div>
        </form>
    </section>
<?php endif; ?>
