<?php

declare(strict_types=1);

// POST /api/checkout.php — starts a purchase (kit page → Mercado Pago). ADR-028.
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['POST'], static function (Shop $shop, Config $config): array {
    if (!Api::originOk($config)) {
        return [403, ['error' => 'origin']];
    }
    $in = Api::jsonInput();
    return $in === null ? [400, ['error' => 'invalid']] : $shop->checkout($in, Api::clientIp());
});
