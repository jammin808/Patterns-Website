<?php /** @var string $name @var string $email @var string $company @var string $countryName @var string $topicLabel @var string $product @var string $message @var int $id */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">New website message</h1>
<p style="margin:0 0 4px;"><strong>From:</strong> <?= e($name) ?> &lt;<?= e($email) ?>&gt;<?= $company !== '' ? ' · ' . e($company) : '' ?><?= ($countryName ?? '') !== '' ? ' · ' . e($countryName) : '' ?></p>
<p style="margin:0 0 4px;"><strong>About:</strong> <?= e($topicLabel) ?><?= $product !== '' ? ' · ' . e($product) : '' ?></p>
<p style="margin:0 0 16px;"><strong>Reference:</strong> #<?= e($id) ?></p>
<div style="white-space:pre-wrap;border-left:3px solid #e3e5ea;padding-left:12px;"><?= e($message) ?></div>
<p style="margin-top:20px;">Reply to this email to answer them directly, or see it in <a href="<?= e($baseUrl) ?>/portal/admin/messages/">the admin inbox</a>.</p>
