<?php

declare(strict_types=1);

// POST /api/webhook.php — Mercado Pago "order" notifications (signed; the
// order is always re-read from MP's API). ADR-028, threat model A1.
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['POST'], static function (Shop $shop, Config $config): array {
    $body = file_get_contents('php://input', false, null, 0, 65536);
    return $shop->webhook($_GET, (string) ($_SERVER['QUERY_STRING'] ?? ''), Api::headers(), $body === false ? '' : $body);
});
