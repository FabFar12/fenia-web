<?php

declare(strict_types=1);

// GET /api/status.php?ref=… — what /gracias shows while the payment settles.
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['GET'], static function (Shop $shop, Config $config): array {
    $ref = $_GET['ref'] ?? '';
    return $shop->status(is_string($ref) ? $ref : '', Api::clientIp());
});
