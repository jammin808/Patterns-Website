<?php
/**
 * The page shell for every public, portal and error page (docs/DESIGN.md §2).
 *
 * Public pages are identical for every visitor (they are cached by the CDN): the theme, a dismissed announcement
 * and the "My account" link are applied in the browser (theme-init.js before paint, site.js after), never here.
 *
 * @var \Psc\View\Seo $seo
 * @var string $content
 * @var string|null $section
 * @var string|null $productKey
 * @var bool|null $bare        the portal layout supplies its own <main>
 */
$bare ??= false;
$section ??= null;
$productKey ??= null;
$chatInline = (bool) $this->shared('chatInline', false);
$home = $seo->path === '/';
$sprite = PSC_ROOT . '/public/assets/img/icons.svg';

// The trail opens the page hero (docs/DESIGN.md 8.4, 8.9): it is the first thing inside the hero's .l-wrap, so it
// shares the hero's width, backdrop and product bar and keeps one rhythm on every page. A page that does not start
// with a hero, or whose wrap is itself the hero grid (the trail would become a grid cell), gets the trail as a strip
// of its own above the content.
$crumbs = !$bare && !$home && count($seo->breadcrumbs) > 1 ? $this->partial('crumbs', ['crumbs' => $seo->breadcrumbs]) : '';
$crumbsInHero = false;
if ($crumbs !== '') {
    $hero = '/\A\s*<section\b[^>]*\bclass="(?:[^"]*\s)?c-hero(?:\s[^"]*)?"[^>]*>\s*<div\b[^>]*\bclass="(?![^"]*\bc-hero-grid\b)(?:[^"]*\s)?l-wrap(?:\s[^"]*)?"[^>]*>/';
    $placed = preg_replace_callback($hero, static fn (array $m): string => $m[0] . $crumbs, $content, 1, $count);
    if (is_string($placed) && $count === 1) {
        $content = $placed;
        $crumbsInHero = true;
    }
}
?><!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#090C10">
    <?= $seo->toHead() ?>

    <link rel="preload" href="/assets/fonts/archivo-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/assets/fonts/jetbrains-mono-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/site.css')) ?>">
    <script src="<?= e(asset('/assets/js/theme-init.js')) ?>"></script>
    <script type="module" src="<?= e(asset('/assets/js/site.js')) ?>"></script>
    <script type="module" src="<?= e(asset('/assets/js/chat.js')) ?>"></script>
    <?= $this->stack('scripts') ?>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/assets/img/brand/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="alternate" type="text/plain" href="/llms.txt" title="llms.txt">
    <?= $this->stack('head') ?>
</head>
<body class="l-page">
<?php if (is_file($sprite)) { readfile($sprite); } ?>
<a class="c-skip" href="#main">Skip to content</a>
<?= $this->partial('announce') ?>
<?= $this->partial('header', ['section' => $section]) ?>
<?php if ($bare): ?>
<?= $content ?>
<?php else: ?>
<main id="main"<?= $productKey !== null ? ' data-product="' . e($productKey) . '"' : '' ?>>
    <?php if (!empty($flashes)): ?>
        <div class="l-wrap c-flashes"><?= $this->partial('flashes', ['flashes' => $flashes]) ?></div>
    <?php endif; ?>
    <?php if ($crumbs !== '' && !$crumbsInHero): ?>
        <div class="l-wrap c-crumbs-bar"><?= $crumbs ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
<?php endif; ?>
<?= $this->partial('footer') ?>
<?php if (!$chatInline): ?>
<?= $this->partial('chat', ['inline' => false]) ?>
<?php endif; ?>
</body>
</html>
