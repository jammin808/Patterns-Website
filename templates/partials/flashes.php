<?php
/**
 * The page's flash messages: each is the answer to what the visitor just did, so site.js gives the first one focus
 * on load (or reads it out, when the address's #fragment has put the visitor elsewhere on the page).
 *
 * @var list<array{type: string, message: string}> $flashes
 */
?>
<?php foreach ($flashes as $f):
    $type = in_array($f['type'], ['info', 'success', 'warning', 'danger'], true) ? $f['type'] : 'info'; ?>
    <div class="pt-alert pt-alert--<?= e($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>" tabindex="-1" data-feedback><?= e($f['message']) ?></div>
<?php endforeach; ?>
