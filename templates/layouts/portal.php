<?php
/**
 * The client portal layout: the site header and footer around a portal shell with its own navigation.
 *
 * @var \Psc\View\Seo $seo
 * @var string $content
 * @var array<string, mixed>|null $user
 * @var list<array{type: string, message: string}> $flashes
 */
$user ??= null;
$isAdmin = ($user['role'] ?? '') === 'admin';
// Signed out, the switch offers sign-up only while accounts are open to everyone or by approval.
$inviteOnly = $app->config->string('security.registration', 'open') === 'invite';
$links = $user === null ? array_values(array_filter([
    ['/portal/login/', 'Sign in'],
    $inviteOnly ? null : ['/portal/register/', 'Create an account'],
])) : [
    ['/portal/', 'Dashboard'],
    ['/portal/downloads/', 'Downloads'],
    ['/portal/licences/', 'Licences'],
    ['/portal/tickets/', 'Tickets'],
    ['/portal/ideas/', 'Feature requests'],
    ['/portal/account/', 'Account'],
];
$adminLinks = $isAdmin ? [
    ['/portal/admin/', 'Admin overview'],
    ['/portal/admin/tickets/', 'All tickets'],
    ['/portal/admin/licences/', 'Licence requests'],
    ['/portal/admin/releases/', 'Releases'],
    ['/portal/admin/users/', 'Users'],
    ['/portal/admin/messages/', 'Messages'],
    ['/portal/admin/chats/', 'Assistant chats'],
    ['/portal/admin/audit/', 'Audit log'],
] : [];
$path = $app->request()?->path ?? '/portal/';
$isCurrent = static function (string $href) use ($path): string {
    if ($href === $path) {
        return ' aria-current="page"';
    }
    if ($href !== '/portal/' && $href !== '/portal/admin/' && str_starts_with($path, $href)) {
        return ' aria-current="true"';
    }
    return '';
};
ob_start();
?>
<div class="pt-shell">
    <nav class="pt-nav" aria-label="Client portal">
        <ul>
            <?php foreach ($links as [$href, $label]): ?>
                <li><a href="<?= e($href) ?>"<?= $isCurrent($href) ?>><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($adminLinks !== []): ?>
            <p class="pt-nav-heading">Administration</p>
            <ul>
                <?php foreach ($adminLinks as [$href, $label]): ?>
                    <li><a href="<?= e($href) ?>"<?= $isCurrent($href) ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($user !== null): ?>
            <form method="post" action="/portal/logout/" class="pt-nav-signout">
                <?= csrf_field() ?>
                <p class="pt-small pt-muted">Signed in as <?= e($user['name']) ?></p>
                <button type="submit" class="pt-btn pt-btn--ghost pt-btn--small" data-busy="Signing out…">Sign out</button>
            </form>
        <?php endif; ?>
    </nav>
    <main class="pt-main" id="main">
        <?= $this->partial('flashes', ['flashes' => $flashes ?? []]) ?>
        <?= $content ?>
    </main>
</div>
<?php
$inner = (string) ob_get_clean();
echo $this->render('layouts/base', ['content' => $inner, 'bare' => true] + get_defined_vars());
