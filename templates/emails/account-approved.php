<?php /** @var string $name @var string $siteName @var string $baseUrl */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">Your account is approved</h1>
<p>Hello <?= e($name) ?>,</p>
<p>Good news: your <?= e($siteName) ?> account is ready. You can now sign in to download the software, request licences and get support.</p>
<?= $this->render('emails/_button', ['url' => $baseUrl . '/portal/login/', 'label' => 'Sign in']) ?>
<p style="font-size:13px;color:#5b616e;">Forgotten your password? <a href="<?= e($baseUrl) ?>/portal/forgot/" style="color:#5b616e;">Reset it here</a>.</p>
