<?php
/**
 * To staff: a client replied on a ticket (the owner, or another client on a public feature request).
 *
 * @var array<string, mixed> $ticket
 * @var string $typeLabel
 * @var string $authorName
 * @var string $authorEmail
 * @var bool $isOwner
 * @var string $body
 * @var int $fileCount
 * @var string $url
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">New reply on #<?= e($ticket['id']) ?>: <?= e($ticket['title']) ?></h1>
<p style="margin:0 0 4px;"><strong>From:</strong> <?= e($authorName) ?> &lt;<?= e($authorEmail) ?>&gt; · <?= $isOwner ? 'who opened the ticket' : 'another client' ?></p>
<p style="margin:0 0 4px;"><strong>Ticket:</strong> <?= e($typeLabel) ?> #<?= e($ticket['id']) ?></p>
<?php if ($fileCount > 0): ?>
<p style="margin:0 0 4px;"><strong>Files:</strong> <?= e($fileCount) ?> attached</p>
<?php endif; ?>
<div style="margin-top:16px;border-left:3px solid #e3e5ea;padding-left:12px;"><?= md($body) ?></div>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Open the ticket']) ?>
