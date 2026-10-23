<?php
/**
 * The confirmation's written text part: the same words as digest-confirm.php, every link written out, and the
 * layout's footer line. Plain text, so nothing is HTML-escaped.
 *
 * @var string $email @var string $url @var string $publisher @var string $siteName @var string $baseUrl
 */
$host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
echo implode("\n", [
    'Confirm your weekly digest',
    '',
    'Someone, most likely you, asked for the weekly Industry Updates digest from ' . $siteName . ' to be sent to ' . $email . '.',
    '',
    'To start it, open the link below, then press the button on the page it opens. Nothing is sent until you do.',
    '',
    'Confirm my digest:',
    $url,
    '',
    'The link works for 7 days. If you did not ask for this, you can ignore this email and you will hear nothing more from us.',
    '',
    'The digest is one email a week with that week\'s new Industry Updates articles, and every one has a link to a page where one button unsubscribes you.',
    '',
    'From ' . $siteName . ', run by ' . $publisher . ', United Kingdom. How we use your address: our privacy notice, ' . $baseUrl . '/legal/privacy/',
    '',
    '--',
    'You received this because of an action on ' . $host . '. If it was not you, you can ignore it.',
]), "\n";
