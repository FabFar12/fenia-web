<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/** A failed call to Mercado Pago. The code is the HTTP status (0 = network). */
final class MpException extends \RuntimeException
{
}
