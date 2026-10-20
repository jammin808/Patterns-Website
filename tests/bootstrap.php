<?php

declare(strict_types=1);

define('PSC_ROOT', dirname(__DIR__));
putenv('PSC_NO_CONFIG_MOVE=1');

spl_autoload_register(static function (string $class): void {
    foreach (['Psc\\Tests\\' => '/tests/', 'Psc\\' => '/app/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = PSC_ROOT . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

if (is_file(PSC_ROOT . '/vendor/autoload.php')) {
    require_once PSC_ROOT . '/vendor/autoload.php';
}
require_once PSC_ROOT . '/tools/phpunit/vendor/autoload.php';
require_once PSC_ROOT . '/app/helpers.php';
