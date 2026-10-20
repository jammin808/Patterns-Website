<?php /** @var list<array{type: string, message: string}> $flashes */ ?>
<?php foreach ($flashes as $f):
    $type = in_array($f['type'], ['info', 'success', 'warning', 'danger'], true) ? $f['type'] : 'info'; ?>
    <div class="pt-alert pt-alert--<?= e($type) ?>" role="<?= $type === 'danger' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
