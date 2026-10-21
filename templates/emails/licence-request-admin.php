<?php
/**
 * To the administrators: a client asked for a licence.
 *
 * @var string $reference
 * @var string $productName
 * @var string $optionName
 * @var string $priceLabel
 * @var list<string> $hardwareIds
 * @var bool $nonprofit
 * @var string $organisation
 * @var int $quantity
 * @var string $note
 * @var string $name
 * @var string $email
 * @var string $url the request's admin page
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">New licence request</h1>
<p style="margin:0 0 4px;"><strong>Reference:</strong> <?= e($reference) ?></p>
<p style="margin:0 0 4px;"><strong>Licence:</strong> <?= e($productName) ?> <?= e($optionName) ?><?= $priceLabel !== '' ? ' · ' . e($priceLabel) : '' ?><?= $quantity > 1 ? ' · ' . (int) $quantity . '-pack' : '' ?></p>
<p style="margin:0 0 4px;"><strong>From:</strong> <?= e($name) ?> &lt;<?= e($email) ?>&gt;</p>
<?php if ($nonprofit): ?>
<p style="margin:0 0 4px;"><strong>Nonprofit:</strong> <?= e($organisation) ?></p>
<?php endif; ?>
<p style="margin:12px 0 4px;"><strong>Hardware ID<?= count($hardwareIds) > 1 ? 's' : '' ?>:</strong></p>
<ul style="margin:0 0 16px;padding-left:20px;font-family:Consolas,Menlo,monospace;font-size:14px;">
<?php foreach ($hardwareIds as $hw): ?>
<li><?= e($hw) ?></li>
<?php endforeach; ?>
</ul>
<?php if ($note !== ''): ?>
<div style="white-space:pre-wrap;border-left:3px solid #e3e5ea;padding-left:12px;margin:0 0 16px;"><?= e($note) ?></div>
<?php endif; ?>
<p style="margin:0;">Reply to this email to write to the client directly. Change the status and add the licence file on the request’s page.</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Open the request']) ?>
