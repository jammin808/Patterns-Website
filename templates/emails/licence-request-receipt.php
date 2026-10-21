<?php
/**
 * To the client: we have their licence request, and what happens next.
 *
 * @var string $name
 * @var string $reference
 * @var string $productName
 * @var string $optionName
 * @var string $priceLabel
 * @var list<string> $hardwareIds
 * @var bool $nonprofit
 * @var int $quantity
 * @var string $paymentMode invoice | links
 * @var string|null $paymentLink
 * @var string $url the licence's page in the portal
 * @var string $baseUrl
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">We have your licence request</h1>
<p style="margin:0 0 12px;">Hello <?= e($name) ?>,</p>
<p style="margin:0 0 12px;">Thank you for requesting <?= e($productName) ?> <?= e($optionName) ?><?= $quantity > 1 ? ' (' . (int) $quantity . '-pack)' : '' ?>. Your reference is <strong><?= e($reference) ?></strong>.</p>
<p style="margin:0 0 4px;"><strong>Hardware ID<?= count($hardwareIds) > 1 ? 's' : '' ?>:</strong></p>
<ul style="margin:0 0 16px;padding-left:20px;font-family:Consolas,Menlo,monospace;font-size:14px;">
<?php foreach ($hardwareIds as $hw): ?>
<li><?= e($hw) ?></li>
<?php endforeach; ?>
</ul>
<h2 style="font-size:16px;margin:20px 0 8px;">What happens next</h2>
<?php if ($paymentLink !== null): ?>
<p style="margin:0 0 12px;">You can pay for your licence now with the link below<?= $priceLabel !== '' ? ' (' . e($priceLabel) . ')' : '' ?>. Once payment has arrived, we issue your licence file and email you when it is ready to download.</p>
<?= $this->render('emails/_button', ['url' => $paymentLink, 'label' => 'Pay for your licence']) ?>
<?php else: ?>
<p style="margin:0 0 12px;">We will email you the payment details<?= $nonprofit ? ', with your nonprofit pricing' : '' ?>. Once payment has arrived, we issue your licence file and email you when it is ready to download.</p>
<?php endif; ?>
<p style="margin:0 0 12px;">You can follow your request in the client portal at any time.</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'View your request']) ?>
<p style="margin:0;font-size:13px;color:#5b616e;">If a hardware ID is wrong, send the right one through <a href="<?= e($baseUrl) ?>/contact/?topic=licence" style="color:#5b616e;">the contact page</a> and quote <?= e($reference) ?>.</p>
