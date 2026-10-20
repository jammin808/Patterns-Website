<?php
/**
 * Every account: tabs by status, a search by name, email or company with a role filter, the list, and the form
 * that invites a client.
 *
 * @var \Psc\Support\Paginator $pager rows carry ticket_count and licence_count
 * @var array{q: string, status: string, role: string} $filters
 * @var bool $filtered
 * @var array<string, int> $counts '' => all, then per status
 * @var array<string, string> $statuses
 * @var array<string, string> $roles
 * @var array<string, string> $errors invite form errors
 * @var array<string, string> $old invite form values
 */
$time = static fn (mixed $ts, bool $date = false): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e($date ? date_short((int) $ts) : ago((int) $ts)) . '</time>'
    : '';
$tabUrl = static function (string $s) use ($filters): string {
    $q = array_filter(['q' => $filters['q'], 'status' => $s, 'role' => $filters['role']], static fn (string $v): bool => $v !== '');
    return '/portal/admin/users/' . ($q !== [] ? '?' . http_build_query($q) : '');
};
$tabNames = ['' => 'All'] + $statuses;
/** One invite field's error wiring: [extra attributes, error markup]. */
$wire = static function (string $field, string $id, ?string $help = null) use ($errors): array {
    $err = (string) ($errors[$field] ?? '');
    $describedBy = array_filter([$help !== null ? $id . '-help' : '', $err !== '' ? $id . '-error' : '']);
    $attrs = ($err !== '' ? ' aria-invalid="true"' : '') . ($describedBy !== [] ? ' aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '');
    $markup = $err !== '' ? '<p class="pt-error" id="' . e($id) . '-error">' . e($err) . '</p>' : '';
    return [$attrs, $markup];
};
$oldRole = isset($roles[$old['role'] ?? '']) ? (string) $old['role'] : 'client';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Users</h1>
    <p class="pt-lede">Every client and administrator account. Open one to approve it, disable it or change its role.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--primary" href="#invite">Invite a client</a>
    </div>
</header>

<nav class="pt-tabs" aria-label="Account status">
    <?php foreach ($tabNames as $s => $label): ?>
        <a href="<?= e($tabUrl((string) $s)) ?>"<?= (string) $s === $filters['status'] ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="pt-muted">(<?= (int) ($counts[$s] ?? 0) ?>)</span></a>
    <?php endforeach; ?>
</nav>

<form class="pt-search" method="get" action="/portal/admin/users/" role="search" aria-label="Search accounts">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <div class="pt-field">
        <label for="users-q">Search</label>
        <input type="search" id="users-q" name="q" value="<?= e($filters['q']) ?>" maxlength="100" autocomplete="off" aria-describedby="users-q-help">
        <p class="pt-help" id="users-q-help">A name, an email address or a company.</p>
    </div>
    <div class="pt-field">
        <label for="users-role">Role</label>
        <select id="users-role" name="role">
            <option value="">Any role</option>
            <?php foreach ($roles as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $key === $filters['role'] ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Search</button>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/admin/users/">Clear</a><?php endif; ?>
    </div>
</form>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <?php if ($filtered): ?>
            <p>No accounts match this search.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/admin/users/">Show every account</a>
        <?php else: ?>
            <p>No accounts yet. Invite your first client below.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table">
            <caption class="u-visually-hidden">Accounts, the newest first (<?= (int) $pager->total ?> in all)</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Company</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="pt-num">Tickets</th>
                    <th scope="col" class="pt-num">Licences</th>
                    <th scope="col">Joined</th>
                    <th scope="col">Last sign-in</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $u):
                    $uRole = (string) $u['role'];
                    $uStatus = (string) $u['status']; ?>
                    <tr<?= $uStatus === 'approval' ? ' class="is-unread"' : '' ?>>
                        <th scope="row"><a href="/portal/admin/users/<?= (int) $u['id'] ?>/"><?= e($u['name']) ?></a></th>
                        <td><?= e($u['email']) ?></td>
                        <td><?= ($u['company'] ?? '') !== '' ? e($u['company']) : '<span class="pt-muted">—</span>' ?></td>
                        <td><span class="pt-badge pt-badge--<?= e($uRole) ?>"><?= e($roles[$uRole] ?? $uRole) ?></span></td>
                        <td><span class="pt-badge pt-badge--<?= e($uStatus) ?>"><?= e($statuses[$uStatus] ?? $uStatus) ?></span></td>
                        <td class="pt-num"><?= (int) $u['ticket_count'] ?></td>
                        <td class="pt-num"><?= (int) $u['licence_count'] ?></td>
                        <td><?= $time($u['created_at'], true) ?></td>
                        <td><?= (int) ($u['last_login_at'] ?? 0) > 0 ? $time($u['last_login_at']) : '<span class="pt-muted">Never</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>

<section class="pt-section" id="invite" aria-labelledby="invite-title">
    <h2 class="pt-h2" id="invite-title">Invite a client</h2>
    <p>We create the account and email them a link to choose their own password. The link works once, for seven days.</p>
    <form class="pt-form" method="post" action="/portal/admin/users/invite/" novalidate>
        <?= csrf_field() ?>
        <?php [$a, $err] = $wire('name', 'invite-name'); ?>
        <div class="pt-field">
            <label for="invite-name">Name</label>
            <input type="text" id="invite-name" name="name" value="<?= e($old['name'] ?? '') ?>" maxlength="120" required autocomplete="off"<?= $a ?>>
            <?= $err ?>
        </div>
        <?php [$a, $err] = $wire('email', 'invite-email', 'help'); ?>
        <div class="pt-field">
            <label for="invite-email">Email address</label>
            <input type="email" id="invite-email" name="email" value="<?= e($old['email'] ?? '') ?>" maxlength="190" required autocomplete="off" spellcheck="false"<?= $a ?>>
            <p class="pt-help" id="invite-email-help">The invitation goes here, and it is the address they sign in with.</p>
            <?= $err ?>
        </div>
        <?php [$a, $err] = $wire('company', 'invite-company'); ?>
        <div class="pt-field">
            <label for="invite-company">Company <span class="pt-muted">(optional)</span></label>
            <input type="text" id="invite-company" name="company" value="<?= e($old['company'] ?? '') ?>" maxlength="160" autocomplete="off"<?= $a ?>>
            <?= $err ?>
        </div>
        <?php [$a, $err] = $wire('role', 'invite-role', 'help'); ?>
        <div class="pt-field">
            <label for="invite-role">Role</label>
            <select id="invite-role" name="role" required<?= $a ?>>
                <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $key === $oldRole ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="pt-help" id="invite-role-help">Administrators can see every account, ticket, licence and message.</p>
            <?= $err ?>
        </div>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary">Send the invitation</button>
        </div>
    </form>
</section>
