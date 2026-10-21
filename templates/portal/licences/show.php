<?php
/**
 * One licence for its owner: the request, where it has got to, and the licence files once it is issued.
 *
 * @var array<string, mixed> $licence
 * @var string $productName
 * @var string $optionName
 * @var list<string> $hardwareIds
 * @var array<string, string> $statuses
 * @var array<string, string> $statusHelp
 * @var list<array<string, mixed>> $files shown only when issued
 * @var int $hiddenFiles files an administrator can see that the client cannot yet
 * @var string|null $installHelp
 * @var string|null $paymentLink
 * @var bool $isAdmin
 */
$id = (int) $licence['id'];
$status = (string) $licence['status'];
$time = fn (mixed $ts): string => $this->render('portal/licences/_time', ['ts' => $ts]);
$labels = count($hardwareIds) === 2 ? ['Main PC', 'Backup PC'] : [];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Licences</p>
    <h1 class="pt-title"><?= e($productName) ?> <?= e($optionName) ?></h1>
    <p class="pt-lede">Reference <span class="pt-mono"><?= e($licence['reference']) ?></span> · <span class="pt-badge pt-badge--<?= e($status) ?>"><?= e($statuses[$status] ?? $status) ?></span></p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/licences/">All your licences</a>
        <?php if ($isAdmin): ?><a class="pt-btn pt-btn--secondary" href="/portal/admin/licences/<?= $id ?>/">Manage this licence</a><?php endif; ?>
    </div>
</header>

<div class="pt-alert pt-alert--<?= $status === 'issued' ? 'success' : ($status === 'revoked' ? 'warning' : 'info') ?>" role="status">
    <?= e($statusHelp[$status] ?? '') ?>
</div>

<?php if ($paymentLink !== null): ?>
    <div class="pt-actions">
        <a class="pt-btn pt-btn--primary" href="<?= e($paymentLink) ?>" rel="noopener">Pay for this licence</a>
    </div>
<?php endif; ?>

<?php if ($status === 'issued'): ?>
    <section class="pt-section" aria-labelledby="licence-files">
        <h2 class="pt-h2" id="licence-files">Licence file<?= count($files) === 1 ? '' : 's' ?></h2>
        <?php if ($files === []): ?>
            <p>Your licence file is on its way and will appear here. You do not need to request it again.</p>
        <?php else: ?>
            <ul class="pt-files" aria-label="Licence files">
                <?php foreach ($files as $f): ?>
                    <li><a href="/portal/licences/<?= $id ?>/files/<?= (int) $f['id'] ?>/" download><?= e($f['original_name']) ?></a> <span class="pt-small pt-muted"><?= e(filesize_human((int) $f['size'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($installHelp !== null): ?><p><?= e($installHelp) ?></p><?php endif; ?>
        <?php endif; ?>
    </section>
<?php elseif ($isAdmin && $hiddenFiles > 0): ?>
    <p class="pt-small pt-muted"><?= $hiddenFiles === 1 ? 'One file is' : $hiddenFiles . ' files are' ?> attached. The client sees them once the licence is issued.</p>
<?php endif; ?>

<section class="pt-section" aria-labelledby="licence-timeline">
    <h2 class="pt-h2" id="licence-timeline">Timeline</h2>
    <dl class="pt-kv">
        <dt>Requested</dt>
        <dd><?= $time($licence['created_at']) ?></dd>
        <?php if ($licence['issued_at'] !== null): ?>
            <dt>Issued</dt>
            <dd><?= $time($licence['issued_at']) ?></dd>
        <?php endif; ?>
        <dt>Status</dt>
        <dd><?= e($statuses[$status] ?? $status) ?><?php if ((int) $licence['updated_at'] > (int) $licence['created_at']): ?> <span class="pt-muted">(last updated <?= $this->render('portal/licences/_time', ['ts' => $licence['updated_at'], 'format' => 'ago']) ?>)</span><?php endif; ?></dd>
        <?php if ($licence['updates_until'] !== null): ?>
            <dt>Updates until</dt>
            <dd><?= $time($licence['updates_until']) ?></dd>
        <?php endif; ?>
        <?php if ($licence['expires_at'] !== null): ?>
            <dt>Ends</dt>
            <dd><?= $time($licence['expires_at']) ?></dd>
        <?php endif; ?>
    </dl>
</section>

<section class="pt-section" aria-labelledby="licence-request">
    <h2 class="pt-h2" id="licence-request">Your request</h2>
    <dl class="pt-kv">
        <dt>Product</dt>
        <dd><?= e($productName) ?></dd>
        <dt>Licence</dt>
        <dd><?= e($optionName) ?><?= (int) $licence['quantity'] > 1 ? ' × ' . (int) $licence['quantity'] : '' ?></dd>
        <?php foreach ($hardwareIds as $i => $hw): ?>
            <dt><?= e(($labels[$i] ?? (count($hardwareIds) > 1 ? 'PC ' . ($i + 1) : 'PC')) . ' hardware ID') ?></dt>
            <dd class="pt-mono"><?= e($hw) ?></dd>
        <?php endforeach; ?>
        <dt>Nonprofit pricing</dt>
        <dd><?= (int) $licence['nonprofit'] === 1 ? 'Yes' : 'No' ?></dd>
    </dl>
    <?php if (trim((string) ($licence['customer_note'] ?? '')) !== ''): ?>
        <h3>Your note</h3>
        <div class="pt-prose"><?= md((string) $licence['customer_note']) ?></div>
    <?php endif; ?>
    <p class="pt-small pt-muted">Something wrong, or a new PC? <a href="/contact/?topic=licence">Get in touch</a> and quote <?= e($licence['reference']) ?>.</p>
</section>
