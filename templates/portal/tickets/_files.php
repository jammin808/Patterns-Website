<?php
/**
 * A list of ticket attachments, each a download link with its size.
 *
 * @var list<array{id: int|string, original_name: string, size: int|string}> $files
 * @var string $label what the list holds, for assistive technology
 */
$files ??= [];
if ($files === []) {
    return;
}
?>
<ul class="pt-files" aria-label="<?= e($label ?? 'Attached files') ?>">
    <?php foreach ($files as $f): ?>
        <li><a href="/portal/attachments/<?= (int) $f['id'] ?>/"><bdi><?= e($f['original_name']) ?></bdi></a> <span class="pt-small pt-muted"><?= e(filesize_human((int) $f['size'])) ?></span></li>
    <?php endforeach; ?>
</ul>
