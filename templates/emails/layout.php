<?php
/**
 * Every email's frame. Two optional values: $preheader (plain text, a hidden first line that mail clients show beside
 * the subject) and $footer (HTML, drawn in place of the line about why the email came). Without them the output is
 * the same, byte for byte, as it has always been.
 *
 * @var string $subject @var string $body @var string $siteName @var string $baseUrl
 * @var string|null $preheader @var string|null $footer
 */
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#15171c;">
<?php if (isset($preheader)): ?><div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f5f7;opacity:0;"><?= e($preheader) ?></div>
<?php endif ?><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;border:1px solid #e3e5ea;">
<tr><td style="padding:20px 28px;border-bottom:4px solid #15171c;">
<a href="<?= e($baseUrl) ?>/" style="color:#15171c;text-decoration:none;font-weight:700;font-size:16px;letter-spacing:.02em;"><?= e($siteName) ?></a>
</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
<?= $body ?>
</td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #e3e5ea;font-size:12px;line-height:1.5;color:#5b616e;">
<?php if (isset($footer)): ?><?= $footer . "\n" ?><?php else: ?>You received this because of an action on <a href="<?= e($baseUrl) ?>/" style="color:#5b616e;"><?= e(parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl) ?></a>. If it was not you, you can ignore it.
<?php endif ?></td></tr>
</table>
</td></tr>
</table>
</body>
</html>
