<?php

declare(strict_types=1);

/**
 * Class loader for the sales API (ADR-028). No Composer on the server:
 * `Fenia\Api\Foo` lives in `_lib/Foo.php`.
 *
 * Every file in _lib/ refuses to run unless FENIA_API is defined, as a second
 * layer behind the `.htaccess` deny (threat model A7).
 */

defined('FENIA_API') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Fenia\\Api\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) {
        return;
    }
    $file = __DIR__ . '/' . $name . '.php';
    if (is_file($file)) {
        require $file;
    }
});
