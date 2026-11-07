<?php

declare(strict_types=1);

// Hourly job (step 3.4): `php …/api/_lib/hourly.php` from Hostinger's cron.
// Command line only; _lib/ is also denied over HTTP. See Cron.php.
if (PHP_SAPI !== 'cli') {
    exit;
}
define('FENIA_API', true);
require __DIR__ . '/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Cron;

try {
    $app = Api::boot();
    $summary = (new Cron($app['shop'], $app['pdo'], $app['mailer'], $app['config'], $app['privateDir']))->run();
} catch (\Throwable $e) {
    echo gmdate('c'), ' FAIL ', get_class($e), ': ', mb_substr($e->getMessage(), 0, 200), "\n";
    exit(1);
}
$parts = [];
foreach ($summary as $key => $value) {
    $parts[] = $key . '=' . $value;
}
echo gmdate('c'), ' OK php=', PHP_VERSION, ' ', implode(' ', $parts), "\n";
