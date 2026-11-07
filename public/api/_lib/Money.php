<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Amounts are compared as integer cents, never as floats
 * (threat model C17: 8000 vs 8000.00 vs 7999.999…).
 */
final class Money
{
    /**
     * @param int|float|string $amount MP sends `transaction_amount` as a JSON number
     * @return int|null null for negative, non-numeric or non-finite input
     */
    public static function toCents($amount): ?int
    {
        if (is_string($amount)) {
            if (!is_numeric($amount)) {
                return null;
            }
            $amount = (float) $amount;
        }
        if (!is_int($amount) && !is_float($amount)) {
            return null;
        }
        $amount = (float) $amount;
        if (!is_finite($amount) || $amount < 0) {
            return null;
        }
        return (int) round($amount * 100);
    }

    /** 1300000 → "13000.00": the decimal string the Orders API expects. */
    public static function fromCents(int $cents): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('Negative amount');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
