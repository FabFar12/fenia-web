<?php

declare(strict_types=1);

// Internal metrics (ADR-029): GET ?rango=hoy|7d|30d|todo → JSON for the
// /metricas/ page. Only with an owner's session; 401 otherwise.
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Catalog;
use Fenia\Api\Config;
use Fenia\Api\Metrics;
use Fenia\Api\Owners;
use Fenia\Api\Shop;

Api::run(['GET'], static function (Shop $shop, Config $config, array $app): ?array {
    if ((new Owners($app['pdo'], $config))->current(Api::sessionCookie()) === null) {
        return [401, ['error' => 'login']];
    }
    $range = is_string($_GET['rango'] ?? null) ? $_GET['rango'] : '7d';
    $metrics = new Metrics($app['pdo'], Catalog::fromFile(__DIR__ . '/catalog.json'), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    return [200, $metrics->report($range)];
});
