<?php /** @var string $name @var string $url @var string $siteName @var string $baseUrl */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">Your <?= e($siteName) ?> account is ready</h1>
<p>Hello <?= e($name) ?>,</p>
<p>We have set up a client portal account for you. It is where you download the software, request and collect licences, and report bugs or suggest features.</p>
<p>To start, choose a password:</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Set my password']) ?>
<p>The link works once, for seven days. If it has expired, use <a href="<?= e($baseUrl) ?>/portal/forgot/">Forgotten your password?</a> with this email address to get a new one.</p>
<p style="font-size:13px;color:#5b616e;">If you were not expecting this, you can ignore it. Nothing happens until a password is set.</p>
