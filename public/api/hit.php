<?php

declare(strict_types=1);

// Private visit counter (ADR-029). Every page posts here with
// navigator.sendBeacon: one event per page view or WhatsApp click. Always
// answers 204 with no body, so it tells nothing to whoever calls it. Sets no
// cookies and stores nothing personal (see Visits).
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\RateLimiter;
use Fenia\Api\Shop;
use Fenia\Api\Visits;

Api::run(['POST'], static function (Shop $shop, Config $config, array $app): ?array {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $salt = (string) $config->get('hash_salt');
    $in = Api::beaconInput();
    if ($in !== null && Api::originOk($config)
        && (new RateLimiter($app['pdo']))->allow(RateLimiter::key('hit-ip', Api::clientIp(), $salt), 300, 3600, $now)) {
        $host = (string) parse_url((string) $config->get('site_url'), PHP_URL_HOST);
        Visits::record($app['pdo'], $in, Api::clientIp(), Api::userAgent(), $salt, $host, $now);
    }
    http_response_code(204);
    return null;
});
