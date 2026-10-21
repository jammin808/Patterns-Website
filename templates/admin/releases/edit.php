<?php
/**
 * One release: its file and numbers, publish or unpublish, its details, and deleting it.
 *
 * @var array<string, mixed> $release
 * @var array<string, mixed>|null $creator
 * @var int $recentDownloads in the last 30 days
 * @var bool $fileMissing
 * @var array<string, string> $products
 * @var array<string, string> $channels
 * @var array<string, string> $access
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$id = (int) $release['id'];
$published = (int) $release['is_published'] === 1;
$name = ($products[(string) $release['product']] ?? (string) $release['product']) . ' ' . (string) $release['version'];
$channel = (string) $release['channel'];
$time = fn (mixed $ts): string => $this->render('portal/licences/_time', ['ts' => $ts]);
$values = [];
foreach (['product', 'version', 'channel', 'title', 'notes', 'access'] as $k) {
    $values[$k] = array_key_exists($k, $old) ? $old[$k] : (string) ($release[$k] ?? '');
}
$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title"><?= e($name) ?></h1>
    <p class="pt-lede">
        <span class="pt-badge pt-badge--<?= $published ? 'public' : 'private' ?>"><?= $published ? 'Published' : 'Draft' ?></span>
        <span class="pt-badge pt-badge--<?= e($channel) ?>"><?= e($channels[$channel] ?? $channel) ?></span>
        <?= e($release['title']) ?>
    </p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/releases/">Back to releases</a>
        <?php if (!$fileMissing): ?><a class="pt-btn pt-btn--secondary" href="/portal/downloads/<?= $id ?>/" download>Download the file</a><?php endif; ?>
    </div>
</header>

<?php if ($fileMissing): ?>
    <div class="pt-alert pt-alert--danger" role="alert">The file for this release is missing from the downloads folder, so nobody can download it. Delete this release and add it again with the file.</div>
<?php endif; ?>

<section class="pt-section" aria-labelledby="release-file">
    <h2 class="pt-h2" id="release-file">File and downloads</h2>
    <dl class="pt-kv">
        <dt>File</dt>
        <dd class="pt-mono"><?= e($release['file_name']) ?></dd>
        <dt>Size</dt>
        <dd><?= e(filesize_human((int) $release['file_size'])) ?></dd>
        <dt>SHA-256</dt>
        <dd class="pt-mono pt-small"><?= e($release['sha256'] ?? '') ?></dd>
        <dt>Downloads</dt>
        <dd><?= (int) $release['download_count'] ?> in all, <?= $recentDownloads ?> in the last 30 days</dd>
        <dt>Added</dt>
        <dd><?= $time($release['created_at']) ?><?= $creator !== null ? ' by ' . e($creator['name']) : '' ?></dd>
        <dt>Published</dt>
        <dd><?= $release['published_at'] !== null ? $time($release['published_at']) . ($published ? '' : ' <span class="pt-muted">(unpublished since)</span>') : '<span class="pt-muted">Not yet</span>' ?></dd>
    </dl>
    <form method="post" action="/portal/admin/releases/<?= $id ?>/" class="pt-actions">
        <?= csrf_field() ?>
        <?php if ($published): ?>
            <input type="hidden" name="action" value="unpublish">
            <button type="submit" class="pt-btn pt-btn--secondary">Unpublish</button>
            <span class="pt-small pt-muted">Clients stop seeing it and can no longer download it.</span>
        <?php else: ?>
            <input type="hidden" name="action" value="publish">
            <button type="submit" class="pt-btn pt-btn--primary"<?= $fileMissing ? ' disabled' : '' ?>>Publish</button>
            <span class="pt-small pt-muted">Clients see it in Downloads straight away.</span>
        <?php endif; ?>
    </form>
</section>

<section class="pt-section" aria-labelledby="release-details" id="details">
    <h2 class="pt-h2" id="release-details">Details</h2>
    <form class="pt-form" method="post" action="/portal/admin/releases/<?= $id ?>/">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?= $this->render('admin/releases/_meta', ['values' => $values, 'errors' => $errors, 'products' => $products, 'channels' => $channels, 'access' => $access]) ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--primary">Save changes</button>
        </div>
    </form>
</section>

<section class="pt-section" aria-labelledby="release-delete" id="delete">
    <h2 class="pt-h2" id="release-delete">Delete this release</h2>
    <p>Deleting removes the release, its download history and its file for good. To hide it for now, unpublish it instead.</p>
    <form class="pt-form" method="post" action="/portal/admin/releases/<?= $id ?>/">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <?= $field([
            'name' => 'confirm',
            'kind' => 'checkbox',
            'id' => 'f-delete-confirm',
            'label' => 'Yes, delete ' . $name . ' and its file',
            'error' => $errors['confirm'] ?? null,
        ]) ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--danger">Delete release</button>
        </div>
    </form>
</section>
