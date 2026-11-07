<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\OrderRules;
use PHPUnit\Framework\TestCase;

/**
 * Test plan U-10…U-17 (threat model A8–A13, B5, C3, C4, C17) on the Orders API
 * (D14). The decision part of U-10/U-18; the atomic transition in the database
 * is covered by OrderStoreTest.
 */
final class OrderRulesTest extends TestCase
{
    private const REF = '0123456789abcdef0123456789abcdef';
    private const COLLECTOR = '5550001111';
    private const MP_ORDER = 'ORD01JQ4S4KY8HWQ6NA5PXB65B3D3';

    /** @param array<string, mixed> $overrides */
    private static function mpOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => self::MP_ORDER,
            'type' => 'online',
            'status' => 'processed',
            'status_detail' => 'accredited',
            'total_amount' => '8000.00',
            'total_paid_amount' => '8000.00',
            'currency' => 'ARS',
            'external_reference' => self::REF,
            'user_id' => self::COLLECTOR,
            'transactions' => ['payments' => [['id' => 'PAY01X', 'status' => 'processed', 'status_detail' => 'accredited', 'amount' => '8000.00']]],
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
            'mp_order_id' => self::MP_ORDER,
        ], $overrides);
    }

    private static function decide(array $mpOrder, array $order, string $collector = self::COLLECTOR): array
    {
        return OrderRules::decide($mpOrder, $order, ['collector_id' => $collector]);
    }

    private static function action(array $mpOrder, array $order): string
    {
        return self::decide($mpOrder, $order)['action'];
    }

    public function testDeliversProcessedAccreditedOrder(): void
    {
        self::assertSame(['action' => OrderRules::DELIVER, 'reason' => 'accredited'], self::decide(self::mpOrder(), self::order()));
    }

    public function testU10SameOrderNotifiedAgainIsIgnored(): void
    {
        $result = self::decide(self::mpOrder(), self::order(['status' => 'paid']));
        self::assertSame(['action' => OrderRules::IGNORE, 'reason' => 'already_delivered'], $result);
    }

    /** @return iterable<string, array{0: mixed, 1: mixed, 2: string}> */
    public static function amounts(): iterable
    {
        yield 'exact' => ['8000.00', '8000.00', OrderRules::DELIVER];
        yield 'exact as number' => [8000, 8000, OrderRules::DELIVER];
        yield 'buyer paid installment interest' => ['8000.00', '8640.00', OrderRules::DELIVER];
        yield 'total one cent short' => ['7999.99', '7999.99', OrderRules::REVIEW];
        yield 'total one peso' => ['1.00', '1.00', OrderRules::REVIEW];
        yield 'total higher' => ['8000.01', '8000.01', OrderRules::REVIEW];
        yield 'underpaid' => ['8000.00', '7000.00', OrderRules::REVIEW];
        yield 'paid missing' => ['8000.00', null, OrderRules::REVIEW];
        yield 'total missing' => [null, '8000.00', OrderRules::REVIEW];
        yield 'garbage' => ['8000abc', '8000.00', OrderRules::REVIEW];
    }

    /** @dataProvider amounts */
    public function testU11AmountsMustMatch($total, $paid, string $expected): void
    {
        $mp = self::mpOrder(['total_amount' => $total, 'total_paid_amount' => $paid]);
        self::assertSame($expected, self::action($mp, self::order()));
    }

    public function testU11CurrencyMustMatch(): void
    {
        foreach (['USD', 'UYU', 'BRL', '', null] as $currency) {
            self::assertSame(OrderRules::REVIEW, self::action(self::mpOrder(['currency' => $currency]), self::order()), var_export($currency, true));
        }
    }

    public function testU12OrderForAnotherReferenceNeverDelivers(): void
    {
        foreach (['ffffffffffffffffffffffffffffffff', '', null] as $ref) {
            self::assertSame(OrderRules::IGNORE, self::action(self::mpOrder(['external_reference' => $ref]), self::order()), var_export($ref, true));
        }
    }

    public function testU12UnknownMpOrderForPendingOrderGoesToReview(): void
    {
        // Our order is bound to one MP order at checkout; a different one never delivers.
        self::assertSame(OrderRules::REVIEW, self::action(self::mpOrder(['id' => 'ORD01OTHER']), self::order()));
        self::assertSame(OrderRules::REVIEW, self::action(self::mpOrder(), self::order(['mp_order_id' => null])));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nonDeliveringStatuses(): iterable
    {
        yield 'created' => ['created', 'created'];
        yield 'processing' => ['processing', 'in_process'];
        yield 'manual review' => ['processing', 'pending_review_manual'];
        yield 'authorized, not captured' => ['action_required', 'waiting_capture'];
        yield 'failed' => ['failed', 'cc_rejected_other_reason'];
        yield 'canceled' => ['canceled', 'canceled'];
        yield 'refunded' => ['refunded', 'refunded'];
        yield 'opened (other order types)' => ['opened', ''];
        yield 'expired' => ['expired', ''];
        yield 'unknown' => ['something_new', ''];
        yield 'empty' => ['', ''];
    }

    /** @dataProvider nonDeliveringStatuses */
    public function testU13OnlyProcessedAccreditedDelivers(string $status, string $detail): void
    {
        $mp = self::mpOrder(['status' => $status, 'status_detail' => $detail]);
        self::assertNotSame(OrderRules::DELIVER, self::action($mp, self::order()));
    }

    public function testU13ProcessedButNotAccreditedGoesToReview(): void
    {
        $mp = self::mpOrder(['status_detail' => 'partially_refunded']);
        self::assertSame(OrderRules::REVIEW, self::action($mp, self::order()));
        self::assertSame(OrderRules::REVIEW, self::action($mp, self::order(['status' => 'paid'])));
    }

    public function testU14RefundRevokesDeliveredOrder(): void
    {
        $result = self::decide(self::mpOrder(['status' => 'refunded', 'status_detail' => 'refunded']), self::order(['status' => 'paid']));
        self::assertSame(['action' => OrderRules::REVOKE, 'reason' => 'refunded'], $result);
    }

    public function testU14ChargebackRevokesDeliveredOrder(): void
    {
        $mp = self::mpOrder(['transactions' => ['payments' => [], 'chargebacks' => [['id' => 'CHB01X']]]]);
        self::assertSame(['action' => OrderRules::REVOKE, 'reason' => 'chargeback'], self::decide($mp, self::order(['status' => 'paid'])));
        // A chargeback on something never delivered still needs a human.
        self::assertSame(OrderRules::REVIEW, self::action($mp, self::order()));
    }

    public function testU14RefundOfUndeliveredOrderIsIgnored(): void
    {
        self::assertSame(OrderRules::IGNORE, self::action(self::mpOrder(['status' => 'refunded']), self::order()));
    }

    public function testU14RefundOfAnotherMpOrderDoesNotRevoke(): void
    {
        $paidOther = self::order(['status' => 'paid', 'mp_order_id' => 'ORD01OTHER']);
        self::assertSame(OrderRules::IGNORE, self::action(self::mpOrder(['status' => 'refunded']), $paidOther));
    }

    public function testU14OutOfOrderStatusAfterPaidChangesNothing(): void
    {
        foreach (['created', 'processing', 'action_required', 'canceled'] as $status) {
            self::assertSame(OrderRules::IGNORE, self::action(self::mpOrder(['status' => $status]), self::order(['status' => 'paid'])), $status);
        }
    }

    public function testU14PaidAgainAfterRevokeNeedsAHuman(): void
    {
        self::assertSame(OrderRules::REVIEW, self::action(self::mpOrder(), self::order(['status' => 'revoked'])));
    }

    public function testOrderInReviewIsNeverAutoDelivered(): void
    {
        self::assertSame(OrderRules::IGNORE, self::action(self::mpOrder(), self::order(['status' => 'review'])));
    }

    public function testU15SandboxOrderNeverCountsInProduction(): void
    {
        // Production's collector is the real account; a sandbox order belongs to the test seller.
        self::assertSame(OrderRules::REVIEW, self::decide(self::mpOrder(), self::order(), '123456789')['action']);
    }

    public function testU16ForeignOrMissingCollectorGoesToReview(): void
    {
        self::assertSame(OrderRules::REVIEW, self::action(self::mpOrder(['user_id' => '999']), self::order()));
        $missing = self::mpOrder();
        unset($missing['user_id']);
        self::assertSame(OrderRules::REVIEW, self::action($missing, self::order()));
    }

    public function testU17SecondMpOrderForPaidPurchaseIsFlagged(): void
    {
        $result = self::decide(self::mpOrder(['id' => 'ORD01SECOND']), self::order(['status' => 'paid']));
        self::assertSame(['action' => OrderRules::REVIEW, 'reason' => 'duplicate_payment'], $result);
    }
}
