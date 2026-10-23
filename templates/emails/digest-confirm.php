<?php
/**
 * The digest's confirmation (docs/NEWS.md §14.9): its link opens a page with a button, and nothing is sent until that
 * button is pressed. No list headers and no advertising: it carries the layout's own footer line.
 *
 * @var string $email the address that asked
 * @var string $url the absolute confirm link
 * @var string $publisher app.publisher
 * @var string $siteName @var string $baseUrl
 */
?>
<h1 style="font-size:18px;margin:0 0 12px;">Confirm your weekly digest</h1>
<p>Someone, most likely you, asked for the weekly Industry Updates digest from <?= e($siteName) ?> to be sent to <?= e($email) ?>.</p>
<p>To start it, press the button below, then the button on the page it opens. Nothing is sent until you do.</p>
<?= $this->render('emails/_button', ['url' => $url, 'label' => 'Confirm my digest']) ?>
<p>The link works for 7 days. If you did not ask for this, you can ignore this email and you will hear nothing more from us.</p>
<p style="font-size:13px;color:#5b616e;">The digest is one email a week with that week's new Industry Updates articles, and every one has a link to a page where one button unsubscribes you.</p>
<p style="font-size:13px;color:#5b616e;">From <?= e($siteName) ?>, run by <?= e($publisher) ?>, United Kingdom. How we use your address: <a href="<?= e($baseUrl) ?>/legal/privacy/" style="color:#5b616e;">our privacy notice</a>.</p>
