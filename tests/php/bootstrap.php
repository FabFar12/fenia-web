<?php

declare(strict_types=1);

// PHPUnit bootstrap: load the sales API classes the same way endpoints do.
define('FENIA_API', true);
require __DIR__ . '/../../public/api/_lib/autoload.php';
