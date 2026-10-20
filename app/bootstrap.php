<?php

declare(strict_types=1);

/*
 * Boots the application. The Psc\ classes load with or without Composer, so the public pages keep working if the
 * vendor directory is missing; only the features that need a package (the support assistant) say they are
 * unavailable.
 */

if (!defined('PSC_ROOT')) {
    define('PSC_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Psc\\')) {
        return;
    }
    $file = PSC_ROOT . '/app/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(PSC_ROOT . '/vendor/autoload.php')) {
    require_once PSC_ROOT . '/vendor/autoload.php';
}

require_once PSC_ROOT . '/app/helpers.php';

return Psc\App::boot();
