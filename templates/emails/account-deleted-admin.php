<?php
/**
 * @var int $id
 * @var string $name the name the account had before it was deleted
 * @var string $email the email address the account had before it was deleted
 * @var string $company
 * @var array{licences: int, tickets: int, comments: int} $kept ticket comments among them
 * @var array{news_comments: int, news_reports: int}|null $removed what went for good with the account
 * @var string $siteName
 * @var string $baseUrl
 */
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$removed ??= ['news_comments' => 0, 'news_reports' => 0];
?>
<h1 style="font-size:18px;margin:0 0 12px;">An account was deleted</h1>
<p>The owner of account #<?= e($id) ?> deleted it from their account page. Their name, email address, company and password have been removed and the account can no longer sign in.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:15px;line-height:1.6;">
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Was</td><td><?= e($name) ?> &lt;<?= e($email) ?>&gt;<?= $company !== '' ? ' · ' . e($company) : '' ?></td></tr>
<tr><td style="padding:0 16px 0 0;color:#5b616e;">Account</td><td>#<?= e($id) ?>, now shown as &ldquo;Deleted user&rdquo;</td></tr>
</table>
<p><strong>Kept as business records:</strong> <?= e($plural($kept['licences'], 'licence', 'licences')) ?>, <?= e($plural($kept['tickets'], 'ticket', 'tickets')) ?> and <?= e($plural($kept['comments'], 'comment', 'comments')) ?> on tickets. They stay linked to account #<?= e($id) ?> and show &ldquo;Deleted user&rdquo; as the author.</p>
<p><strong>Deleted for good:</strong> <?= e($plural((int) ($removed['news_comments'] ?? 0), 'comment', 'comments')) ?> on Industry Updates articles and <?= e($plural((int) ($removed['news_reports'] ?? 0), 'report', 'reports')) ?> of other people's comments. The articles' comment counts were updated.</p>
<p><a href="<?= e($baseUrl) ?>/portal/admin/users/<?= e($id) ?>/">See the account</a></p>
