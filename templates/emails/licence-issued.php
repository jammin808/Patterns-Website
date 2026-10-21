<?php
/**
 * To the client: their licence is issued and its file is ready in the portal.
 *
 * @var string $name
 * @var string $reference
 * @var string $productName
 * @var string $optionName
 * @var string $message from the administrator, may be empty
 * @var string $installHelp how the product installs a licence file, may be empty
 * @var string $url the licence's page in the portal
 * @var string $baseUrl
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">Your <?= e($productName) ?> licence is ready</h1>
<p style="margin:0 0 12px;">Hello <?= e($name) ?>,</p>
<p style="margin:0 0 12px;">Your <?= e($productName) ?> <?= e($optionName) ?> licence (reference <strong><?= e($reference) ?></strong>) is issued. Thank you. Sign in to the client portal to download your licence file.</p>
<?php if ($message !== ''): ?>
<div style="white-space:pre-wrap;border-left:3px solid #e3e5ea;padding-left:12px;margin:0 0 16px;"><?= e($message) ?></div>
<?php endif; ?>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Download your licence file']) ?>
<?php if ($installHelp !== ''): ?>
<p style="margin:0 0 12px;"><strong>To install it:</strong> <?= e($installHelp) ?></p>
<?php endif; ?>
<p style="margin:0;font-size:13px;color:#5b616e;">Keep the licence file somewhere safe. If you move to a new PC, send its hardware ID through <a href="<?= e($baseUrl) ?>/contact/?topic=licence" style="color:#5b616e;">the contact page</a> and quote <?= e($reference) ?>.</p>
