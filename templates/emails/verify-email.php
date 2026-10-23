<?php
/**
 * The email check. Its link opens a page with one button, and only that button confirms the address.
 *
 * @var string $name empty when the address is not yet proven (Auth::greetingName)
 * @var string $url @var string $siteName @var string $baseUrl
 * @var bool $digest the registration asked for the weekly digest, which has a confirmation of its own
 */
$digest ??= false;
?>
<h1 style="font-size:18px;margin:0 0 12px;">Confirm your email address</h1>
<p>Hello<?= ($name ?? '') !== '' ? ' ' . e($name) : '' ?>,</p>
<p>Thanks for creating a <?= e($siteName) ?> account. Please confirm this is your email address to finish setting it up: press the button below, then the button on the page it opens.</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Confirm my email address']) ?>
<p>The link works once, for 48 hours. If it has expired, sign in and we will offer to send a new one.</p>
<?php if ($digest): ?>
<p>You also asked for the weekly Industry Updates digest. Once your address is confirmed we will send one more email about it, and the digest starts only when you press the button that email leads to.</p>
<?php endif ?>
<p style="font-size:13px;color:#5b616e;">If you did not create an account, you can ignore this email. The account stays inactive unless someone presses the button on the page the link opens.</p>
