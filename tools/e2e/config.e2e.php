<?php

/* The browser checks' configuration: SQLite and emails as files in a throwaway storage folder. */

$storage = getenv('PSC_E2E_STORAGE') ?: sys_get_temp_dir() . '/psc-e2e';

return [
    // The tag and its cookie choice are on, under a test ID; every spec answers the choice up front (playwright
    // config) except consent.spec, which intercepts Google's hosts, so no run reaches Google.
    // In consent mode, so the browser suite covers the cookie banner (the default tracks every visit, banner off).
    'analytics' => ['ga_id' => 'G-E2ETEST00', 'consent' => true],
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
    // The launch articles all go live at once when the server starts, so pages.spec checks every one of them.
    'news' => ['import_stagger' => false],
];
