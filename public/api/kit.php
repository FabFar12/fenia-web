<?php

declare(strict_types=1);

// "Tu kit" (D25, docs/ventas/propuesta-visual.md §6).
//   POST JSON {token, refresh?}         → what /descarga shows, plus a view pass
//                                         for this device (HttpOnly cookie).
//   GET ?k=<kit>&r=fichas|imprimibles   → that page of the kit, with the pass.
// Nothing here counts downloads. The pages' Content-Security-Policy lives in
// api/.htaccess (LiteSpeed would replace one set here).
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['GET', 'POST'], static function (Shop $shop, Config $config): ?array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!Api::originOk($config)) {
            return [403, ['usable' => false]];
        }
        $in = Api::jsonInput() ?? [];
        $token = is_string($in['token'] ?? null) ? $in['token'] : '';
        $result = $shop->openKit($token, Api::clientIp(), Api::userAgent(), ($in['refresh'] ?? false) === true);
        if (isset($result[2])) {
            Api::setPassCookie($result[2]);
        }
        return [$result[0], $result[1]];
    }

    $kit = is_string($_GET['k'] ?? null) ? $_GET['k'] : '';
    $page = in_array($_GET['r'] ?? null, ['fichas', 'imprimibles'], true) ? (string) $_GET['r'] : '';
    Api::sendKitPage($shop->kitResource($kit, $page, Api::passCookie($kit), Api::clientIp(), Api::userAgent()), $config);
    return null;
});
