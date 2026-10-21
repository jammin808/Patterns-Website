<?php /** @var string $subject @var string $body @var string $siteName @var string $baseUrl */ ?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#15171c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;border:1px solid #e3e5ea;">
<tr><td style="padding:20px 28px;border-bottom:4px solid #15171c;">
<a href="<?= e($baseUrl) ?>/" style="color:#15171c;text-decoration:none;font-weight:700;font-size:16px;letter-spacing:.02em;"><?= e($siteName) ?></a>
</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
<?= $body ?>
</td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #e3e5ea;font-size:12px;line-height:1.5;color:#5b616e;">
You received this because of an action on <a href="<?= e($baseUrl) ?>/" style="color:#5b616e;"><?= e(parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl) ?></a>. If it was not you, you can ignore it.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
