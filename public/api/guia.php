<?php

declare(strict_types=1);

// "Tu kit" (D25): GET ?k=<kit> → the kit's guide PDF, shown in the browser
// ("Leer ahora"). Needs the view pass set by kit.php; counts no download.
// api/.htaccess sends no Content-Security-Policy here: a page policy can break
// the browser's own PDF viewer.
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['GET'], static function (Shop $shop, Config $config): ?array {
    $kit = is_string($_GET['k'] ?? null) ? $_GET['k'] : '';
    Api::sendKitPage($shop->kitResource($kit, 'guide', Api::passCookie($kit), Api::clientIp(), Api::userAgent()), $config);
    return null;
});
