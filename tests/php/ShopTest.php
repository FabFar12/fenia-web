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
        $this->pdo = TestDb::fresh();
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
    private function config(array $overrides = []): Config
    {
        return Config::fromArray(EnvConfigInboxTest::validConfig($overrides + [
            'env' => 'test',
            'site_url' => 'https://staging.example',
            'db_user' => 'u',
            'mp_webhook_secret' => self::SECRET,
            'mp_collector_id' => self::COLLECTOR,
            'mail_reply_to' => 'soporte@example.com',
            'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => hash_file('sha256', $this->kitsDir . '/kit-a.zip')]],
        ]), 'test');
    }

    /** @param array<string, mixed> $overrides */
    private function shop(array $overrides = []): Shop
    {
        $config = $this->config($overrides);
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

    public function testCheckoutChargesThePromoPriceOnlyWhileItRuns(): void
    {
        $catalog = Catalog::fromJson((string) json_encode(['products' => [
            ['id' => 'kit-a', 'title' => 'Kit A', 'status' => 'live', 'price' => 30000, 'currency' => 'ARS',
             'promo' => ['price' => 13000, 'label' => 'Precio de lanzamiento', 'starts' => '2026-11-07T03:00:00.000Z', 'ends' => '2026-11-15T02:59:59.000Z']],
        ]]));
        $shop = fn (): Shop => new Shop($this->config(), $catalog, $this->pdo, new MpClient('TEST-token', $this->mp), $this->mailer, $this->kitsDir,
            static function (): void {}, fn (): \DateTimeImmutable => $this->now);

        $this->now = new \DateTimeImmutable('2026-11-15 02:59:58', new \DateTimeZone('UTC'));
        $shop()->checkout(self::form(), '1.1.1.1');
        self::assertSame('13000.00', end($this->mp->requests)['body']['total_amount'], 'last second of the promo');

        $this->now = new \DateTimeImmutable('2026-11-15 02:59:59', new \DateTimeZone('UTC'));
        $shop()->checkout(self::form(['email' => 'b@example.com', 'email_confirm' => 'b@example.com']), '1.1.1.2');
        $sent = end($this->mp->requests)['body'];
        self::assertSame('30000.00', $sent['total_amount'], 'promo over: regular price, with no deploy');
        self::assertSame('30000.00', $sent['items'][0]['unit_price']);
        $expected = $this->pdo->query('SELECT expected_cents FROM orders ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([1300000, 3000000], array_map('intval', $expected), 'each order expects what it was created at');
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

        self::assertSame([200, ['ok' => true, 'ignored' => 'not_found']], $this->notify($shop, 'ORD01NOTOURS'), 'S-03: nothing to retry');

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

    public function testTenDownloadsThenTheLinkIsSpent(): void
    {
        [$shop, , , $token] = $this->delivered();
        $info = $shop->downloadInfo($token, self::IP);
        self::assertSame([200, ['usable' => true, 'kit_title' => 'Kit <A> & más', 'remaining' => 10, 'max' => 10, 'expires_on' => '07/12/2026', 'guide' => false]], $info);
        self::assertSame(10, $shop->downloadInfo($token, self::IP)[1]['remaining'], 'looking counts nothing (C6)');

        for ($i = 1; $i <= 10; $i++) {
            $got = $shop->download($token, self::IP, 'Mozilla/5.0');
            self::assertSame(200, $got['status'], "download $i");
            self::assertSame('PK-the-reviewed-kit', file_get_contents($got['path']));
            self::assertSame('kit-a.zip', $got['filename']);
        }
        self::assertSame(['status' => 410], $shop->download($token, self::IP, null));
        self::assertSame([410, ['usable' => false]], $shop->downloadInfo($token, self::IP));
    }

    public function testTheGuidePdfDownloadsOnItsOwnAndCountsTowardTheLimit(): void
    {
        file_put_contents($this->kitsDir . '/kit-a-guia.pdf', '%PDF-1.7 guía');
        $guide = ['file' => 'kit-a-guia.pdf', 'sha256' => hash_file('sha256', $this->kitsDir . '/kit-a-guia.pdf')];
        $kits = ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => hash_file('sha256', $this->kitsDir . '/kit-a.zip'), 'guide' => $guide]];
        [, , , $token] = $this->delivered();
        $shop = $this->shop(['kits' => $kits]);

        self::assertTrue($shop->downloadInfo($token, self::IP)[1]['guide']);
        $got = $shop->download($token, self::IP, null, 'guide');
        self::assertSame(200, $got['status']);
        self::assertSame('kit-a-guia.pdf', $got['filename']);
        self::assertSame('%PDF-1.7 guía', file_get_contents($got['path']));
        self::assertSame('kit-a.zip', $shop->download($token, self::IP, null)['filename']);
        self::assertSame(8, $shop->downloadInfo($token, self::IP)[1]['remaining'], 'guide + zip = 2 of 10');
        self::assertTrue($shop->kitsReady());

        file_put_contents($this->kitsDir . '/kit-a-guia.pdf', '%PDF tampered');
        self::assertSame(503, $shop->download($token, self::IP, null, 'guide')['status'], 'a changed guide is never served');
        self::assertSame(8, $shop->downloadInfo($token, self::IP)[1]['remaining'], 'nor counted');
        self::assertSame(404, $this->shop()->download($token, self::IP, null, 'guide')['status'], 'kit without a guide');
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
        self::assertSame(10, $shop->downloadInfo($token, self::IP)[1]['remaining']);
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

    // ── "Tu kit" (D25) ──────────────────────────────────────────────────────

    /** kit-a with its guide, fichas and printables; kit-b with fichas only. */
    private function tuKitConfig(): array
    {
        $files = [
            'kit-a-guia.pdf' => '%PDF-1.7 guía',
            'kit-a-fichas.html' => '<!doctype html><title>Fichas</title>',
            'kit-a-imprimibles.html' => '<!doctype html><title>Imprimibles</title>',
        ];
        foreach ($files as $name => $content) {
            file_put_contents($this->kitsDir . '/' . $name, $content);
        }
        $sha = fn (string $file): string => (string) hash_file('sha256', $this->kitsDir . '/' . $file);
        return ['kits' => [
            'kit-a' => [
                'file' => 'kit-a.zip', 'sha256' => $sha('kit-a.zip'),
                'guide' => ['file' => 'kit-a-guia.pdf', 'sha256' => $sha('kit-a-guia.pdf'), 'pages' => 37],
                'fichas' => ['file' => 'kit-a-fichas.html', 'sha256' => $sha('kit-a-fichas.html'), 'count' => 8],
                'imprimibles' => ['file' => 'kit-a-imprimibles.html', 'sha256' => $sha('kit-a-imprimibles.html'), 'count' => 8],
            ],
            'kit-b' => [
                'file' => 'kit-a.zip', 'sha256' => $sha('kit-a.zip'),
                'fichas' => ['file' => 'kit-a-fichas.html', 'sha256' => $sha('kit-a-fichas.html'), 'count' => 8],
            ],
        ]];
    }

    /** @return array{0: Shop, 1: string, 2: string, 3: string, 4: string} shop, ref, MP id, token, view pass */
    private function openedKit(): array
    {
        [, $ref, $mpId, $token] = $this->delivered();
        $shop = $this->shop($this->tuKitConfig());
        $opened = $shop->openKit($token, self::IP, 'Mozilla/5.0');
        self::assertSame(200, $opened[0]);
        return [$shop, $ref, $mpId, $token, $opened[2]['value']];
    }

    /** @return list<string> */
    private function loggedKinds(): array
    {
        return array_map('strval', $this->pdo->query('SELECT kind FROM download_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testTuKitOpensWithAViewPassAndCountsNothing(): void
    {
        [, , , $token] = $this->delivered();
        $shop = $this->shop($this->tuKitConfig());

        [$status, $body, $pass] = $shop->openKit($token, self::IP, 'Mozilla/5.0');
        self::assertSame(200, $status);
        self::assertEquals([
            'usable' => true,
            'kit' => 'kit-a',
            'kit_title' => 'Kit <A> & más',
            'remaining' => 10,
            'max' => 10,
            'expires_on' => '07/12/2026',
            'bought_on' => '7/11',
            'email_masked' => 'a***@example.com',
            'resources' => (object) ['guide' => ['size' => 37], 'fichas' => ['size' => 8], 'imprimibles' => ['size' => 8]],
        ], $body);
        self::assertSame('fk_kit-a', $pass['name']);
        self::assertSame(30 * 86400, $pass['max_age'], 'lives as long as the link');

        $fichas = $shop->kitResource('kit-a', 'fichas', $pass['value'], self::IP, null);
        self::assertSame(['status' => 200, 'path' => $this->kitsDir . '/kit-a-fichas.html', 'filename' => 'kit-a-fichas.html', 'type' => 'text/html; charset=utf-8'], $fichas);
        $guide = $shop->kitResource('kit-a', 'guide', $pass['value'], self::IP, null);
        self::assertSame(['application/pdf', '%PDF-1.7 guía'], [$guide['type'], file_get_contents($guide['path'])]);
        self::assertSame(200, $shop->kitResource('kit-a', 'imprimibles', $pass['value'], self::IP, null)['status']);

        self::assertSame(10, $shop->openKit($token, self::IP, null, true)[1]['remaining'], 'reading and the fichas count nothing');
        self::assertSame(['open', 'fichas', 'read', 'imprimibles'], $this->loggedKinds(), 'a refresh is not a new visit');
    }

    public function testTuKitStillOpensWhenTheDownloadsRunOut(): void
    {
        [$shop, , , $token, $pass] = $this->openedKit();
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $shop->download($token, self::IP, null, $i % 2 === 0 ? 'zip' : 'guide')['status']);
        }
        self::assertSame(410, $shop->download($token, self::IP, null)['status']);
        [$status, $body] = $shop->openKit($token, self::IP, null);
        self::assertSame([200, 0], [$status, $body['remaining']]);
        self::assertSame(200, $shop->kitResource('kit-a', 'fichas', $pass, self::IP, null)['status'], 'reading stays free (D25)');
        self::assertSame(410, $shop->downloadInfo($token, self::IP)[0], 'the old download page still says it is spent');
    }

    public function testTheViewPassOpensOnlyItsKitAndCannotBeForged(): void
    {
        [$shop, , , , $pass] = $this->openedKit();
        self::assertSame(403, $shop->kitResource('kit-a', 'fichas', null, self::IP, null)['status'], 'no pass');
        self::assertSame(403, $shop->kitResource('kit-b', 'fichas', $pass, self::IP, null)['status'], 'pass of another kit');
        [$id, $until, $mac] = explode('.', $pass);
        self::assertSame(403, $shop->kitResource('kit-a', 'fichas', ((int) $id + 1) . ".$until.$mac", self::IP, null)['status'], 'another link');
        self::assertSame(403, $shop->kitResource('kit-a', 'fichas', "$id." . ((int) $until + 86400) . ".$mac", self::IP, null)['status'], 'a longer life');
        self::assertSame(404, $shop->kitResource('kit-a', 'zip', $pass, self::IP, null)['status'], 'only the pages of the kit');
        self::assertSame(404, $shop->kitResource('../kit-a', 'fichas', $pass, self::IP, null)['status']);
        self::assertSame(404, $shop->kitResource('kit-b', 'guide', $pass, self::IP, null)['status'], 'kit-b has no guide');

        $this->now = $this->now->modify('+30 days');
        self::assertSame(403, $shop->kitResource('kit-a', 'fichas', $pass, self::IP, null)['status'], 'expires with the link');
    }

    public function testForgedPassesAreCappedPerIp(): void
    {
        [$shop, , , , $pass] = $this->openedKit();
        $forged = substr($pass, 0, -1) . (substr($pass, -1) === 'A' ? 'B' : 'A');
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(403, $shop->kitResource('kit-a', 'fichas', $forged, self::IP, null)['status']);
        }
        self::assertSame(429, $shop->kitResource('kit-a', 'fichas', $forged, self::IP, null)['status']);
        self::assertSame(200, $shop->kitResource('kit-a', 'fichas', $pass, '7.7.7.7', null)['status'], 'other IPs unaffected');
    }

    public function testARefundClosesTuKitAtOnce(): void
    {
        [$shop, , $mpId, $token, $pass] = $this->openedKit();
        $this->mp->orders[$mpId]['status'] = 'refunded';
        self::assertSame('revoked', $this->notify($shop, $mpId)[1]['result']);
        self::assertSame(410, $shop->kitResource('kit-a', 'fichas', $pass, self::IP, null)['status']);
        self::assertSame(410, $shop->openKit($token, self::IP, null)[0]);
    }

    public function testTuKitServesOnlyTheReviewedFiles(): void
    {
        [$shop, , , , $pass] = $this->openedKit();
        file_put_contents($this->kitsDir . '/kit-a-fichas.html', '<script>changed()</script>');
        self::assertSame(503, $shop->kitResource('kit-a', 'fichas', $pass, self::IP, null)['status']);
        self::assertContains('kit_file_problem', $this->events());
        self::assertTrue($shop->kitsReady());
        unlink($this->kitsDir . '/kit-a-imprimibles.html');
        self::assertFalse($shop->kitsReady(), 'health shows a missing page');
    }

    public function testOpeningTheKitFromManyPlacesIsNotSharing(): void
    {
        [, , , $token] = $this->delivered();
        $shop = $this->shop($this->tuKitConfig());
        foreach (['1.0.0.1', '1.0.0.2', '1.0.0.3', '1.0.0.4', '1.0.0.5'] as $ip) {
            $pass = $shop->openKit($token, $ip, null)[2]['value'];
            self::assertSame(200, $shop->kitResource('kit-a', 'fichas', $pass, $ip, null)['status']);
        }
        self::assertNotContains('link_sharing', $this->events());
    }

    public function testKitsWithFichasGetTheTuKitMail(): void
    {
        $shop = $this->shop($this->tuKitConfig());
        self::assertSame(200, $shop->checkout(self::form(), self::IP)[0]);
        $mpId = (string) array_key_last($this->mp->orders);
        $this->mp->pay($mpId);
        $this->notify($shop, $mpId);
        $mail = $this->mailer->sent[0];
        self::assertStringContainsString('Abrir mi kit', $mail['html']);
        self::assertStringContainsString('completar las fichas', $mail['text']);
        self::assertStringContainsString('las descargas de archivos son hasta 10', $mail['text']);
        self::assertStringNotContainsString('te conviene la compu', $mail['text']);
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
            for ($i = 0; $i < 10; $i++) {
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

    public function testABouncingAddressAlertsOnceAndStopsAfterTwoDays(): void
    {
        $this->mailer->fail = true;
        [$shop, , $mpId] = $this->checkedOut();
        $this->mp->pay($mpId);
        $this->notify($shop, $mpId);
        $levels = fn (): array => array_values(array_map(static fn (array $l): string => $l[0], array_filter($this->logs, static fn (array $l): bool => $l[1] === 'mail_failed')));

        $this->now = $this->now->modify('+1 hour');
        self::assertSame(1, $shop->reconcile()['errors'], 'retried');
        self::assertSame(['error', 'warning'], $levels(), 'alerted once, then only logged');

        $this->now = $this->now->modify('+48 hours');
        self::assertSame(['synced' => 0, 'emailed' => 0, 'errors' => 0], $shop->reconcile(), 'given up after 2 days');
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
        self::assertStringContainsString('El kit viene en un ZIP', $mail['text'], 'without a guide');
        self::assertStringContainsString('kit completo en un ZIP', DeliveryMail::build('Kit', 'https://x.example/descarga/#t=a', 'https://x.example', 's@x.example', 30, 5, true)['html']);
        self::assertTrue($this->shop()->kitsReady());
        self::assertFalse($this->shop(['kits' => ['kit-a' => ['file' => 'kit-missing.zip', 'sha256' => str_repeat('a', 64)]]])->kitsReady());
    }
}
