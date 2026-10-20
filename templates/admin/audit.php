<?php
/**
 * The audit log, newest first: when, who (the account behind the row, if any), the action, its subject (linked
 * when it names a record with an admin page) and its details, pretty-printed and escaped. Filtered by an action
 * prefix such as "user." or "login".
 *
 * @var \Psc\Support\Paginator $pager rows carry actor_name and actor_email
 * @var string $prefix
 * @var list<string> $groups the first part of every action in the log, for quick filters
 * @var bool $invalid the typed filter had characters an action never has, so it was ignored
 * @var string $typed what was typed into the filter, shown back in the field
 */
$time = static fn (mixed $ts): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(date_short((int) $ts) . ', ' . date('H:i:s', (int) $ts)) . '</time>'
    : '';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Audit log</h1>
    <p class="pt-lede">Who did what in the portal: sign-ins, account changes, and ticket, licence and release actions.</p>
</header>

<form class="pt-search" method="get" action="/portal/admin/audit/" role="search" aria-label="Filter the audit log">
    <div class="pt-field">
        <label for="audit-action">Action starts with</label>
        <input type="search" id="audit-action" name="action" value="<?= e($typed) ?>" maxlength="64" autocomplete="off" aria-describedby="audit-action-help<?= $invalid ? ' audit-action-error' : '' ?>"<?= $invalid ? ' aria-invalid="true"' : '' ?>>
        <p class="pt-help" id="audit-action-help">For example user, login.failed or licence.</p>
        <?php if ($invalid): ?><p class="pt-error" id="audit-action-error">Use only letters, numbers, dots, dashes and underscores. Showing everything instead.</p><?php endif; ?>
    </div>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Filter</button>
        <?php if ($prefix !== ''): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/admin/audit/">Show everything</a><?php endif; ?>
    </div>
</form>

<?php if ($groups !== []): ?>
    <nav class="pt-tabs" aria-label="Kinds of action">
        <a href="/portal/admin/audit/"<?= $prefix === '' ? ' aria-current="page"' : '' ?>>Everything</a>
        <?php foreach ($groups as $g): ?>
            <a href="/portal/admin/audit/?action=<?= e(rawurlencode($g . '.')) ?>"<?= $prefix === $g . '.' ? ' aria-current="page"' : '' ?>><?= e($g) ?></a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <?php if ($prefix !== ''): ?>
            <p>No actions start with “<?= e($prefix) ?>”.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/admin/audit/">Show everything</a>
        <?php else: ?>
            <p>The audit log is empty. Sign-ins and administration actions are recorded here as they happen.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table">
            <caption class="u-visually-hidden">Audit log<?= $prefix !== '' ? ', actions starting with ' . e($prefix) : '' ?>, newest first (<?= (int) $pager->total ?> in all)</caption>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">Action</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $a):
                    $action = (string) $a['action'];
                    $subject = $a['subject'] !== null ? (string) $a['subject'] : null;
                    $subjectUrl = \Psc\Controllers\Admin\AdminController::subjectUrl($action, $subject);
                    $meta = \Psc\Controllers\Admin\AdminController::prettyMeta($a['meta'] !== null ? (string) $a['meta'] : null); ?>
                    <tr>
                        <td><?= $time($a['created_at']) ?></td>
                        <td>
                            <?php if ($a['user_id'] !== null && $a['actor_email'] !== null): ?>
                                <a href="/portal/admin/users/<?= (int) $a['user_id'] ?>/"><?= e($a['actor_name']) ?></a><br><span class="pt-small pt-muted"><?= e($a['actor_email']) ?></span>
                            <?php elseif ($a['user_id'] !== null): ?>
                                <span class="pt-muted">Former account #<?= (int) $a['user_id'] ?></span>
                            <?php else: ?>
                                <span class="pt-muted">No account</span>
                            <?php endif; ?>
                        </td>
                        <th scope="row" class="pt-mono"><?= e($action) ?></th>
                        <td class="pt-mono"><?php if ($subject === null): ?><span class="pt-muted">—</span><?php elseif ($subjectUrl !== null): ?><a href="<?= e($subjectUrl) ?>"><?= e($subject) ?></a><?php else: ?><?= e($subject) ?><?php endif; ?></td>
                        <td><?php if ($meta !== ''): ?><pre class="pt-mono pt-small"><?= e($meta) ?></pre><?php else: ?><span class="pt-muted">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>
