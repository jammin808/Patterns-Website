<?php
/**
 * To a ticket's owner: its status changed. $reply is the staff message that came with the change, and is empty
 * when there was none or it was an internal note (an internal note is never emailed).
 *
 * @var string $name
 * @var array<string, mixed> $ticket
 * @var string $typeLabel
 * @var string $oldLabel
 * @var string $newLabel
 * @var string $statusHelp
 * @var string $staffName
 * @var string $reply
 * @var string $url
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">Your <?= e(mb_strtolower($typeLabel)) ?> is now <?= e(mb_strtolower($newLabel)) ?></h1>
<p>Hello <?= e($name) ?>,</p>
<p><strong>#<?= e($ticket['id']) ?> <?= e($ticket['title']) ?></strong> moved from <?= e($oldLabel) ?> to <strong><?= e($newLabel) ?></strong>.</p>
<p><?= e($statusHelp) ?></p>
<?php if ($reply !== ''): ?>
<p><?= e($staffName) ?> added:</p>
<div style="border-left:3px solid #e3e5ea;padding-left:12px;"><?= md($reply) ?></div>
<?php endif; ?>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'View the ticket']) ?>
<p style="font-size:13px;color:#5b616e;">To add anything, reply in the portal rather than to this email.</p>
