<?php
/**
 * To staff: a client opened a ticket.
 *
 * @var array<string, mixed> $ticket
 * @var string $typeLabel
 * @var string $productLabel
 * @var string $severityLabel
 * @var string $authorName
 * @var string $authorEmail
 * @var int $fileCount
 * @var string $url
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">New <?= e(mb_strtolower($typeLabel)) ?>: <?= e($ticket['title']) ?></h1>
<p style="margin:0 0 4px;"><strong>From:</strong> <?= e($authorName) ?> &lt;<?= e($authorEmail) ?>&gt;</p>
<p style="margin:0 0 4px;"><strong>Product:</strong> <?= e($productLabel) ?></p>
<?php if ($severityLabel !== ''): ?>
<p style="margin:0 0 4px;"><strong>Severity:</strong> <?= e($severityLabel) ?></p>
<?php endif; ?>
<?php if (!empty($ticket['app_version'])): ?>
<p style="margin:0 0 4px;"><strong>Version:</strong> <?= e($ticket['app_version']) ?></p>
<?php endif; ?>
<?php if (!empty($ticket['environment'])): ?>
<p style="margin:0 0 4px;"><strong>Windows and hardware:</strong> <?= e(str_replace("\n", ' · ', (string) $ticket['environment'])) ?></p>
<?php endif; ?>
<p style="margin:0 0 4px;"><strong>Reference:</strong> #<?= e($ticket['id']) ?> · <?= ($ticket['visibility'] ?? '') === 'public' ? 'public on the feature request board' : 'private' ?></p>
<?php if ($fileCount > 0): ?>
<p style="margin:0 0 4px;"><strong>Files:</strong> <?= e($fileCount) ?> attached</p>
<?php endif; ?>
<div style="margin-top:16px;border-left:3px solid #e3e5ea;padding-left:12px;"><?= md((string) $ticket['body']) ?></div>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Open the ticket']) ?>
