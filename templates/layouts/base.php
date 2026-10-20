<?php
/** @var \Psc\View\Seo $seo */
/** @var string $content */
?><!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $seo->toHead() ?>
</head>
<body>
<main id="main"><?= $content ?></main>
</body>
</html>
