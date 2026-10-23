<?php
/**
 * The digest's footer, drawn by the layout in place of its own line (docs/NEWS.md §14.8): why it came, both ways
 * to stop it, and who sends it. digest.text.php says the same in its text part.
 *
 * @var array{email: string, source: string, date: string}|null $who the subscriber (the preview: the sample); null on a test
 * @var string $unsubscribeUrl the unsubscribe page (one button), with this message's token
 * @var string $mailto mail.unsubscribe_to
 * @var string $mailtoUrl its mailto link, the token in the subject
 * @var string $privacyUrl @var string $publisher @var string $siteName
 */
// What the subscriber did, in the second person; :date (confirmed_at, London) ends the sentence.
$how = [
    'page' => 'signed up for it on our website and confirmed it',
    'account' => 'ticked the box for it on your account page and confirmed it',
    'registration' => 'ticked the box for it when you created your account and confirmed it',
];
?>
<?php if ($who === null): ?>
<p style="margin:0 0 8px;">You get this test because you sent it from the Industry Updates desk.</p>
<?php else: ?>
<p style="margin:0 0 8px;">You get this weekly digest at <?= e($who['email']) ?> because you <?= e($how[$who['source']] ?? $how['page']) ?> on <?= e($who['date']) ?>.</p>
<?php endif ?>
<p style="margin:0 0 8px;">To stop it, <a href="<?= e($unsubscribeUrl) ?>" style="color:#5b616e;">open this link and press Unsubscribe</a><?php if ($mailto !== ''): ?> or email <a href="<?= e($mailtoUrl) ?>" style="color:#5b616e;"><?= e($mailto) ?></a><?php endif ?>.</p>
<p style="margin:0;">From <?= e($siteName) ?>, run by <?= e($publisher) ?>, United Kingdom. How we use your address: <a href="<?= e($privacyUrl) ?>" style="color:#5b616e;">our privacy notice</a>.</p>
