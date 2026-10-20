<?php /** @var string $name @var string $url @var string $siteName @var string $baseUrl */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">Confirm your email address</h1>
<p>Hello <?= e($name) ?>,</p>
<p>Thanks for creating a <?= e($siteName) ?> account. Please confirm this is your email address to finish setting it up.</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Confirm my email address']) ?>
<p>The link works once, for 48 hours. If it has expired, sign in and we will offer to send a new one.</p>
<p style="font-size:13px;color:#5b616e;">If you did not create an account, you can ignore this email. Nothing will be set up without the link.</p>
