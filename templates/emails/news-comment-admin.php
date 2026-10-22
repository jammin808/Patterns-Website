<?php
/**
 * To staff: an Industry Updates comment needs a look. Sent on a comment's first report, again when reports hide it,
 * and (at most once a day) when a comment waits for approval. The comment is plain text, never Markdown.
 *
 * @var string $kind reported | hidden | pending
 * @var array{title: string, url: string} $article
 * @var string $authorName
 * @var string $authorEmail
 * @var string $body
 * @var string $url the moderation page
 */
$intro = match ($kind) {
    'hidden' => 'A comment has been reported enough times that it is now hidden from the article. It stays hidden until you show it again or delete it.',
    'pending' => 'A comment is waiting for your approval. Others may be waiting too: the moderation page lists them all. This email is sent at most once a day.',
    default => 'A reader reported a comment. It still shows on the article; it is hidden automatically if more readers report it.',
};
$heading = match ($kind) {
    'hidden' => 'A reported comment was hidden',
    'pending' => 'A comment is waiting for approval',
    default => 'A comment was reported',
};
?>
<h1 style="font-size:18px;margin:0 0 12px;"><?= e($heading) ?></h1>
<p><?= e($intro) ?></p>
<p style="margin:0 0 4px;"><strong>Article:</strong> <a href="<?= e($article['url']) ?>"><?= e($article['title']) ?></a></p>
<p style="margin:0 0 4px;"><strong>From:</strong> <?= e($authorName) ?> &lt;<?= e($authorEmail) ?>&gt;</p>
<div style="margin-top:16px;border-left:3px solid #e3e5ea;padding-left:12px;"><p><?= nl2br(e($body)) ?></p></div>
<?= $this->render('emails/_button', ['url' => $url, 'label' => $kind === 'pending' ? 'Review waiting comments' : 'Review the comment']) ?>
