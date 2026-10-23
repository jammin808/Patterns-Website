<?php
/**
 * A password reset (or, for an account whose address was never confirmed, the email check it also does).
 *
 * @var string $name empty when the address is not yet proven (Auth::greetingName)
 * @var string $url @var string $siteName @var string $baseUrl
 * @var bool $digest the account is waiting to confirm its address and asked for the weekly digest at registration
 */
$digest ??= false;
?>
<h1 style="font-size:18px;margin:0 0 12px;">Reset your password</h1>
<p>Hello<?= ($name ?? '') !== '' ? ' ' . e($name) : '' ?>,</p>
<p>Someone asked to reset the password for your <?= e($siteName) ?> account. If it was you, choose a new password here:</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Choose a new password']) ?>
<p>The link works once, for one hour. When the new password is saved, any browsers signed in to your account are signed out.</p>
<?php if ($digest): ?>
<p>You also asked for the weekly Industry Updates digest when you created the account. Choosing a new password confirms your address, and then we will send one more email about the digest: it starts only when you press the button that email leads to.</p>
<?php endif ?>
<p style="font-size:13px;color:#5b616e;">If you did not ask for this, you can ignore this email. Your password stays as it is.</p>
