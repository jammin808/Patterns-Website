<?php

/* The browser checks' configuration: SQLite and emails as files in a throwaway storage folder. */

$storage = getenv('PSC_E2E_STORAGE') ?: sys_get_temp_dir() . '/psc-e2e';

return [
    'analytics' => ['ga_id' => ''],     // no Google tag outside production
    'app' => [
        'url' => 'http://127.0.0.1:8765',
        'env' => 'development',
        'debug' => false,
        'force_https' => false,
        'secret' => str_repeat('e2', 32),
        'admin_emails' => ['admin@example.test'],
    ],
    'storage' => ['path' => $storage],
    'mail' => ['driver' => 'log', 'admin_to' => 'owner@example.test'],
    'chat' => ['api_key' => ''],
    'security' => ['login_max_attempts' => 50],
];
