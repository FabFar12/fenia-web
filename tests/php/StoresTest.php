<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Db;
use Fenia\Api\OrderStore;
use Fenia\Api\RateLimiter;
use Fenia\Api\Tokens;
use Fenia\Api\TokenStore;
use PHPUnit\Framework\TestCase;

/**
 * Database layer on SQLite (the same SQL runs on Hostinger's MySQL; checked on
 * staging). Test plan U-10/U-18 (atomic transitions), U-21 and S-13/S-14
 * logic (download limits), U-30 (rate limiter).
 */
final class StoresTest extends TestCase
{
    private \PDO $pdo;
    private OrderStore $orders;
    private TokenStore $tokens;

    private const NOW = '2026-11-06 12:00:00';

    protected function setUp(): void
    {
        $this->pdo = TestDb::fresh();
        $this->orders = new OrderStore($this->pdo);
        $this->tokens = new TokenStore($this->pdo);
    }

    private function newOrder(string $email = 'ana@example.com'): int
    {
        return $this->orders->create([
            'reference' => Tokens::orderReference(),
            'kit_id' => 'kit-ensenar-con-ia',
            'email' => $email,
            'expected_cents' => 800000,
            'currency' => 'ARS',
            'client_ip_hash' => null,
        ], self::NOW);
    }

    public function testMigrationIsIdempotent(): void
    {
        Db::migrate($this->pdo);
        Db::migrate($this->pdo);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn());
        self::assertSame(Db::SCHEMA_VERSION, (int) $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
    }

    /** D25: a database of schema 1 (production since the launch) gets the new column and 10 downloads per link. */
    public function testMigrationFromSchema1RaisesLinksToTenAndLogsKinds(): void
    {
        [, , $old] = $this->paidOrderWithToken('2026-12-06 12:00:00', 5);
        $this->tokens->consume($this->pdo->query("SELECT token_hash FROM download_tokens WHERE id = $old")->fetchColumn(), self::NOW);
        $this->pdo->exec('ALTER TABLE download_log DROP COLUMN kind');
        $this->pdo->exec('DELETE FROM schema_version');
        $this->pdo->exec('INSERT INTO schema_version (version) VALUES (1)');

        Db::migrate($this->pdo);
        Db::migrate($this->pdo);

        $token = $this->pdo->query("SELECT max_downloads, download_count FROM download_tokens WHERE id = $old")->fetch();
        self::assertSame([10, 1], [(int) $token['max_downloads'], (int) $token['download_count']], 'raised, the download already made is kept');
        self::assertSame(Db::SCHEMA_VERSION, (int) $this->pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
        $this->tokens->logDownload($old, 'ip', null, self::NOW, 'fichas');
        self::assertSame('fichas', $this->pdo->query('SELECT kind FROM download_log')->fetchColumn());
    }

    public function testCreatesAndFindsOrders(): void
    {
        $id = $this->newOrder();
        $row = $this->pdo->query("SELECT * FROM orders WHERE id = $id")->fetch();
        self::assertSame('pending', $row['status']);
        self::assertSame($row['id'], $this->orders->findByReference($row['reference'])['id']);
        self::assertNull($this->orders->findByReference('ffffffffffffffffffffffffffffffff'));
    }

    public function testReferenceIsUnique(): void
    {
        $id = $this->newOrder();
        $reference = $this->orders->findByReference((string) $this->pdo->query("SELECT reference FROM orders WHERE id = $id")->fetchColumn())['reference'];
        $this->expectException(\PDOException::class);
        $this->orders->create(['reference' => $reference, 'kit_id' => 'k', 'email' => 'b@example.com', 'expected_cents' => 1, 'currency' => 'ARS', 'client_ip_hash' => null], self::NOW);
    }

    public function testBindsTheMpOrderOnlyOnce(): void
    {
        $id = $this->newOrder();
        self::assertTrue($this->orders->bindMpOrder($id, 'ORD01A', self::NOW));
        self::assertFalse($this->orders->bindMpOrder($id, 'ORD01B', self::NOW));
        self::assertSame((string) $id, (string) $this->orders->findByMpOrderId('ORD01A')['id']);
        self::assertNull($this->orders->findByMpOrderId('ORD01B'));
    }

    public function testU10U18TransitionIsCompareAndSet(): void
    {
        $id = $this->newOrder();
        self::assertTrue($this->orders->transition($id, 'pending', 'paid', 'accredited', self::NOW), 'first notification wins');
        self::assertFalse($this->orders->transition($id, 'pending', 'paid', 'accredited', self::NOW), 'duplicate loses: no second delivery');
        self::assertFalse($this->orders->transition($id, 'review', 'paid', null, self::NOW), 'wrong "from" never applies');
        self::assertTrue($this->orders->transition($id, 'paid', 'revoked', 'refunded', self::NOW));
    }

    public function testPaidByEmailOnlyReturnsPaidOrdersOfThatEmail(): void
    {
        $paid = $this->newOrder('ana@example.com');
        $this->newOrder('ana@example.com'); // pending
        $this->newOrder('otro@example.com');
        $this->orders->transition($paid, 'pending', 'paid', null, self::NOW);
        $rows = $this->orders->paidByEmail('ana@example.com');
        self::assertCount(1, $rows);
        self::assertSame((string) $paid, (string) $rows[0]['id']);
        self::assertSame([], $this->orders->paidByEmail('nadie@example.com'));
    }

    private function paidOrderWithToken(string $expiresAt = '2026-12-06 12:00:00', int $max = 5): array
    {
        $id = $this->newOrder();
        $this->orders->transition($id, 'pending', 'paid', null, self::NOW);
        $token = Tokens::generate();
        $tokenId = $this->tokens->issue($id, $token['hash'], $expiresAt, $max, self::NOW);
        return [$id, $token['hash'], $tokenId];
    }

    public function testU21FiveDownloadsThenTheSixthIsRefused(): void
    {
        [$orderId, $hash] = $this->paidOrderWithToken();
        for ($i = 1; $i <= 5; $i++) {
            $got = $this->tokens->consume($hash, self::NOW);
            self::assertNotNull($got, "download $i");
            self::assertSame('kit-ensenar-con-ia', $got['kit_id']);
            self::assertSame($orderId, $got['order_id']);
        }
        self::assertNull($this->tokens->consume($hash, self::NOW), 'sixth download');
    }

    public function testU21ExpiredTokenIsRefused(): void
    {
        [, $hash] = $this->paidOrderWithToken('2026-11-06 12:00:00');
        self::assertNull($this->tokens->consume($hash, '2026-11-06 12:00:00'));
        self::assertNull($this->tokens->consume($hash, '2026-11-07 00:00:00'));
    }

    public function testRevokedTokensAndUnpaidOrdersAreRefused(): void
    {
        [$orderId, $hash] = $this->paidOrderWithToken();
        self::assertSame(1, $this->tokens->revokeForOrder($orderId, self::NOW));
        self::assertNull($this->tokens->consume($hash, self::NOW), 'revoked after a refund');

        $pending = $this->newOrder();
        $token = Tokens::generate();
        $this->tokens->issue($pending, $token['hash'], '2026-12-06 12:00:00', 5, self::NOW);
        self::assertNull($this->tokens->consume($token['hash'], self::NOW), 'order not paid');

        self::assertNull($this->tokens->consume(hash('sha256', 'unknown'), self::NOW), 'unknown token');
    }

    public function testDownloadLogCountsDistinctIps(): void
    {
        [, , $tokenId] = $this->paidOrderWithToken();
        foreach (['ip-a', 'ip-b', 'ip-a', 'ip-c'] as $ip) {
            $this->tokens->logDownload($tokenId, hash('sha256', $ip), 'Mozilla/5.0', self::NOW);
        }
        self::assertSame(3, $this->tokens->distinctIps($tokenId));

        // "Tu kit": reading and the fichas from other IPs (a phone on 4G and on wifi) are not sharing.
        foreach (['open', 'read', 'fichas', 'imprimibles'] as $i => $kind) {
            $this->tokens->logDownload($tokenId, hash('sha256', 'ip-view-' . $i), null, self::NOW, $kind);
        }
        self::assertSame(3, $this->tokens->distinctIps($tokenId));
        $this->tokens->logDownload($tokenId, hash('sha256', 'ip-d'), null, self::NOW, 'guide');
        self::assertSame(4, $this->tokens->distinctIps($tokenId), 'the guide PDF is a download');
    }

    public function testFindsATokenByIdWithItsOrder(): void
    {
        [$orderId, $hash, $tokenId] = $this->paidOrderWithToken();
        $row = $this->tokens->findById($tokenId);
        self::assertSame($hash, $row['token_hash']);
        self::assertSame('kit-ensenar-con-ia', $row['kit_id']);
        self::assertSame('paid', $row['order_status']);
        self::assertSame('ana@example.com', $row['order_email']);
        self::assertSame(self::NOW, $row['order_paid_at']);
        self::assertSame($row, $this->tokens->findByHash($hash));
        self::assertSame($orderId, (int) $row['order_id']);
        self::assertNull($this->tokens->findById($tokenId + 100));
    }

    public function testU30RateLimiterBlocksAboveTheLimitAndForgetsAfterTheWindow(): void
    {
        $limiter = new RateLimiter($this->pdo);
        $salt = str_repeat('s', 32);
        $ip = RateLimiter::key('checkout-ip', '200.1.2.3', $salt);
        $t0 = new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'));

        self::assertTrue($limiter->allow($ip, 3, 3600, $t0));
        self::assertTrue($limiter->allow($ip, 3, 3600, $t0->modify('+1 minute')));
        self::assertTrue($limiter->allow($ip, 3, 3600, $t0->modify('+2 minutes')));
        self::assertFalse($limiter->allow($ip, 3, 3600, $t0->modify('+3 minutes')), 'fourth in the hour');
        self::assertTrue($limiter->allow(RateLimiter::key('checkout-ip', '200.1.2.4', $salt), 3, 3600, $t0), 'other IP unaffected');
        self::assertTrue($limiter->allow($ip, 3, 3600, $t0->modify('+61 minutes')), 'window passed');
    }

    public function testRateLimitKeysAreKeyedAndNeverRaw(): void
    {
        $a = RateLimiter::key('checkout-ip', '200.1.2.3', str_repeat('s', 32));
        self::assertStringNotContainsString('200.1.2.3', $a);
        self::assertNotSame($a, RateLimiter::key('checkout-ip', '200.1.2.3', str_repeat('t', 32)), 'salt matters');
        self::assertSame($a, RateLimiter::key('checkout-ip', ' 200.1.2.3 ', str_repeat('s', 32)), 'normalized');
    }
}
