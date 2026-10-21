<?php

/*
 * The server's configuration. Copy this file to one of these places (first found wins) and fill it in:
 *   1. the path in the PSC_CONFIG environment variable
 *   2. ../patterns-data/config.php   (beside public_html: outside the web root — preferred)
 *   3. config/local.php              (inside the site; denied to the web by .htaccess)
 * A config/local.php found while (2) does not exist is moved to (2) on the first request when that folder can be
 * created, so a file uploaded into the site lands outside the web root and survives redeploys.
 * Only the keys you set override config/defaults.php. Never commit the filled-in file.
 */

return [
    'app' => [
        'url' => 'https://patternsshowcontrol.co.uk',
        'secret' => 'REPLACE_WITH_64_HEX_CHARS',      // php -r "echo bin2hex(random_bytes(32));"
        'admin_emails' => ['you@example.com'],
    ],
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=u000000000_psc;charset=utf8mb4',
        'user' => 'u000000000_psc',
        'password' => 'REPLACE',
    ],
    'mail' => [
        'driver' => 'smtp',
        'from' => 'no-reply@patternsshowcontrol.co.uk',
        'admin_to' => 'you@example.com',
        'smtp' => [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'encryption' => 'ssl',
            'user' => 'no-reply@patternsshowcontrol.co.uk',
            'password' => 'REPLACE',
        ],
    ],
    'chat' => [
        'api_key' => 'REPLACE_WITH_ANTHROPIC_API_KEY',
    ],
];
