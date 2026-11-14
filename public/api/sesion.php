<?php

declare(strict_types=1);

// Owners' session for the internal metrics (ADR-029), user and password.
//   POST JSON {usuario, clave}  → logs in: session cookie (HttpOnly) + a
//                                  readable flag that stops counting our visits.
//   POST JSON {salir: true}     → logs out.
//   GET                         → who is logged in (200) or 401.
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Owners;
use Fenia\Api\Shop;

Api::run(['GET', 'POST'], static function (Shop $shop, Config $config, array $app): ?array {
    $owners = new Owners($app['pdo'], $config);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $user = $owners->current(Api::sessionCookie());
        return $user === null ? [401, ['error' => 'login']] : [200, ['user' => $user]];
    }
    if (!Api::originOk($config)) {
        return [403, ['error' => 'origin']];
    }
    $in = Api::jsonInput() ?? [];
    if (($in['salir'] ?? false) === true) {
        $owners->logout(Api::sessionCookie());
        Api::clearSessionCookies();
        return [200, ['ok' => true]];
    }
    $user = is_string($in['usuario'] ?? null) ? $in['usuario'] : '';
    $password = is_string($in['clave'] ?? null) ? $in['clave'] : '';
    $result = $owners->login($user, $password, Api::clientIp());
    if (isset($result[2])) {
        Api::setSessionCookies($result[2]);
    }
    return [$result[0], $result[1]];
});
