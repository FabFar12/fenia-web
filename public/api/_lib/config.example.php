<?php

// TEMPLATE ONLY — no real values. The real file is <privateDir>/config.php
// (~/fenia-private/<env>/config.php on Hostinger), outside public_html and
// never in git. It arrives through api/_inbox/ (Inbox.php) and is validated by
// Config.php, which refuses to run on anything missing or for another env.

defined('FENIA_API') || exit;

return [
    'env' => 'staging',                          // 'staging' | 'production'
    'site_url' => 'https://staging.fenia.com.ar',
    'sales_enabled' => true,                     // kill switch (threat model B3)

    // Mercado Pago (Checkout Pro via Orders, D14)
    'mp_access_token' => 'APP_USR-…',            // test token on staging
    'mp_webhook_secret' => '…',                  // "clave secreta" of the app's webhooks
    'mp_collector_id' => '…',                    // user_id that owns the orders

    // MySQL (Hostinger: host localhost for PHP on the same server)
    'db_dsn' => 'mysql:host=localhost;dbname=…;charset=utf8mb4',
    'db_user' => '…',
    'db_password' => '…',

    // Delivery mail: sent from kits@, replies to soporte@ (D10)
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_user' => 'kits@fenia.com.ar',
    'smtp_password' => '…',
    'mail_from' => 'kits@fenia.com.ar',
    'mail_reply_to' => 'soporte@fenia.com.ar',

    // Random secret (≥ 32 chars) for keyed hashes of IPs/emails in the DB
    // (rate limits, download log). Generated per environment, never reused.
    // NEVER change it after the first sale: download links are derived from
    // it (Shop::tokenFor), so every link already sent would stop working.
    'hash_salt' => '…',

    // Kit id (the .md slug) => ZIP in <privateDir>/kits/ + its SHA-256 (E2E-02)
    'kits' => [
        'guia-evaluar-tiempos-ia' => ['file' => 'guia-evaluar-tiempos-ia.zip', 'sha256' => '…'],
    ],

    // Optional (defaults in Config.php): download_days 30, max_downloads 5,
    // order_ttl_minutes 60, alert_email '' (= mail_reply_to; alerts and the
    // daily report of the hourly job), daily_report true.
];
