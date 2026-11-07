<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Catalog;
use Fenia\Api\Config;
use Fenia\Api\Db;
use Fenia\Api\DeliveryMail;
use Fenia\Api\MailSender;
use Fenia\Api\Money;
use Fenia\Api\MpClient;
use Fenia\Api\MpSignature;
use Fenia\Api\Shop;
use PHPUnit\Framework\TestCase;

/**
 * The whole sales flow with SQLite, a fake MP and a fake mailer. Test plan
 * U-10…U-21, U-30 at service level; S-13…S-18 logic before staging.
 */
final class ShopTest extends TestCase
{
    public const COLLECTOR = FakeMp::COLLECTOR;
    private const SECRET = 'webhook-secret';
    private const IP = '200.1.2.3';

    private \PDO $pdo;
    private FakeMp $mp;
    private FakeMailer $mailer;
    private string $kitsDir;
    private \DateTimeImmutable $now;
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->pdo = Db::connect('sqlite::memory:');
        Db::migrate($this->pdo);
        $this->mp = new FakeMp();
        $this->mailer = new FakeMailer();
        $this->now = new \DateTimeImmutable('2026-11-07 12:00:00', new \DateTimeZone('UTC'));
        $this->kitsDir = sys_get_temp_dir() . '/fenia-kits-' . bin2hex(random_bytes(4));
        mkdir($this->kitsDir);
        file_put_contents($this->kitsDir . '/kit-a.zip', 'PK-the-reviewed-kit');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->kitsDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->kitsDir);
    }

    /** @param array<string, mixed> $overrides */
    private function shop(array $overrides = []): Shop
    {
        $config = Config::fromArray(EnvConfigInboxTest::validConfig($overrides + [
            'env' => 'test',
            'site_url' => 'https://staging.example',
            'db_user' => 'u',
            'mp_webhook_secret' => self::SECRET,
            'mp_collector_id' => self::COLLECTOR,
            'mail_reply_to' => 'soporte@example.com',
            'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => hash_file('sha256', $this->kitsDir . '/kit-a.zip')]],
        ]), 'test');
        $catalog = Catalog::fromJson((string) json_encode(['products' => [
            ['id' => 'kit-a', 'title' => 'Kit <A> & más', 'status' => 'live', 'price' => 13000, 'currency' => 'ARS'],
            ['id' => 'kit-b', 'title' => 'Kit B', 'status' => 'coming-soon', 'price' => 13000, 'currency' => 'ARS'],
        ]]));
        return new Shop(
            $config,
            $catalog,
            $this->pdo,
            new MpClient('TEST-token', $this->mp),
            $this->mailer,
            $this->kitsDir,
            function (string $level, string $event, array $context): void {
                $this->logs[] = [$level, $event, $context];
            },
            fn (): \DateTimeImmutable => $this->now
        );
    }

    /** @return array<string, mixed> */
    private static function form(array $overrides = []): array
    {
        return $overrides + ['kit' => 'kit-a', 'email' => 'Ana@Example.com', 'email_confirm' => 'ana@example.com', 'accept_terms' => true, 'website' => ''];
    }

    /** @return array{0: Shop, 1: string, 2: string} shop, our reference, MP order id */
    private function checkedOut(): array
    {
        $shop = $this->shop();
        [$status] = $shop->checkout(self::form(), self::IP);
        self::assertSame(200, $status);
        $mpId = (string) array_key_last($this->mp->orders);
        return [$shop, (string) $this->mp->orders[$mpId]['external_reference'], $mpId];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function notify(Shop $shop, string $mpId, string $secret = self::SECRET, string $type = 'order'): array
    {
        $ts = '1762516800000';
        $requestId = 'req-' . bin2hex(random_bytes(4));
        $v1 = hash_hmac('sha256', MpSignature::manifest($mpId, $requestId, $ts), $secret);
        return $shop->webhook(
            ['data_id' => $mpId, 'type' => $type],
            'data.id=' . $mpId . '&type=' . $type,
            ['x-signature' => "ts=$ts,v1=$v1", 'x-request-id' => $requestId],
            '{"type":"' . $type . '","data":{"id":"' . $mpId . '"}}'
        );
    }

    /** @return array<string, mixed> */
    private function order(string $reference): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE reference = ?');
        $stmt->execute([$reference]);
        return $stmt->fetch();
    }

    private function events(): array
    {
        return array_map(static fn (array $l): string => $l[1], $this->logs);
    }

    // ── Checkout ────────────────────────────────────────────────────────────

    public function testCheckoutCreatesTheMpOrderFromTheCatalogue(): void
    {
        [$status, $body] = $this->shop()->checkout(self::form(['price' => 1]), self::IP);

        self::assertSame(200, $status);
        self::assertStringStartsWith('https://www.mercadopago.com/checkout/', $body['checkout_url']);
        $request = $this->mp->requests[0];
        $sent = $request['body'];
        $ref = $sent['external_reference'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $ref);
        self::assertSame($ref, $request['headers']['X-Idempotency-Key'], 'retries cannot create a second order');
        self::assertSame('13000.00', $sent['total_amount'], 'price from the catalogue, never from the browser');
        self::assertSame('13000.00', $sent['items'][0]['unit_price']);
        self::assertSame('kit-a', $sent['items'][0]['external_code']);
        self::assertSame(['online', 'manual', 'automatic'], [$sent['type'], $sent['processing_mode'], $sent['capture_mode']]);
        self::assertSame('PT60M', $sent['expiration_time']);
        self::assertSame(['ticket', 'atm'], $sent['config']['payment_method']['not_allowed_types'], 'D4');
        self::assertSame('https://staging.example/gracias/?ref=' . $ref, $sent['config']['online']['success_url']);
        self::assertSame('ana@example.com', $sent['payer']['email']);

        $order = $this->order($ref);
        self::assertSame('pending', $order['status']);
        self::assertSame(1300000, (int) $order['expected_cents']);
        self::assertSame('ORD01TEST1', $order['mp_order_id']);
        self::assertNotSame(self::IP, $order['client_ip_hash']);
    }

    public function testCheckoutRefusesBadInput(): void
    {
        $shop = $this->shop();
        self::assertSame(400, $shop->checkout(self::form(['website' => 'http://spam']), '1.1.1.1')[0], 'honeypot');
        self::assertSame(404, $shop->checkout(self::form(['kit' => 'kit-b']), '1.1.1.2')[0], 'coming soon');
        self::assertSame(404, $shop->checkout(self::form(['kit' => '../config']), '1.1.1.3')[0]);
        self::assertSame(404, $shop->checkout(self::form(['kit' => ['kit-a']]), '1.1.1.4')[0]);
        self::assertSame(400, $shop->checkout(self::form(['email_confirm' => 'otra@example.com']), '1.1.1.5')[0]);
        self::assertSame(400, $shop->checkout(self::form(['email' => "a@example.com\r\nBcc: x@y.z"]), '1.1.1.6')[0]);
        self::assertSame(400, $shop->checkout(self::form(['accept_terms' => 'true']), '1.1.1.7')[0], 'must be literally true');
        self::assertSame([], $this->mp->requests, 'nothing reached Mercado Pago');
    }

    public function testKillSwitchStopsCheckout(): void
    {
        self::assertSame(503, $this->shop(['sales_enabled' => false])->checkout(self::form(), self::IP)[0]);
        self::assertSame([], $this->mp->requests);
    }

    public function testCheckoutIsRateLimitedPerIpAndPerEmail(): void
    {
        $shop = $this->shop();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $shop->checkout(self::form(), self::IP)[0]);
        }
        self::assertSame(429, $shop->checkout(self::form(), '9.9.9.9')[0], 'sixth for the same email in the hour');
        for ($i = 0; $i < 5; $i++) {
            $shop->checkout(self::form(['email' => "b$i@example.com", 'email_confirm' => "b$i@example.com"]), self::IP);
        }
        self::assertSame(429, $shop->checkout(self::form(['email' => 'z@example.com', 'email_confirm' => 'z@example.com']), self::IP)[0], 'eleventh from the IP');
    }

    public function testInvalidRequestsDoNotSpendTheBuyersQuota(): void
    {
        $shop = $this->shop();
        for ($i = 0; $i < 30; $i++) {
            $shop->checkout(self::form(['kit' => 'kit-inexistente']), self::IP);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_events')->fetchColumn());
        self::assertSame(200, $shop->checkout(self::form(), self::IP)[0]);
    }

    public function testMpFailureOrForeignCheckoutUrlFailsTheOrder(): void
    {
        $this->mp->failWith = 500;
        self::assertSame(503, $this->shop()->checkout(self::form(), self::IP)[0]);
        self::assertSame('failed', $this->pdo->query('SELECT status FROM orders')->fetchColumn());

        $this->mp->failWith = null;
        $this->mp->checkoutHost = 'mercadopago.com.evil.example';
        self::assertSame(503, $this->shop()->checkout(self::form(['email' => 'c@example.com', 'email_confirm' => 'c@example.com']), '5.5.5.5')[0]);
    }

    public function testOnlyMercadoPagoCheckoutUrlsAreAccepted(): void
    {
        self::assertTrue(Shop::isMpCheckoutUrl('https://www.mercadopago.com/checkout/v1/redirect?order_id=X'));
        self::assertTrue(Shop::isMpCheckoutUrl('https://sandbox.mercadopago.com.ar/checkout/v1/redirect'));
        self::assertFalse(Shop::isMpCheckoutUrl('http://www.mercadopago.com/checkout'));
        self::assertFalse(Shop::isMpCheckoutUrl('https://mercadopago.com.evil.example/'));
        self::assertFalse(Shop::isMpCheckoutUrl('https://evilmercadopago.com/'));
        self::assertFalse(Shop::isMpCheckoutUrl('javascript:alert(1)'));
    }

    // ── Webhook → delivery ──────────────────────────────────────────────────

    public function testPaidWebhookDeliversExactlyOnce(): void
    {
        [$shop, $ref, $mpId] = $this->checkedOut();
        self::assertSame('ignored:not_final_created', $this->notify($shop, $mpId)[1]['result'], 'unpaid: nothing delivered');
        self::assertSame([], $this->mailer->sent);

        $this->mp->pay($mpId);
        self::assertSame([200, ['ok' => true, 'result' => 'delivered']], $this->notify($shop, $mpId));
        self::assertSame(['ok' => true, 'result' => 'ignored:already_delivered'], $this->notify($shop, $mpId)[1], 'MP retries');
        self::assertCount(1, $this->mailer->sent, 'one mail, one link');

        $mail = $this->mailer->sent[0];
        self::assertSame('ana@example.com', $mail['to']);
        self::assertSame('Tu kit FENIA: Kit <A> & más', $mail['subject']);
        self::assertStringContainsString('Kit &lt;A&gt; &amp; más', $mail['html'], 'title escaped in HTML');
        $order = $this->order($ref);
        self::assertSame('paid', $order['status']);
        self::assertNotNull($order['paid_at']);
        self::assertNotNull($order['email_sent_at']);
    }

    public function testWebhookRejectsBadSignaturesAndIgnoresOtherTopics(): void
    {
        [$shop, , $mpId] = $this->checkedOut();
        $this->mp->pay($mpId);
        self::assertSame(401, $this->notify($shop, $mpId, 'wrong-secret')[0]);
        self::assertSame(401, $shop->webhook(['data_id' => $mpId, 'type' => 'order'], '', [], '')[0], 'unsigned');
        self::assertSame('type', $this->notify($shop, $mpId, self::SECRET, 'payment')[1]['ignored']);
        self::assertSame([], $this->mailer->sent);

        $this->mp->failWith = 500;
        self::assertSame(502, $this->notify($shop, $mpId)[0], 'MP must retry later');
    }

    public function testForeignCollectorGoesToReviewNotDelivery(): void
    {
        [$shop, $ref, $mpId] = $this->checkedOut();
        $this->mp->pay($mpId);
        $this->mp->orders[$mpId]['user_id'] = '999';
        self::assertSame('review', $this->notify($shop, $mpId)[1]['result']);
        self::assertSame('review', $this->order($ref)['status']);
        self::assertSame([], $this->mailer->sent);
        self::assertContains('order_review', $this->events());
    }

    public function testDuplicatePaymentIsFlaggedButKeepsTheBuyersLink(): void
    {
        [$shop, $ref, $mpId, $token] = $this->delivered();
        $this->mp->orders['ORD01DUP'] = ['id' => 'ORD01DUP'] + $this->mp->orders[$mpId];
        self::assertSame('review', $this->notify($shop, 'ORD01DUP')[1]['result']);
        self::assertSame('paid', $this->order($ref)['status'], 'B5: refund the second payment by hand');
        self::assertSame(200, $shop->download($token, self::IP, null)['status']);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testUnderpaidOrderIsNotDelivered(): void
    {
        [$shop, $ref, $mpId] = $this->checkedOut();
        $this->mp->pay($mpId, '1.00');
        $this->notify($shop, $mpId);
        self::assertSame('review', $this->order($ref)['status']);
        self::assertSame([], $this->mailer->sent);
    }

    // ── /gracias ────────────────────────────────────────────────────────────

    public function testStatusPullsThePaymentWhenTheWebhookIsLate(): void
    {
        [$shop, $ref, $mpId] = $this->checkedOut();
        self::assertSame([200, ['state' => 'pending']], $shop->status($ref, self::IP));

        $this->mp->pay($mpId); // no webhook arrives
        $this->now = $this->now->modify('+10 seconds');
        [$status, $body] = $shop->status($ref, self::IP);
        self::assertSame(200, $status);
        self::assertSame('paid', $body['state']);
        self::assertSame('a***@example.com', $body['email_masked']);
        self::assertSame('/descarga/#t=' . $this->mailer->lastToken(), $body['download_url'], 'same link as the mail');

        $this->now = $this->now->modify('+25 hours');
        self::assertArrayNotHasKey('download_url', $shop->status($ref, self::IP)[1], 'after a day, only the mail has the link');
    }

    public function testStatusOfUnknownOrMalformedReferences(): void
    {
        $shop = $this->shop();
        self::assertSame(400, $shop->status('nope', self::IP)[0]);
        self::assertSame(404, $shop->status(str_repeat('f', 32), self::IP)[0]);
    }

    // ── Download ────────────────────────────────────────────────────────────

    /** @return array{0: Shop, 1: string, 2: string, 3: string} shop, ref, MP id, token */
    private function delivered(): array
    {
        [$shop, $ref, $mpId] = $this->checkedOut();
        $this->mp->pay($mpId);
        $this->notify($shop, $mpId);
        return [$shop, $ref, $mpId, $this->mailer->lastToken()];
    }

    public function testFiveDownloadsThenTheLinkIsSpent(): void
    {
        [$shop, , , $token] = $this->delivered();
        $info = $shop->downloadInfo($token, self::IP);
        self::assertSame([200, ['usable' => true, 'kit_title' => 'Kit <A> & más', 'remaining' => 5, 'max' => 5, 'expires_on' => '07/12/2026']], $info);
        self::assertSame(5, $shop->downloadInfo($token, self::IP)[1]['remaining'], 'looking counts nothing (C6)');

        for ($i = 1; $i <= 5; $i++) {
            $got = $shop->download($token, self::IP, 'Mozilla/5.0');
            self::assertSame(200, $got['status'], "download $i");
            self::assertSame('PK-the-reviewed-kit', file_get_contents($got['path']));
            self::assertSame('kit-a.zip', $got['filename']);
        }
        self::assertSame(['status' => 410], $shop->download($token, self::IP, null));
        self::assertSame([410, ['usable' => false]], $shop->downloadInfo($token, self::IP));
    }

    public function testExpiredLinkIsRefused(): void
    {
        [$shop, , , $token] = $this->delivered();
        $this->now = $this->now->modify('+30 days');
        self::assertSame(410, $shop->download($token, self::IP, null)['status']);
    }

    public function testATamperedZipIsNeverServedNorCounted(): void
    {
        [$shop, , , $token] = $this->delivered();
        file_put_contents($this->kitsDir . '/kit-a.zip', 'PK-something-else');
        self::assertSame(503, $shop->download($token, self::IP, null)['status']);
        self::assertContains('kit_file_problem', $this->events());
        self::assertSame(5, $shop->downloadInfo($token, self::IP)[1]['remaining']);
    }

    public function testGuessingLinksIsCappedPerIp(): void
    {
        $shop = $this->shop();
        self::assertSame(400, $shop->download('short', self::IP, null)['status']);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(404, $shop->download(str_repeat('A', 42) . chr(65 + $i % 26), self::IP, null)['status']);
        }
        self::assertSame(429, $shop->download(str_repeat('B', 43), self::IP, null)['status']);
        self::assertSame(404, $shop->download(str_repeat('B', 43), '7.7.7.7', null)['status'], 'other IPs unaffected');
    }

    public function testSharedLinkRaisesAnAlert(): void
    {
        [$shop, , , $token] = $this->delivered();
        foreach (['1.0.0.1', '1.0.0.2', '1.0.0.3', '1.0.0.4'] as $ip) {
            $shop->download($token, $ip, null);
        }
        self::assertContains('link_sharing', $this->events());
    }

    public function testRefundRevokesTheLink(): void
    {
        [$shop, $ref, $mpId, $token] = $this->delivered();
        $this->mp->orders[$mpId]['status'] = 'refunded';
        self::assertSame('revoked', $this->notify($shop, $mpId)[1]['result']);
        self::assertSame('revoked', $this->order($ref)['status']);
        self::assertSame(410, $shop->download($token, self::IP, null)['status']);
        self::assertSame(['state' => 'unknown'], $shop->status($ref, self::IP)[1]);
    }

    // ── "No me llegó" ───────────────────────────────────────────────────────

    public function testResendAnswersTheSameAndOnlyMailsTheBuyer(): void
    {
        [$shop, , , $token] = $this->delivered();
        self::assertSame([200, ['ok' => true]], $shop->resend(['email' => 'nadie@example.com'], self::IP));
        self::assertCount(1, $this->mailer->sent, 'no purchase, no mail');

        self::assertSame([200, ['ok' => true]], $shop->resend(['email' => ' ANA@example.com '], self::IP));
        self::assertCount(2, $this->mailer->sent);
        self::assertSame('ana@example.com', $this->mailer->sent[1]['to']);
        self::assertSame($token, $this->mailer->lastToken(), 'a working link is resent, not reset');

        self::assertSame([200, ['ok' => true]], $shop->resend(['email' => 'ana@example.com', 'website' => 'x'], self::IP), 'honeypot');
        self::assertCount(2, $this->mailer->sent);
    }

    public function testResendRenewsASpentLinkAtMostTwice(): void
    {
        [$shop, , , $first] = $this->delivered();
        $tokens = [$first];
        for ($round = 1; $round <= 3; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $shop->download(end($tokens), '3.3.3.' . $round, null);
            }
            $this->now = $this->now->modify('+1 day'); // resend allows 3 per email per day
            $shop->resend(['email' => 'ana@example.com'], '4.4.4.' . $round);
            $tokens[] = $this->mailer->lastToken();
        }
        self::assertCount(3, array_unique($tokens), 'first link + 2 renewals, then support');
        self::assertSame(410, $shop->download($tokens[1], '6.6.6.6', null)['status']);
        self::assertSame(410, $shop->download($tokens[2], '6.6.6.6', null)['status'], 'third link spent too');
        self::assertContains('renewals_exhausted', $this->events());
        self::assertSame(410, $shop->download($first, '6.6.6.6', null)['status'], 'old link stays dead');
    }

    public function testResendIsRateLimitedSilentlyPerEmail(): void
    {
        $this->delivered();
        $shop = $this->shop();
        for ($i = 0; $i < 3; $i++) {
            $shop->resend(['email' => 'ana@example.com'], '8.8.8.' . $i);
        }
        self::assertSame([200, ['ok' => true]], $shop->resend(['email' => 'ana@example.com'], '8.8.9.9'), 'no 429: it would reveal a buyer');
        self::assertCount(4, $this->mailer->sent, 'delivery + 3 resends');
    }

    // ── Mail failures and reconciliation ────────────────────────────────────

    public function testFailedMailKeepsTheOrderDeliverableAndIsRetried(): void
    {
        $this->mailer->fail = true;
        [$shop, $ref, $mpId] = $this->checkedOut();
        $this->mp->pay($mpId);
        self::assertSame('delivered', $this->notify($shop, $mpId)[1]['result']);
        self::assertNull($this->order($ref)['email_sent_at']);
        self::assertArrayHasKey('download_url', $shop->status($ref, self::IP)[1], '/gracias still has the link');

        $this->mailer->fail = false;
        self::assertSame(['synced' => 0, 'emailed' => 1, 'errors' => 0], $shop->reconcile());
        self::assertNotNull($this->order($ref)['email_sent_at']);
        self::assertSame(['synced' => 0, 'emailed' => 0, 'errors' => 0], $shop->reconcile(), 'nothing left');
    }

    public function testReconcileDeliversLostWebhooks(): void
    {
        [$shop, $ref, $mpId] = $this->checkedOut();
        $this->mp->pay($mpId);
        self::assertSame(['synced' => 1, 'emailed' => 0, 'errors' => 0], $shop->reconcile());
        self::assertSame('paid', $this->order($ref)['status']);
        self::assertCount(1, $this->mailer->sent);
    }

    // ── Small pieces ────────────────────────────────────────────────────────

    public function testHelpers(): void
    {
        self::assertSame('a***@gmail.com', Shop::maskEmail('ana@gmail.com'));
        self::assertSame('***', Shop::maskEmail('@x.com'));
        self::assertSame('13000.00', Money::fromCents(1300000));
        self::assertSame('0.05', Money::fromCents(5));
        self::assertSame('7999.99', Money::fromCents(799999));
        $mail = DeliveryMail::build('<b>Kit</b>', 'https://x.example/descarga/#t=abc"onmouseover="x', 'https://x.example', 'soporte@x.example', 30, 5);
        self::assertStringNotContainsString('<b>Kit</b>', $mail['html']);
        self::assertStringNotContainsString('"onmouseover="', $mail['html']);
        self::assertStringContainsString('30 días y permite 5 descargas', $mail['text']);
        self::assertTrue($this->shop()->kitsReady());
        self::assertFalse($this->shop(['kits' => ['kit-a' => ['file' => 'kit-missing.zip', 'sha256' => str_repeat('a', 64)]]])->kitsReady());
    }
}
