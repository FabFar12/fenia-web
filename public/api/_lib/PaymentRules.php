<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * The one place that decides what a payment means for an order.
 *
 * Input is the payment as returned by MP's API (`GET /v1/payments/{id}`),
 * never the webhook body: the API is the source of truth (threat model A1).
 * The function is pure, so every branch is unit-tested (test plan U-11…U-17).
 *
 * Order statuses: `pending` (created, unpaid) → `paid` (delivered) →
 * `revoked` (refund / chargeback / dispute). `review` means a human must look:
 * nothing is delivered automatically from it.
 */
final class PaymentRules
{
    public const DELIVER = 'deliver';
    public const REVOKE = 'revoke';
    public const REVIEW = 'review';
    public const IGNORE = 'ignore';

    /** Statuses that undo a delivery (threat model A13). */
    private const REVOKING_STATUSES = ['refunded', 'charged_back', 'in_mediation', 'cancelled'];

    /**
     * @param array<string, mixed> $payment MP payment resource
     * @param array{external_reference: string, status: string, expected_cents: int, currency: string, payment_id: ?string} $order
     * @param array{collector_id: string, require_live_mode: bool} $env
     * @return array{action: string, reason: string}
     */
    public static function decide(array $payment, array $order, array $env): array
    {
        $reference = (string) ($payment['external_reference'] ?? '');
        if ($reference === '' || !hash_equals($order['external_reference'], $reference)) {
            return self::result(self::IGNORE, 'reference_mismatch');
        }

        $collector = $payment['collector_id'] ?? ($payment['collector']['id'] ?? null);
        if ((string) $collector !== $env['collector_id']) {
            return self::result(self::REVIEW, 'collector_mismatch');
        }

        // Sandbox payments never count in production (threat model A11).
        if ($env['require_live_mode'] && ($payment['live_mode'] ?? null) !== true) {
            return self::result(self::IGNORE, 'not_live_mode');
        }

        $status = (string) ($payment['status'] ?? '');
        $paymentId = (string) ($payment['id'] ?? '');
        $sameAsDelivered = $order['payment_id'] !== null && $order['payment_id'] === $paymentId;

        if ($status === 'approved') {
            return self::decideApproved($payment, $order, $sameAsDelivered);
        }

        if (in_array($status, self::REVOKING_STATUSES, true)) {
            return $order['status'] === 'paid' && $sameAsDelivered
                ? self::result(self::REVOKE, $status)
                : self::result(self::IGNORE, 'not_delivered_' . $status);
        }

        // pending, in_process, authorized, rejected… never deliver (threat model A10).
        return self::result(self::IGNORE, 'not_final_' . ($status === '' ? 'unknown' : $status));
    }

    /**
     * @param array<string, mixed> $payment
     * @param array{external_reference: string, status: string, expected_cents: int, currency: string, payment_id: ?string} $order
     * @return array{action: string, reason: string}
     */
    private static function decideApproved(array $payment, array $order, bool $sameAsDelivered): array
    {
        $detail = (string) ($payment['status_detail'] ?? '');
        if ($detail !== 'accredited') {
            return self::result(self::REVIEW, 'approved_not_accredited_' . $detail);
        }
        if (($payment['currency_id'] ?? null) !== $order['currency']) {
            return self::result(self::REVIEW, 'currency_mismatch');
        }
        $cents = Money::toCents($payment['transaction_amount'] ?? null);
        if ($cents === null || $cents !== $order['expected_cents']) {
            return self::result(self::REVIEW, 'amount_mismatch');
        }

        switch ($order['status']) {
            case 'pending':
                return self::result(self::DELIVER, 'approved');
            case 'paid':
                // Same payment notified again → nothing to do. A second payment
                // for an already-paid order → refund it (threat model B5).
                return $sameAsDelivered
                    ? self::result(self::IGNORE, 'already_delivered')
                    : self::result(self::REVIEW, 'duplicate_payment');
            case 'review':
                return self::result(self::IGNORE, 'order_in_review');
            case 'revoked':
                return self::result(self::REVIEW, 'approved_after_revoke');
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
