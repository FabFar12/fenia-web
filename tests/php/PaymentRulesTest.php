<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\PaymentRules;
use PHPUnit\Framework\TestCase;

/**
 * Test plan U-10…U-17 (threat model A8–A13, B5, C3, C4, C17).
 * Decision part of U-10/U-18; the atomic database transition is covered
 * when the persistence layer lands (step 3.1b).
 */
final class PaymentRulesTest extends TestCase
{
    private const REF = '0123456789abcdef0123456789abcdef';
    private const COLLECTOR = '123456789';

    /** @param array<string, mixed> $overrides */
    private static function payment(array $overrides = []): array
    {
        return array_merge([
            'id' => 1111,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => 8000,
            'currency_id' => 'ARS',
            'external_reference' => self::REF,
            'collector_id' => 123456789,
            'live_mode' => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private static function order(array $overrides = []): array
    {
        return array_merge([
            'external_reference' => self::REF,
            'status' => 'pending',
            'expected_cents' => 800000,
            'currency' => 'ARS',
            'payment_id' => null,
        ], $overrides);
    }

    private static function env(bool $requireLiveMode = true): array
    {
        return ['collector_id' => self::COLLECTOR, 'require_live_mode' => $requireLiveMode];
    }

    private static function action(array $payment, array $order, ?array $env = null): string
    {
        return PaymentRules::decide($payment, $order, $env ?? self::env())['action'];
    }

    public function testDeliversApprovedAccreditedPaymentForPendingOrder(): void
    {
        self::assertSame(PaymentRules::DELIVER, self::action(self::payment(), self::order()));
    }

    public function testU10SamePaymentNotifiedAgainIsIgnored(): void
    {
        $paid = self::order(['status' => 'paid', 'payment_id' => '1111']);
        $result = PaymentRules::decide(self::payment(), $paid, self::env());
        self::assertSame(PaymentRules::IGNORE, $result['action']);
        self::assertSame('already_delivered', $result['reason']);
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function amounts(): iterable
    {
        yield 'exact int' => [8000, PaymentRules::DELIVER];
        yield 'exact float' => [8000.0, PaymentRules::DELIVER];
        yield 'exact with decimals' => [8000.00, PaymentRules::DELIVER];
        yield 'one cent short' => [7999.99, PaymentRules::REVIEW];
        yield 'one peso' => [1, PaymentRules::REVIEW];
        yield 'more than expected' => [8000.01, PaymentRules::REVIEW];
        yield 'missing' => [null, PaymentRules::REVIEW];
        yield 'negative' => [-8000, PaymentRules::REVIEW];
        yield 'garbage' => ['8000abc', PaymentRules::REVIEW];
    }

    /** @dataProvider amounts */
    public function testU11AmountMustMatchExactly($amount, string $expected): void
    {
        self::assertSame($expected, self::action(self::payment(['transaction_amount' => $amount]), self::order()));
    }

    public function testU11CurrencyMustMatch(): void
    {
        foreach (['USD', 'UYU', '', null] as $currency) {
            self::assertSame(
                PaymentRules::REVIEW,
                self::action(self::payment(['currency_id' => $currency]), self::order()),
                'currency ' . var_export($currency, true)
            );
        }
    }

    public function testU12PaymentForAnotherOrderNeverDelivers(): void
    {
        $other = self::payment(['external_reference' => 'ffffffffffffffffffffffffffffffff']);
        self::assertSame(PaymentRules::IGNORE, self::action($other, self::order()));
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['external_reference' => '']), self::order()));
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['external_reference' => null]), self::order()));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nonDeliveringStatuses(): iterable
    {
        yield 'pending' => ['pending', 'pending_waiting_payment'];
        yield 'in_process' => ['in_process', 'pending_review_manual'];
        yield 'authorized' => ['authorized', 'pending_capture'];
        yield 'rejected' => ['rejected', 'cc_rejected_other_reason'];
        yield 'cancelled' => ['cancelled', 'expired'];
        yield 'refunded' => ['refunded', 'refunded'];
        yield 'charged_back' => ['charged_back', 'settled'];
        yield 'in_mediation' => ['in_mediation', 'pending'];
        yield 'unknown' => ['something_new', ''];
        yield 'empty' => ['', ''];
    }

    /** @dataProvider nonDeliveringStatuses */
    public function testU13OnlyApprovedAccreditedDelivers(string $status, string $detail): void
    {
        $payment = self::payment(['status' => $status, 'status_detail' => $detail]);
        self::assertNotSame(PaymentRules::DELIVER, self::action($payment, self::order()));
    }

    public function testU13ApprovedButNotAccreditedGoesToReview(): void
    {
        $payment = self::payment(['status_detail' => 'partially_refunded']);
        self::assertSame(PaymentRules::REVIEW, self::action($payment, self::order()));
    }

    public function testU14RefundChargebackOrDisputeRevokesDeliveredOrder(): void
    {
        $paid = self::order(['status' => 'paid', 'payment_id' => '1111']);
        foreach (['refunded', 'charged_back', 'in_mediation'] as $status) {
            $result = PaymentRules::decide(self::payment(['status' => $status]), $paid, self::env());
            self::assertSame(PaymentRules::REVOKE, $result['action'], $status);
        }
    }

    public function testU14RefundOfAnUndeliveredOrderIsIgnored(): void
    {
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['status' => 'refunded']), self::order()));
    }

    public function testU14RefundOfADifferentPaymentDoesNotRevoke(): void
    {
        $paid = self::order(['status' => 'paid', 'payment_id' => '2222']);
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['status' => 'refunded']), $paid));
    }

    public function testU14OutOfOrderPendingAfterPaidChangesNothing(): void
    {
        $paid = self::order(['status' => 'paid', 'payment_id' => '1111']);
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['status' => 'pending']), $paid));
    }

    public function testU14ApprovedAgainAfterRevokeNeedsAHuman(): void
    {
        $revoked = self::order(['status' => 'revoked', 'payment_id' => '1111']);
        self::assertSame(PaymentRules::REVIEW, self::action(self::payment(), $revoked));
    }

    public function testOrderInReviewIsNeverAutoDelivered(): void
    {
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(), self::order(['status' => 'review'])));
    }

    public function testU15SandboxPaymentNeverCountsInProduction(): void
    {
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['live_mode' => false]), self::order()));
        self::assertSame(PaymentRules::IGNORE, self::action(self::payment(['live_mode' => null]), self::order()));
        // Staging runs with require_live_mode = false.
        self::assertSame(
            PaymentRules::DELIVER,
            self::action(self::payment(['live_mode' => false]), self::order(), self::env(false))
        );
    }

    public function testU16ForeignCollectorGoesToReview(): void
    {
        self::assertSame(PaymentRules::REVIEW, self::action(self::payment(['collector_id' => 999]), self::order()));
        $missing = self::payment();
        unset($missing['collector_id']);
        self::assertSame(PaymentRules::REVIEW, self::action($missing, self::order()));
        // MP may nest it as collector.id
        $nested = self::payment(['collector' => ['id' => 123456789]]);
        unset($nested['collector_id']);
        self::assertSame(PaymentRules::DELIVER, self::action($nested, self::order()));
    }

    public function testU17SecondApprovedPaymentForPaidOrderIsFlagged(): void
    {
        $paid = self::order(['status' => 'paid', 'payment_id' => '1111']);
        $result = PaymentRules::decide(self::payment(['id' => 2222]), $paid, self::env());
        self::assertSame(PaymentRules::REVIEW, $result['action']);
        self::assertSame('duplicate_payment', $result['reason']);
    }
}
