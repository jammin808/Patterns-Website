<?php
/** @var int $status @var string $title @var string|null $debug */
$messages = [
    403 => 'You do not have access to this page. If you think you should, sign in with the right account or contact us.',
    404 => 'This page does not exist, or it has moved. Try the links below, or ask the support assistant.',
    405 => 'That action is not available here.',
    410 => 'This page was removed for good. Try the links below, or ask the support assistant.',
    419 => 'Your session expired before the form was sent. Go back, refresh the page and try again.',
    429 => 'Too many requests in a short time. Please wait a few minutes and try again.',
    500 => 'Something went wrong on our side. It has been logged. Please try again in a moment.',
];
?>
<section class="c-error">
    <p class="c-error-code" aria-hidden="true"><?= e($status) ?></p>
    <h1><?= e($title) ?></h1>
    <p><?= e($messages[$status] ?? 'Something went wrong.') ?></p>
    <p class="c-error-links"><a href="/">Home</a> · <a href="/support/">Support</a> · <a href="/contact/">Contact</a></p>
    <?php if (!empty($debug)): ?><pre class="c-error-debug"><?= e($debug) ?></pre><?php endif; ?>
</section>
