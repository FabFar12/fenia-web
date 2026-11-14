<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\MailSender;

// Test doubles shared by ShopTest and CronTest (loaded by bootstrap.php).

/** Mercado Pago's Orders API, in memory. */
final class FakeMp
{
    public const COLLECTOR = '5550001111';

    /** @var array<string, array<string, mixed>> */
    public array $orders = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}> */
    public array $requests = [];
    public ?int $failWith = null;
    public string $checkoutHost = 'www.mercadopago.com';

    /** @param array<string, string> $headers */
    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $decoded = $body === null ? null : json_decode($body, true);
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $decoded];
        if ($this->failWith !== null) {
            return ['status' => $this->failWith, 'body' => '{"message":"error"}'];
        }
        if ($method === 'POST') {
            $id = 'ORD01TEST' . (count($this->orders) + 1);
            $this->orders[$id] = [
                'id' => $id,
                'external_reference' => $decoded['external_reference'],
                'user_id' => self::COLLECTOR,
                'status' => 'created',
                'currency' => 'ARS',
                'total_amount' => $decoded['total_amount'],
                'checkout_url' => 'https://' . $this->checkoutHost . '/checkout/v1/redirect?order_id=' . $id,
            ];
            return ['status' => 201, 'body' => (string) json_encode($this->orders[$id])];
        }
        $id = substr($url, strrpos($url, '/') + 1);
        return isset($this->orders[$id])
            ? ['status' => 200, 'body' => (string) json_encode($this->orders[$id])]
            : ['status' => 404, 'body' => '{"message":"not found"}'];
    }

    /** The buyer pays in Checkout Pro. */
    public function pay(string $id, string $paid = '13000.00'): void
    {
        $this->orders[$id]['status'] = 'processed';
        $this->orders[$id]['status_detail'] = 'accredited';
        $this->orders[$id]['total_paid_amount'] = $paid;
    }
}

final class FakeMailer implements MailSender
{
    /** @var list<array{to: string, subject: string, text: string, html: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $text, string $html): void
    {
        if ($this->fail) {
            throw new \RuntimeException('smtp down');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
    }

    public function lastToken(): string
    {
        $last = end($this->sent);
        self::assertMatches('~/descarga/#t=([A-Za-z0-9_-]{43})~', $last === false ? '' : $last['text'], $m);
        return $m[1];
    }

    /** @param array<int, string> $m */
    private static function assertMatches(string $pattern, string $text, ?array &$m): void
    {
        if (!preg_match($pattern, $text, $m)) {
            throw new \RuntimeException('No download link in the mail');
        }
    }
}

/**
 * A clean database for each test: MariaDB in CI (FENIA_TEST_DSN, same engine
 * as Hostinger), SQLite in memory otherwise. SQLite alone missed a MariaDB-only
 * collation error (2026-11-07), hence the real engine in CI.
 */
final class TestDb
{
    public static function fresh(): \PDO
    {
        $dsn = (string) getenv('FENIA_TEST_DSN');
        if ($dsn === '') {
            $pdo = \Fenia\Api\Db::connect('sqlite::memory:');
        } else {
            $pdo = \Fenia\Api\Db::connect($dsn, (string) getenv('FENIA_TEST_DB_USER'), (string) getenv('FENIA_TEST_DB_PASSWORD'));
            foreach (['orders', 'download_tokens', 'download_log', 'rate_events', 'schema_version', 'site_events', 'sessions', 'speed_checks'] as $table) {
                $pdo->exec("DROP TABLE IF EXISTS $table");
            }
        }
        \Fenia\Api\Db::migrate($pdo);
        return $pdo;
    }
}
