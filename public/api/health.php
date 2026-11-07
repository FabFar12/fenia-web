<?php

declare(strict_types=1);

// GET /api/health.php — the API booted (config, database, catalogue). For the
// uptime monitor and the post-deploy check. `ip` is the caller's own address
// as PHP sees it: behind Hostinger's CDN it shows whether rate limits see real
// visitors or the CDN (H-06).
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['GET', 'HEAD'], static function (Shop $shop, Config $config): array {
    return [200, ['ok' => true, 'sales' => $config->salesEnabled(), 'kits' => $shop->kitsReady(), 'ip' => Api::clientIp()]];
});
