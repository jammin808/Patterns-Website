<?php
/**
 * To the client: their licence moved to a new status (other than issued, which has its own email).
 *
 * @var string $name
 * @var string $reference
 * @var string $productName
 * @var string $optionName
 * @var string $status
 * @var string $statusLabel
 * @var string $statusHelp
 * @var string $message from the administrator, may be empty
 * @var string|null $paymentLink when awaiting payment and a link applies
 * @var string $url the licence's page in the portal
 * @var string $baseUrl
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">Your licence request: <?= e(mb_strtolower($statusLabel)) ?></h1>
<p style="margin:0 0 12px;">Hello <?= e($name) ?>,</p>
<p style="margin:0 0 12px;">Your request for <?= e($productName) ?> <?= e($optionName) ?> (reference <strong><?= e($reference) ?></strong>) is now <strong><?= e(mb_strtolower($statusLabel)) ?></strong>.</p>
<?php if ($statusHelp !== ''): ?>
<p style="margin:0 0 12px;"><?= e($statusHelp) ?></p>
<?php endif; ?>
<?php if ($message !== ''): ?>
<div style="white-space:pre-wrap;border-left:3px solid #e3e5ea;padding-left:12px;margin:0 0 16px;"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($paymentLink !== null): ?>
<?= $this->render('emails/_button', ['url' => $paymentLink, 'label' => 'Pay for your licence']) ?>
<?php endif; ?>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'View your licence']) ?>
<p style="margin:0;font-size:13px;color:#5b616e;">Questions? Use <a href="<?= e($baseUrl) ?>/contact/?topic=licence" style="color:#5b616e;">the contact page</a> and quote <?= e($reference) ?>.</p>
