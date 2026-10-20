<?php /** @var string $name @var string $url @var string $siteName @var string $baseUrl */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">Reset your password</h1>
<p>Hello <?= e($name) ?>,</p>
<p>Someone asked to reset the password for your <?= e($siteName) ?> account. If it was you, choose a new password here:</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Choose a new password']) ?>
<p>The link works once, for one hour. When the new password is saved, any browsers signed in to your account are signed out.</p>
<p style="font-size:13px;color:#5b616e;">If you did not ask for this, you can ignore this email. Your password stays as it is.</p>
