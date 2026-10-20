<?php /** @var string $name @var string $siteName @var string $baseUrl */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">You already have an account</h1>
<p>Hello <?= e($name) ?>,</p>
<p>Someone tried to create a <?= e($siteName) ?> account with this email address. It already has an account, so nothing new was set up.</p>
<p>If it was you, sign in with your existing password:</p>
<?= $this->render('emails/_button', ['url' => $baseUrl . '/portal/login/', 'label' => 'Sign in']) ?>
<p>Forgotten your password? <a href="<?= e($baseUrl) ?>/portal/forgot/">Reset it here</a>.</p>
<p style="font-size:13px;color:#5b616e;">If it was not you, you can ignore this email. Your account has not changed.</p>
