<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in server, standing in for the root .htaccess:
 *   php -S 127.0.0.1:8080 tools/dev-router.php
 * Real files under public/ are served as they are; everything else goes to public/index.php.
 */

$root = dirname(__DIR__);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath($root . '/public' . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, $root . '/public/') && !str_ends_with($file, '.php')) {
    $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon', 'txt' => 'text/plain', 'webmanifest' => 'application/manifest+json'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    // As public/.htaccess: versioned stylesheets and scripts for a year, images and fonts (unversioned) for a week.
    header('Cache-Control: ' . (in_array($ext, ['css', 'js'], true) ? 'public, max-age=31536000, immutable' : 'public, max-age=604800'));
    readfile($file);
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/public/index.php';
return true;
