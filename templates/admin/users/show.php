<?php
/**
 * One account for administrators: its details, the actions its state allows (never on the administrator's own
 * account), its tickets and licences, and the recent audit rows by or about it.
 *
 * @var array<string, mixed> $account
 * @var bool $isSelf
 * @var bool $deleted the owner deleted the account (its row stays for its tickets and licences)
 * @var string|null $enableTo the status enabling it would give back, for a disabled account
 * @var bool $invited the account was created by an invitation
 * @var bool $adminEmail the email is on the configured administrator list
 * @var list<array<string, mixed>> $tickets
 * @var int $ticketCount
 * @var list<array<string, mixed>> $licences
 * @var int $licenceCount
 * @var array<int, string> $licenceNames
 * @var list<array<string, mixed>> $audit rows carry actor_name and actor_email
 * @var array<string, string> $statuses
 * @var array<string, string> $statusHelp
 * @var array<string, string> $roles
 * @var array<string, string> $ticketTypes
 * @var array<string, string> $ticketStatuses
 * @var array<string, string> $licenceStatuses
 * @var array<string, string> $products
 */
$time = static fn (mixed $ts, bool $date = false): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e($date ? date_short((int) $ts) : ago((int) $ts)) . '</time>'
    : '';
$id = (int) $account['id'];
$status = (string) $account['status'];
$role = (string) $account['role'];
$company = (string) ($account['company'] ?? '');
$mailto = \Psc\Controllers\Admin\AdminController::mailto((string) $account['email']);
$action = '/portal/admin/users/' . $id . '/';

/** @var list<array{string, string, string, string}> $buttons action, label, button style, help */
$buttons = [];
if (!$isSelf && !$deleted) {
    if ($status === 'approval') {
        $buttons[] = ['approve', 'Approve this account', 'primary', 'They can sign in straight away, and we email them to say so.'];
    }
    if ($status === 'pending') {
        $buttons[] = $invited
            ? ['resend_verification', 'Send a new invitation link', 'secondary', 'They have not chosen a password yet. The new link replaces the old one.']
            : ['resend_verification', 'Resend the confirmation email', 'secondary', 'They have not confirmed their email address yet.'];
    }
    if ($status === 'disabled') {
        $buttons[] = ['enable', 'Enable this account', 'secondary', match ($enableTo) {
            'pending' => 'It goes back to pending until they confirm their email address. You can then send them a new link.',
            'approval' => 'It goes back to waiting for approval. Enabling it does not approve it.',
            default => 'They can sign in again.',
        }];
    }
    $buttons[] = $role === 'admin'
        ? ['make_client', 'Make a client', 'secondary', 'They lose access to the administration pages.']
        : ['make_admin', 'Make an administrator', 'secondary', 'They can see and change every account, ticket, licence and release.'];
    if ($status !== 'disabled') {
        $buttons[] = ['disable', 'Disable this account', 'danger', 'They are signed out and cannot sign in until the account is enabled again.'];
    }
}
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration · Users</p>
    <h1 class="pt-title"><?= e($account['name']) ?></h1>
    <p class="pt-lede"><?= e($account['email']) ?><?= $company !== '' ? ' · ' . e($company) : '' ?></p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/users/">Back to all users</a>
    </div>
</header>

<section class="pt-section" aria-labelledby="user-details">
    <h2 class="pt-h2" id="user-details">Account</h2>
    <dl class="pt-kv">
        <dt>Status</dt>
        <dd><span class="pt-badge pt-badge--<?= e($status) ?>"><?= e($statuses[$status] ?? $status) ?></span> <span class="pt-muted"><?= e($statusHelp[$status] ?? '') ?></span></dd>
        <dt>Role</dt>
        <dd><span class="pt-badge pt-badge--<?= e($role) ?>"><?= e($roles[$role] ?? $role) ?></span></dd>
        <dt>Email</dt>
        <dd><?php if ($mailto !== null): ?><a href="<?= e($mailto) ?>"><?= e($account['email']) ?></a><?php else: ?><?= e($account['email']) ?><?php endif; ?></dd>
        <dt>Company</dt>
        <dd><?= $company !== '' ? e($company) : '<span class="pt-muted">Not given</span>' ?></dd>
        <dt>Joined</dt>
        <dd><?= $time($account['created_at'], true) ?><?= $invited ? ' <span class="pt-muted">(invited)</span>' : '' ?></dd>
        <dt>Email confirmed</dt>
        <dd><?= (int) ($account['email_verified_at'] ?? 0) > 0 ? $time($account['email_verified_at'], true) : '<span class="pt-muted">Not yet</span>' ?></dd>
        <dt>Last sign-in</dt>
        <dd><?= (int) ($account['last_login_at'] ?? 0) > 0 ? $time($account['last_login_at']) : '<span class="pt-muted">Never</span>' ?></dd>
        <dt>Emails about new releases</dt>
        <dd><?= (int) $account['marketing_opt_in'] === 1 ? 'Yes' : 'No' ?></dd>
        <dt>Last changed</dt>
        <dd><?= $time($account['updated_at']) ?></dd>
    </dl>
</section>

<section class="pt-section" id="manage" aria-labelledby="user-manage">
    <h2 class="pt-h2" id="user-manage">Manage this account</h2>
    <?php if ($isSelf): ?>
        <div class="pt-alert pt-alert--info" role="status">This is your own account. To change your role or status, ask another administrator. Your name, company and password are on <a href="/portal/account/">your account page</a>.</div>
    <?php elseif ($deleted): ?>
        <div class="pt-alert pt-alert--info" role="status">This account was deleted by its owner. Their personal details are erased and it can never sign in again, so it cannot be changed. Its tickets and licences are kept below.</div>
    <?php else: ?>
        <?php if ($role === 'admin' && $adminEmail): ?>
            <div class="pt-alert pt-alert--warning" role="status">This email address is on the administrator list in the site settings, so the account becomes an administrator again whenever it signs in.</div>
        <?php endif; ?>
        <div class="pt-grid pt-grid--2">
            <?php foreach ($buttons as [$act, $label, $style, $help]): ?>
                <form class="pt-card" method="post" action="<?= e($action) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= e($act) ?>">
                    <p class="pt-card-body"><?= e($help) ?></p>
                    <div class="pt-card-footer">
                        <button type="submit" class="pt-btn pt-btn--<?= e($style) ?>"><?= e($label) ?></button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="user-tickets">
    <h2 class="pt-h2" id="user-tickets">Tickets <span class="pt-muted">(<?= $ticketCount ?>)</span></h2>
    <?php if ($tickets === []): ?>
        <div class="pt-empty"><p>No tickets from this account.</p></div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table">
                <caption class="u-visually-hidden">Tickets from <?= e($account['name']) ?>, the most recently active first</caption>
                <thead>
                    <tr>
                        <th scope="col">Ticket</th>
                        <th scope="col">Type</th>
                        <th scope="col">Product</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t):
                        $tType = (string) $t['type'];
                        $tStatus = (string) $t['status']; ?>
                        <tr>
                            <th scope="row"><a href="/portal/tickets/<?= (int) $t['id'] ?>/"><span class="pt-muted pt-mono">#<?= (int) $t['id'] ?></span> <?= e($t['title']) ?></a></th>
                            <td><span class="pt-badge pt-badge--<?= e($tType) ?>"><?= e($ticketTypes[$tType] ?? $tType) ?></span></td>
                            <td><?= e($products[(string) $t['product']] ?? $t['product']) ?></td>
                            <td><span class="pt-badge pt-badge--<?= e($tStatus) ?>"><?= e($ticketStatuses[$tStatus] ?? $tStatus) ?></span></td>
                            <td><?= $time($t['last_activity_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($ticketCount > count($tickets)): ?>
            <p><a href="/portal/admin/tickets/?q=<?= e(rawurlencode((string) $account['email'])) ?>">See all <?= $ticketCount ?> tickets from this account</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="user-licences">
    <h2 class="pt-h2" id="user-licences">Licences <span class="pt-muted">(<?= $licenceCount ?>)</span></h2>
    <?php if ($licences === []): ?>
        <div class="pt-empty"><p>No licences or licence requests for this account.</p></div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table">
                <caption class="u-visually-hidden">Licences for <?= e($account['name']) ?>, the newest first</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Licence</th>
                        <th scope="col">Status</th>
                        <th scope="col">Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($licences as $l):
                        $lStatus = (string) $l['status'];
                        $lProduct = (string) $l['product']; ?>
                        <tr>
                            <th scope="row" class="pt-mono"><a href="/portal/admin/licences/<?= (int) $l['id'] ?>/"><?= e($l['reference']) ?></a></th>
                            <td><?= e(($products[$lProduct] ?? $lProduct) . ' ' . ($licenceNames[(int) $l['id']] ?? $l['tier'])) ?></td>
                            <td><span class="pt-badge pt-badge--<?= e($lStatus) ?>"><?= e($licenceStatuses[$lStatus] ?? $lStatus) ?></span></td>
                            <td><?= $time($l['created_at'], true) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($licenceCount > count($licences)): ?>
            <p><a href="/portal/admin/licences/?q=<?= e(rawurlencode((string) $account['email'])) ?>">See all <?= $licenceCount ?> licences for this account</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="user-audit">
    <h2 class="pt-h2" id="user-audit">Recent activity</h2>
    <?php if ($audit === []): ?>
        <div class="pt-empty"><p>Nothing recorded for this account yet.</p></div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table">
                <caption class="u-visually-hidden">The latest audit log rows by or about this account, newest first</caption>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Action</th>
                        <th scope="col">By</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit as $a):
                        $meta = \Psc\Controllers\Admin\AdminController::prettyMeta($a['meta'] !== null ? (string) $a['meta'] : null); ?>
                        <tr>
                            <td><?= $time($a['created_at']) ?></td>
                            <th scope="row" class="pt-mono"><?= e($a['action']) ?></th>
                            <td>
                                <?php if ($a['user_id'] === null): ?>
                                    <span class="pt-muted">No account</span>
                                <?php elseif ((int) $a['user_id'] === $id): ?>
                                    This account
                                <?php elseif ($a['actor_email'] !== null): ?>
                                    <a href="/portal/admin/users/<?= (int) $a['user_id'] ?>/"><?= e($a['actor_name']) ?></a>
                                <?php else: ?>
                                    <span class="pt-muted">Former account</span>
                                <?php endif; ?>
                            </td>
                            <td><?php if ($meta !== ''): ?><pre class="pt-mono pt-small"><?= e($meta) ?></pre><?php else: ?><span class="pt-muted">—</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p><a href="/portal/admin/audit/?action=user.">See all account actions in the audit log</a></p>
    <?php endif; ?>
</section>
