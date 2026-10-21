<?php
/**
 * @var int $id
 * @var string $name the name the account had before it was deleted
 * @var string $email the email address the account had before it was deleted
 * @var string $company
 * @var array{licences: int, tickets: int, comments: int} $kept
 * @var string $siteName
 * @var string $baseUrl
 */
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
?>
<h1 style="font-size:18px;margin:0 0 12px;">An account was deleted</h1>
<p>The owner of account #<?= e($id) ?> deleted it from their account page. Their name, email address, company and password have been removed and the account can no longer sign in.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:15px;line-height:1.6;">
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Was</td><td><?= e($name) ?> &lt;<?= e($email) ?>&gt;<?= $company !== '' ? ' · ' . e($company) : '' ?></td></tr>
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Account</td><td>#<?= e($id) ?>, now shown as &ldquo;Deleted user&rdquo;</td></tr>
</table>
<p><strong>Kept as business records:</strong> <?= e($plural($kept['licences'], 'licence', 'licences')) ?>, <?= e($plural($kept['tickets'], 'ticket', 'tickets')) ?> and <?= e($plural($kept['comments'], 'comment', 'comments')) ?>. They stay linked to account #<?= e($id) ?> and show &ldquo;Deleted user&rdquo; as the author.</p>
<p><a href="<?= e($baseUrl) ?>/portal/admin/users/<?= e($id) ?>/">See the account</a></p>
