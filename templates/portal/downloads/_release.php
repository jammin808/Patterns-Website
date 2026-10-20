<?php
/**
 * One published release as a card: what it is, its notes, the file's size and SHA-256, and the action this
 * account has (a download button, or the way to get the licence it needs).
 *
 * @var array<string, mixed> $r a releases row plus may_download
 * @var string $productName
 * @var array{name: string, licence_url: string, licence_label: string} $product
 * @var array<string, string> $channels
 * @var bool $latest the newest release of its product
 */
$channel = (string) $r['channel'];
$label = $productName . ' ' . (string) $r['version'];
$sha = (string) ($r['sha256'] ?? '');
$titleId = 'release-' . (int) $r['id'];
?>
<article class="pt-card" aria-labelledby="<?= e($titleId) ?>">
    <h3 class="pt-card-title" id="<?= e($titleId) ?>"><?= e($label) ?><?php if ((string) $r['title'] !== '' && (string) $r['title'] !== $label): ?>: <?= e($r['title']) ?><?php endif; ?></h3>
    <p class="pt-card-meta">
        <?php if ($latest): ?><span class="pt-badge pt-badge--new">Latest</span><?php endif; ?>
        <span class="pt-badge pt-badge--<?= e($channel) ?>"><?= e($channels[$channel] ?? $channel) ?></span>
        <?php if ($r['access'] === 'licensed'): ?><span class="pt-badge pt-badge--private">Licence holders</span><?php endif; ?>
        <span class="pt-muted">Released <?= $this->render('portal/licences/_time', ['ts' => $r['published_at']]) ?></span>
    </p>
    <div class="pt-card-body">
        <?php if (trim((string) ($r['notes'] ?? '')) !== ''): ?>
            <div class="pt-prose"><?= md((string) $r['notes']) ?></div>
        <?php endif; ?>
        <dl class="pt-kv">
            <dt>File</dt>
            <dd class="pt-mono"><?= e($r['file_name']) ?></dd>
            <dt>Size</dt>
            <dd><?= e(filesize_human((int) $r['file_size'])) ?></dd>
            <?php if ($sha !== ''): ?>
                <dt><abbr title="A fingerprint of the file, to check your copy arrived complete and unchanged">SHA-256</abbr></dt>
                <dd class="pt-mono pt-small"><?= e($sha) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
    <div class="pt-card-footer">
        <?php if ($r['may_download']): ?>
            <div class="pt-actions">
                <a class="pt-btn <?= $latest ? 'pt-btn--primary' : 'pt-btn--secondary' ?>" href="/portal/downloads/<?= (int) $r['id'] ?>/" download>Download<span class="u-visually-hidden"> <?= e($label) ?></span> <span class="pt-small">(<?= e(filesize_human((int) $r['file_size'])) ?>)</span></a>
            </div>
        <?php else: ?>
            <p class="pt-small">This build is for <?= e($productName) ?> licence holders.</p>
            <div class="pt-actions">
                <a class="pt-btn pt-btn--secondary" href="<?= e($product['licence_url']) ?>"><?= e($product['licence_label']) ?><span class="u-visually-hidden"> for <?= e($productName) ?></span></a>
            </div>
        <?php endif; ?>
    </div>
</article>
