<?php
/**
 * Downloads: the published releases of each product, the newest first; the latest of each is highlighted, and
 * earlier versions follow it.
 *
 * @var array<string, list<array<string, mixed>>> $groups product key => releases, ecosystem order
 * @var array<string, array{name: string, licence_url: string, licence_label: string}> $products
 * @var array<string, string> $channels
 */
?>
<header class="pt-header">
    <p class="pt-eyebrow">Client portal</p>
    <h1 class="pt-title">Downloads</h1>
    <p class="pt-lede">The latest versions of each product, with release notes and earlier versions below them.</p>
    <?php if (count($groups) > 1): ?>
        <div class="pt-header-actions">
            <?php foreach ($products as $key => $p): ?>
                <a class="pt-btn pt-btn--ghost pt-btn--small" href="#<?= e($key) ?>"><?= e($p['name']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</header>

<?php if ($groups === []): ?>
    <div class="pt-empty">
        <p>There is nothing to download just yet. New versions appear here as soon as they are released.</p>
        <a class="pt-btn pt-btn--secondary" href="/portal/">Back to the dashboard</a>
    </div>
<?php else: ?>
    <?php foreach ($groups as $key => $releases):
        $name = $products[$key]['name']; ?>
        <section class="pt-section" id="<?= e($key) ?>" aria-labelledby="downloads-<?= e($key) ?>">
            <h2 class="pt-h2" id="downloads-<?= e($key) ?>"><?= e($name) ?></h2>
            <?= $this->render('portal/downloads/_release', ['r' => $releases[0], 'productName' => $name, 'product' => $products[$key], 'channels' => $channels, 'latest' => true]) ?>
            <?php if (count($releases) > 1): ?>
                <p class="pt-small pt-muted">Earlier versions of <?= e($name) ?>, the newest first:</p>
                <div class="pt-grid pt-grid--2">
                    <?php foreach (array_slice($releases, 1) as $r): ?>
                        <?= $this->render('portal/downloads/_release', ['r' => $r, 'productName' => $name, 'product' => $products[$key], 'channels' => $channels, 'latest' => false]) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="pt-section" aria-labelledby="downloads-verify">
        <h2 class="pt-h2" id="downloads-verify">Checking a download</h2>
        <p>Each file is listed with its SHA-256 checksum, a fingerprint of the file. If the checksum your computer works out for your copy matches the one shown here, the file arrived complete and unchanged. On Windows, PowerShell’s Get-FileHash shows a file’s SHA-256.</p>
        <p class="pt-small pt-muted">If a download stops part way, your browser can usually resume it from its downloads list.</p>
    </section>
<?php endif; ?>
