<?php /** @var array<string, mixed> $user @var string $siteName @var string $baseUrl */ ?>
<h1 style="font-size:18px;margin:0 0 12px;">An account is waiting for approval</h1>
<p>Someone created an account and confirmed their email address. They cannot sign in until it is approved.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:15px;line-height:1.6;">
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Name</td><td><?= e($user['name'] ?? '') ?></td></tr>
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Email</td><td><?= e($user['email'] ?? '') ?></td></tr>
<?php if (!empty($user['company'])): ?>
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Company</td><td><?= e($user['company']) ?></td></tr>
<?php endif; ?>
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Account</td><td>#<?= e((int) ($user['id'] ?? 0)) ?></td></tr>
</table>
<?= $this->render('emails/_button', ['url' => $baseUrl . '/portal/admin/users/' . (int) ($user['id'] ?? 0) . '/', 'label' => 'Review the account']) ?>
