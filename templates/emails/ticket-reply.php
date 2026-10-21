<?php
/**
 * To a ticket's owner: someone on the team replied (never sent for an internal note).
 *
 * @var string $name
 * @var array<string, mixed> $ticket
 * @var string $typeLabel
 * @var string $staffName
 * @var string $body
 * @var string $url
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">New reply on your <?= e(mb_strtolower($typeLabel)) ?></h1>
<p>Hello <?= e($name) ?>,</p>
<p><?= e($staffName) ?> from the team replied to <strong>#<?= e($ticket['id']) ?> <?= e($ticket['title']) ?></strong>:</p>
<div style="border-left:3px solid #e3e5ea;padding-left:12px;"><?= md($body) ?></div>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Read and reply']) ?>
<p style="font-size:13px;color:#5b616e;">Please reply in the portal rather than to this email, so the whole conversation stays in one place.</p>
