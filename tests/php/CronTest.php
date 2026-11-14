<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Catalog;
use Fenia\Api\Config;
use Fenia\Api\Cron;
use Fenia\Api\Db;
use Fenia\Api\MpClient;
use Fenia\Api\RateLimiter;
use Fenia\Api\Shop;
use PHPUnit\Framework\TestCase;

/** Hourly job (step 3.4): reconciliation, alerts, daily report. */
final class CronTest extends TestCase
{
    private \PDO $pdo;
    private FakeMp $mp;
    private FakeMailer $mailer;
    private string $dir;
    private \DateTimeImmutable $now;
    /** @var list<string> pages the speed check asked Google about (never the real network) */
    private array $speedAsked = [];

    protected function setUp(): void
    {
        $this->pdo = TestDb::fresh();
        $this->mp = new FakeMp();
        $this->mailer = new FakeMailer();
        $this->dir = sys_get_temp_dir() . '/fenia-cron-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/logs', 0700, true);
        mkdir($this->dir . '/kits');
        file_put_contents($this->dir . '/kits/kit-a.zip', 'PK');
        $this->now = new \DateTimeImmutable('2026-11-07 10:00:00', new \DateTimeZone('UTC')); // 07:00 in Argentina
    }

    protected function tearDown(): void
    {
        foreach (['logs/*', 'kits/*', '*'] as $pattern) {
            foreach (glob($this->dir . '/' . $pattern) ?: [] as $path) {
                is_dir($path) ? rmdir($path) : unlink($path);
            }
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        return Config::fromArray(EnvConfigInboxTest::validConfig($overrides + [
            'env' => 'test',
            'db_user' => 'u',
            'mp_collector_id' => FakeMp::COLLECTOR,
            'alert_email' => 'alertas@example.com',
            'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => hash('sha256', 'PK')]],
        ]), 'test');
    }

    private function shop(Config $config): Shop
    {
        $catalog = Catalog::fromJson('{"products":[{"id":"kit-a","title":"Kit A","status":"live","price":13000,"currency":"ARS"}]}');
        return new Shop($config, $catalog, $this->pdo, new MpClient('TEST-token', $this->mp), $this->mailer, $this->dir . '/kits',
            static function (): void {}, fn (): \DateTimeImmutable => $this->now);
    }

    /** @param array<string, mixed> $overrides */
    private function cron(array $overrides = []): Cron
    {
        $config = $this->config($overrides);
        return new Cron($this->shop($config), $this->pdo, $this->mailer, $config, $this->dir, fn (): \DateTimeImmutable => $this->now,
            function (string $url): array {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->speedAsked[] = (string) $query['url'];
                return ['status' => 200, 'body' => '{"lighthouseResult":{"categories":{"performance":{"score":0.9}}}}'];
            });
    }

    /** @param array<string, mixed> $event */
    private function log(array $event): void
    {
        file_put_contents($this->dir . '/logs/api-2026-11.log', json_encode(['ts' => '2026-11-07T09:00:00Z'] + $event) . "\n", FILE_APPEND);
    }

    /** @return list<string> */
    private function subjects(): array
    {
        return array_map(static fn (array $m): string => $m['subject'], $this->mailer->sent);
    }

    public function testReconcilesALostWebhook(): void
    {
        $shop = $this->shop($this->config());
        $shop->checkout(['kit' => 'kit-a', 'email' => 'ana@example.com', 'email_confirm' => 'ana@example.com', 'accept_terms' => true], '1.1.1.1');
        $this->mp->pay((string) array_key_last($this->mp->orders));

        $summary = $this->cron()->run();
        self::assertSame(1, $summary['synced']);
        self::assertSame(['Tu kit FENIA: Kit A'], $this->subjects(), 'the buyer got the kit; nothing to alert');
    }

    public function testMailsNewAlertsOnceAndExplainsThem(): void
    {
        $this->log(['level' => 'info', 'event' => 'mail_sent', 'order' => 1]);
        $this->log(['level' => 'alert', 'event' => 'link_sharing', 'order' => 7]);
        $this->log(['level' => 'alert', 'event' => 'link_sharing', 'order' => 7]);
        $this->log(['level' => 'error', 'event' => 'mail_failed', 'order' => 8]);
        file_put_contents($this->dir . '/logs/api-2026-11.log', '{"level":"alert","event":"half-writ', FILE_APPEND);

        self::assertSame(2, $this->cron()->run()['alerts'], 'deduplicated; the half-written line waits');
        self::assertSame('alertas@example.com', $this->mailer->sent[0]['to']);
        self::assertSame('[FENIA ventas] 2 aviso(s) para revisar', $this->mailer->sent[0]['subject']);
        self::assertStringContainsString('más de 3 conexiones distintas', $this->mailer->sent[0]['text']);
        self::assertStringContainsString('link_sharing · order=7', $this->mailer->sent[0]['text']);

        self::assertSame(0, $this->cron()->run()['alerts'], 'already reported');
        file_put_contents($this->dir . '/logs/api-2026-11.log', "ten\"}\n", FILE_APPEND);
        $this->log(['level' => 'alert', 'event' => 'kit_file_problem', 'kit' => 'kit-a']);
        self::assertSame(2, $this->cron()->run()['alerts'], 'the line finished meanwhile + the new one');
        self::assertStringContainsString('half-written', $this->mailer->sent[1]['text'], 'nothing lost');
        self::assertCount(2, $this->mailer->sent);
    }

    public function testDailyReportOnceADayAfterNineInArgentina(): void
    {
        self::assertSame('skip', $this->cron()->run()['report'], '07:00 ART');
        $this->now = $this->now->modify('+3 hours'); // 10:00 ART
        self::assertSame('sent', $this->cron()->run()['report']);
        self::assertSame('[FENIA ventas] Resumen del 07/11', $this->mailer->sent[0]['subject']);
        self::assertStringContainsString('Ventas: 0 (AR$ 0)', $this->mailer->sent[0]['text']);
        $this->now = $this->now->modify('+1 hour');
        self::assertSame('skip', $this->cron()->run()['report'], 'once a day');
        $this->now = $this->now->modify('+1 day');
        self::assertSame('sent', $this->cron()->run()['report']);
    }

    public function testReportCanBeTurnedOffAndAlertsDefaultToSupport(): void
    {
        $this->now = $this->now->modify('+3 hours');
        $this->log(['level' => 'alert', 'event' => 'order_review', 'order' => 3, 'reason' => 'amount_mismatch']);
        $summary = $this->cron(['daily_report' => false, 'alert_email' => ''])->run();
        self::assertSame('skip', $summary['report']);
        self::assertSame(['soporte@fenia.com.ar'], array_column($this->mailer->sent, 'to'));
    }

    public function testSpeedCheckOnceADayAndOldVisitsAndSessionsForgotten(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-07 07:30:00', new \DateTimeZone('UTC')); // 04:30 ART
        self::assertSame('skip', $this->cron()->run()['speed'], 'before 05:00');
        $this->now = $this->now->modify('+1 hour');
        self::assertSame('2 páginas', $this->cron()->run()['speed']);
        self::assertSame(['https://staging.fenia.com.ar/', 'https://staging.fenia.com.ar/kits/kit-a/'], $this->speedAsked, 'the home and the first kit');
        self::assertSame('skip', $this->cron()->run()['speed'], 'once a day');

        $this->pdo->exec("INSERT INTO site_events (at, day, kind, path, source, device, visitor, entry) VALUES ('2025-01-01 10:00:00', '2025-01-01', 'view', '/', 'Directo', 'compu', 'aaaaaaaaaaaaaaaa', 1)");
        $this->pdo->exec("INSERT INTO sessions (token_hash, subject, created_at, expires_at, last_seen_at) VALUES ('" . str_repeat('a', 64) . "', 'owner:fab', '2026-09-01 00:00:00', '2026-10-01 00:00:00', '2026-09-01 00:00:00')");
        $summary = $this->cron()->run();
        self::assertSame([1, 1], [$summary['visits_purged'], $summary['sessions_purged']]);
    }

    public function testPurgesOldRateLimitEvents(): void
    {
        $limiter = new RateLimiter($this->pdo);
        $limiter->allow('old-ip', 10, 3600, $this->now->modify('-3 days'));
        $limiter->allow('new-ip', 10, 3600, $this->now);
        self::assertSame(1, $this->cron()->run()['purged']);
    }
}
