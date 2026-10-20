<?php

declare(strict_types=1);

/*
 * Defaults for every setting. The server's own file (see config/config.example.php) overrides any of these; it is
 * never committed. Secrets have no default here.
 */

return [
    'app' => [
        'name' => 'Patterns Show Control',
        'url' => 'https://patternsshowcontrol.co.uk',
        'env' => 'production',            // production | development | testing
        'debug' => false,
        'secret' => '',                   // 32+ random bytes, hex or base64; signs tokens. Required in production.
        'timezone' => 'Europe/London',
        'locale' => 'en-GB',
        'force_https' => true,
        'admin_emails' => [],             // verified accounts with these emails are administrators
        'support_email' => 'support@patternsshowcontrol.co.uk',
        'publisher' => 'Ben Rohleder',
        'trusted_proxy_header' => null,   // e.g. 'HTTP_CF_CONNECTING_IP' when a CDN sits in front
        'page_cache_seconds' => 300,      // public pages: browser/CDN cache life
    ],

    'db' => [
        // Empty DSN: a SQLite file in the storage directory (development). Production: MySQL on 127.0.0.1.
        'dsn' => '',
        'user' => '',
        'password' => '',
        'auto_migrate' => true,
    ],

    'storage' => [
        // null: '../patterns-data' beside the site when writable (outside public_html), else ./storage.
        'path' => null,
    ],

    'mail' => [
        'driver' => 'log',                // log | mail | smtp
        'from' => 'no-reply@patternsshowcontrol.co.uk',
        'from_name' => 'Patterns Show Control',
        'admin_to' => '',                 // where contact messages, licence requests and new tickets are sent
        'smtp' => [
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'encryption' => 'ssl',        // ssl (implicit TLS) | tls (STARTTLS) | none
            'user' => '',
            'password' => '',
            'timeout' => 15,
        ],
    ],

    'security' => [
        'registration' => 'open',         // open | approval | invite
        'session_idle_seconds' => 7200,
        'session_absolute_seconds' => 43200,
        'remember_days' => 30,
        'login_max_attempts' => 5,        // per account and IP within the window
        'login_window_seconds' => 900,
        'password_min_length' => 10,
        'turnstile_site_key' => '',       // optional Cloudflare Turnstile on public forms
        'turnstile_secret' => '',
    ],

    'chat' => [
        'enabled' => true,
        'api_key' => '',                  // Anthropic API key; the assistant is off until it is set
        'model' => 'claude-sonnet-5-5',
        'gate_model' => 'claude-haiku-5-5',
        'max_tokens' => 1200,
        'max_input_chars' => 1200,
        'max_turns' => 16,                // user messages per conversation
        'history_messages' => 12,         // earlier messages sent back to the model
        'per_ip_per_10min' => 20,
        'per_ip_per_day' => 120,
        'daily_token_budget' => 1500000,  // all conversations together; past it the assistant hands over to the forms
        'retention_days' => 30,
        'timeout_seconds' => 60,
    ],

    'licensing' => [
        'payment_mode' => 'invoice',      // invoice | links
        'payment_links' => [],            // e.g. ['stagewatch.pro' => 'https://buy.stripe.com/...']
    ],

    'downloads' => [
        'max_upload_mb' => 512,
    ],

    'uploads' => [
        'max_mb' => 10,
        'allowed' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'log', 'json', 'pdf', 'zip', 'csv', 'xml'],
    ],
];
