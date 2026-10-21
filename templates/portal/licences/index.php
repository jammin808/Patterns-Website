<?php
/**
 * My licences: every request and licence, the newest first; an issued licence lists its files.
 *
 * @var list<array<string, mixed>> $licences
 * @var array<int, list<array<string, mixed>>> $files licence id => files (issued licences only)
 * @var array<string, string> $statuses
 * @var array<string, string> $products
 * @var array<string, array<string, string>> $options product => tier => name
 * @var bool $canRequest
 */
$time = fn (mixed $ts): string => $this->render('portal/licences/_time', ['ts' => $ts]);
?>
<header class="pt-header">
    <p class="pt-eyebrow">Client portal</p>
    <h1 class="pt-title">Licences</h1>
    <p class="pt-lede">Your licence requests and licences. Once a licence is issued, its licence file is here to download.</p>
    <?php if ($canRequest): ?>
        <div class="pt-header-actions">
            <a class="pt-btn pt-btn--primary" href="/portal/licences/request/">Request a licence</a>
        </div>
    <?php endif; ?>
</header>

<?php if ($licences === []): ?>
    <div class="pt-empty">
        <p>You have no licences yet. Request one with your PC’s hardware ID, and your licence file will appear here once it is issued.</p>
        <?php if ($canRequest): ?>
            <a class="pt-btn pt-btn--primary" href="/portal/licences/request/">Request a licence</a>
        <?php endif; ?>
        <a class="pt-btn pt-btn--ghost" href="/licensing/">Compare licences</a>
    </div>
<?php else: ?>
    <div class="pt-grid pt-grid--2">
        <?php foreach ($licences as $l):
            $id = (int) $l['id'];
            $status = (string) $l['status'];
            $product = (string) $l['product'];
            $name = ($products[$product] ?? ucfirst($product)) . ' ' . ($options[$product][(string) $l['tier']] ?? (string) $l['tier']);
            $licenceFiles = $files[$id] ?? []; ?>
            <article class="pt-card" aria-labelledby="licence-<?= $id ?>">
                <h2 class="pt-card-title" id="licence-<?= $id ?>"><a href="/portal/licences/<?= $id ?>/"><?= e($name) ?></a></h2>
                <p class="pt-card-meta">
                    <span class="pt-badge pt-badge--<?= e($status) ?>"><?= e($statuses[$status] ?? $status) ?></span>
                    <span class="pt-mono"><?= e($l['reference']) ?></span>
                </p>
                <div class="pt-card-body">
                    <dl class="pt-kv">
                        <dt>Requested</dt>
                        <dd><?= $time($l['created_at']) ?></dd>
                        <?php if ($l['issued_at'] !== null): ?>
                            <dt>Issued</dt>
                            <dd><?= $time($l['issued_at']) ?></dd>
                        <?php endif; ?>
                        <?php if ((int) $l['quantity'] > 1): ?>
                            <dt>Licences</dt>
                            <dd><?= (int) $l['quantity'] ?></dd>
                        <?php endif; ?>
                        <?php if ($l['expires_at'] !== null): ?>
                            <dt>Ends</dt>
                            <dd><?= $time($l['expires_at']) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($status === 'issued' && $licenceFiles !== []): ?>
                        <ul class="pt-files" aria-label="Licence files for <?= e($name) ?>">
                            <?php foreach ($licenceFiles as $f): ?>
                                <li><a href="/portal/licences/<?= $id ?>/files/<?= (int) $f['id'] ?>/" download><?= e($f['original_name']) ?></a> <span class="pt-small pt-muted"><?= e(filesize_human((int) $f['size'])) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="pt-card-footer">
                    <a class="pt-btn pt-btn--ghost pt-btn--small" href="/portal/licences/<?= $id ?>/">View details<span class="u-visually-hidden"> of <?= e($l['reference']) ?></span></a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
