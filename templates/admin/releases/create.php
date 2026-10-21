<?php
/**
 * Adding a release: its details, then the file, uploaded here or picked from the vault's incoming folder.
 *
 * @var array<string, string> $products
 * @var array<string, string> $channels
 * @var array<string, string> $access
 * @var list<array{name: string, size: int, modified: int, allowed: bool}> $incoming
 * @var list<string> $extensions
 * @var int $maxBytes
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
$values = [];
foreach (['product', 'version', 'channel', 'title', 'notes', 'access'] as $k) {
    $values[$k] = $old[$k] ?? '';
}
$usable = array_values(array_filter($incoming, static fn (array $f): bool => $f['allowed']));
$others = count($incoming) - count($usable);
$accept = implode(',', array_map(static fn (string $x): string => '.' . $x, $extensions));
$types = implode(', ', array_map('strtoupper', $extensions));
$options = ['' => 'Do not use the incoming folder'];
foreach ($usable as $f) {
    $options[$f['name']] = $f['name'] . ' (' . filesize_human($f['size']) . ', ' . date_short($f['modified']) . ')';
}
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Add a release</h1>
    <p class="pt-lede">Describe the release and add its file. Save it as a draft to check it first, or publish it now.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/releases/">Back to releases</a>
    </div>
</header>

<form class="pt-form" method="post" action="/portal/admin/releases/" enctype="multipart/form-data" id="form">
    <?= csrf_field() ?>

    <fieldset class="pt-fieldset">
        <legend>Release</legend>
        <?= $this->render('admin/releases/_meta', ['values' => $values, 'errors' => $errors, 'products' => $products, 'channels' => $channels, 'access' => $access]) ?>
    </fieldset>

    <?php
    // An incoming-folder error with no list to show it beside (the file was moved or removed meanwhile) is shown
    // on the upload control, the only control left in this group.
    $fileError = $errors['file'] ?? ($usable === [] ? ($errors['incoming'] ?? null) : null);
    ?>
    <fieldset class="pt-fieldset">
        <legend>File</legend>
        <p class="pt-help">Upload the file here, or pick one you placed in the <span class="pt-mono">downloads-vault/incoming/</span> folder by FTP or the File Manager. The incoming folder suits large files. Allowed: <?= e($types) ?>.</p>
        <?= $field([
            'name' => 'file',
            'kind' => 'file',
            'label' => 'Upload a file',
            'error' => $fileError,
            'help' => 'Up to ' . filesize_human($maxBytes) . '.',
            // The browser checks the size before sending, so an over-large file is never uploaded only to be refused.
            'attrs' => ['accept' => $accept, 'data-max-bytes' => $maxBytes, 'data-max-message' => 'This file is larger than ' . filesize_human($maxBytes) . '. Upload it to the downloads-vault/incoming/ folder with the File Manager or FTP, then pick it from the list.'],
            'markOptional' => false,
        ]) ?>
        <?php if ($usable !== []): ?>
            <?= $field([
                'name' => 'incoming',
                'kind' => 'select',
                'label' => 'Or pick from the incoming folder',
                'value' => $old['incoming'] ?? '',
                'error' => $errors['incoming'] ?? null,
                'options' => $options,
                'help' => 'The file is moved out of the incoming folder when the release is saved.',
                'markOptional' => false,
            ]) ?>
        <?php else: ?>
            <p class="pt-small pt-muted">The incoming folder has no files that can be used<?= $others > 0 ? ' (' . $others . ' other file' . ($others === 1 ? '' : 's') . ' of a type that is not allowed)' : '' ?>. Files placed there appear in a list here.</p>
        <?php endif; ?>
    </fieldset>

    <?= $field([
        'name' => 'publish',
        'kind' => 'checkbox',
        'label' => 'Publish now, so clients see it in Downloads straight away',
        'checked' => ($old['publish'] ?? '') === '1',
        'error' => $errors['publish'] ?? null,
        'help' => 'Leave this clear to save a draft. Only administrators can download a draft.',
    ]) ?>

    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--primary">Save release</button>
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/releases/">Cancel</a>
    </div>
</form>
