<?php

/*
 * A development configuration: copy to config/local.php and run
 *   PSC_NO_CONFIG_MOVE=1 php -S 127.0.0.1:8080 tools/dev-router.php
 * SQLite in ./storage, emails written to storage/mail as .eml files, no HTTPS redirect.
 */

return [
    'analytics' => ['ga_id' => ''],     // no Google tag outside production
    'app' => [
        'url' => 'http://127.0.0.1:8080',
        'env' => 'development',
        'debug' => true,
        'force_https' => false,
        'admin_emails' => ['admin@example.test'],
    ],
    'storage' => ['path' => __DIR__ . '/../storage'],
    'mail' => ['driver' => 'log'],
];
