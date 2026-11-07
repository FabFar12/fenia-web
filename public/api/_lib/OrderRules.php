<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * The one place that decides what a Mercado Pago order means for our order
 * (Checkout Pro via the Orders API, decision D14).
 *
 * Input is the order as returned by MP's API (`GET /v1/orders/{id}`), never the
 * webhook body: the API is the source of truth (threat model A1). The function
 * is pure, so every branch is unit-tested (test plan U-10…U-17).
 *
 * Our order statuses: `pending` (created, unpaid) → `paid` (delivered) →
 * `revoked` (refund / chargeback). `review` means a human must look: nothing is
 * delivered automatically from it.
 *
 * MP order statuses (docs, 2026-11): created · processing · action_required ·
 * processed (accredited | partially_refunded) · failed · refunded · canceled.
 * Orders carry no `live_mode`: a sandbox order belongs to the test seller, so
 * the `user_id` check is what keeps test orders out of production (A11).
 */
final class OrderRules
{
    public const DELIVER = 'deliver';
    public const REVOKE = 'revoke';
    public const REVIEW = 'review';
    public const IGNORE = 'ignore';

    /**
     * @param array<string, mixed> $mpOrder MP order resource
     * @param array{external_reference: string, status: string, expected_cents: int, currency: string, mp_order_id: ?string} $order
     * @param array{collector_id: string} $env
     * @return array{action: string, reason: string}
     */
    public static function decide(array $mpOrder, array $order, array $env): array
    {
        $reference = (string) ($mpOrder['external_reference'] ?? '');
        if ($reference === '' || !hash_equals($order['external_reference'], $reference)) {
            return self::result(self::IGNORE, 'reference_mismatch');
        }

        // Also rejects sandbox orders in production: they belong to the test seller.
        if ((string) ($mpOrder['user_id'] ?? '') !== $env['collector_id']) {
            return self::result(self::REVIEW, 'collector_mismatch');
        }

        $mpOrderId = (string) ($mpOrder['id'] ?? '');
        $sameOrder = $order['mp_order_id'] !== null && $order['mp_order_id'] === $mpOrderId;
        $delivered = $order['status'] === 'paid' && $sameOrder;

        if (!empty($mpOrder['transactions']['chargebacks'])) {
            return $delivered
                ? self::result(self::REVOKE, 'chargeback')
                : self::result(self::REVIEW, 'chargeback_not_delivered');
        }

        $status = (string) ($mpOrder['status'] ?? '');
        $detail = (string) ($mpOrder['status_detail'] ?? '');

        if ($status === 'processed') {
            return $detail === 'accredited'
                ? self::decidePaid($mpOrder, $order, $sameOrder)
                : self::result(self::REVIEW, 'processed_' . ($detail === '' ? 'unknown' : $detail));
        }

        if ($status === 'refunded') {
            return $delivered
                ? self::result(self::REVOKE, 'refunded')
                : self::result(self::IGNORE, 'not_delivered_refunded');
        }

        // created, processing, action_required, failed, canceled, anything new:
        // never deliver (threat model A10).
        return self::result(self::IGNORE, 'not_final_' . ($status === '' ? 'unknown' : $status));
    }

    /**
     * @param array<string, mixed> $mpOrder
     * @param array{external_reference: string, status: string, expected_cents: int, currency: string, mp_order_id: ?string} $order
     * @return array{action: string, reason: string}
     */
    private static function decidePaid(array $mpOrder, array $order, bool $sameOrder): array
    {
        if (($mpOrder['currency'] ?? null) !== $order['currency']) {
            return self::result(self::REVIEW, 'currency_mismatch');
        }
        $total = Money::toCents($mpOrder['total_amount'] ?? null);
        $paid = Money::toCents($mpOrder['total_paid_amount'] ?? null);
        if ($total === null || $total !== $order['expected_cents']) {
            return self::result(self::REVIEW, 'amount_mismatch');
        }
        // Installments with interest can make the buyer pay more, never less.
        if ($paid === null || $paid < $order['expected_cents']) {
            return self::result(self::REVIEW, 'underpaid');
        }

        switch ($order['status']) {
            case 'pending':
                return $sameOrder
                    ? self::result(self::DELIVER, 'accredited')
                    : self::result(self::REVIEW, 'unknown_mp_order');
            case 'paid':
                // The same order notified again → nothing to do. A second MP
                // order for an already-paid purchase → refund it (threat model B5).
                return $sameOrder
                    ? self::result(self::IGNORE, 'already_delivered')
                    : self::result(self::REVIEW, 'duplicate_payment');
            case 'review':
                return self::result(self::IGNORE, 'order_in_review');
            case 'revoked':
                return self::result(self::REVIEW, 'paid_after_revoke');
            default:
                return self::result(self::REVIEW, 'unknown_order_status');
        }
    }

    /** @return array{action: string, reason: string} */
    private static function result(string $action, string $reason): array
    {
        return ['action' => $action, 'reason' => $reason];
    }
}
