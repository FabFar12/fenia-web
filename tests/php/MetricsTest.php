<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Catalog;
use Fenia\Api\Config;
use Fenia\Api\Db;
use Fenia\Api\Metrics;
use Fenia\Api\OrderStore;
use Fenia\Api\Owners;
use Fenia\Api\SpeedCheck;
use Fenia\Api\Tokens;
use Fenia\Api\TokenStore;
use Fenia\Api\Visits;
use PHPUnit\Framework\TestCase;

/** Internal metrics (ADR-029): private visit counter, owners' accounts, report, speed check. */
final class MetricsTest extends TestCase
{
    private const SALT = 'ssssssssssssssssssssssssssssssss';
    private const UA = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/150.0 Mobile Safari/537.36';

    private \PDO $pdo;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = TestDb::fresh();
        $this->now = new \DateTimeImmutable('2026-11-14 15:00:00', new \DateTimeZone('UTC')); // 12:00 in Argentina
    }

    /** @param array<string, mixed> $in */
    private function hit(array $in, string $ip = '200.1.1.1', ?string $ua = self::UA): bool
    {
        return Visits::record($this->pdo, $in, $ip, $ua, self::SALT, 'fenia.com.ar', $this->now);
    }

    private function config(array $owners): Config
    {
        return Config::fromArray(EnvConfigInboxTest::validConfig(['db_user' => 'u', 'owners' => $owners]), 'staging');
    }

    // ── Visit counter ────────────────────────────────────────────────────────

    public function testAPageViewIsStoredWithoutAnythingPersonal(): void
    {
        self::assertTrue($this->hit(['p' => '/kits/kit-a', 'r' => 'https://l.instagram.com/?u=x', 'w' => 412]));
        $row = $this->pdo->query('SELECT * FROM site_events')->fetch();
        self::assertSame(['2026-11-14', 'view', '/kits/kit-a/', 'Instagram', 'celular', 1], [$row['day'], $row['kind'], $row['path'], $row['source'], $row['device'], (int) $row['entry']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $row['visitor']);
        $all = implode(' ', array_map('strval', $row));
        self::assertStringNotContainsString('200.1.1.1', $all, 'no IP');
        self::assertStringNotContainsString('Android', $all, 'no browser');
    }

    public function testTheVisitorHashChangesEveryDay(): void
    {
        $today = Visits::visitor('200.1.1.1', self::UA, self::SALT, '2026-11-14');
        self::assertSame($today, Visits::visitor('200.1.1.1', self::UA, self::SALT, '2026-11-14'), 'same person, same day');
        self::assertNotSame($today, Visits::visitor('200.1.1.1', self::UA, self::SALT, '2026-11-15'), 'unrelated the next day');
        self::assertNotSame($today, Visits::visitor('200.1.1.2', self::UA, self::SALT, '2026-11-14'));
    }

    public function testBotsAndJunkAreNotCounted(): void
    {
        foreach (['Googlebot/2.1', 'facebookexternalhit/1.1', 'WhatsApp/2.23', 'curl/8.4', 'Mozilla/5.0 HeadlessChrome/150', '', null] as $ua) {
            self::assertFalse($this->hit(['p' => '/'], '1.1.1.1', $ua), (string) $ua);
        }
        foreach ([[], ['p' => 'http://evil/'], ['p' => '/api/kit.php'], ['p' => '/../etc'], ['p' => '/<script>'], ['p' => '/', 'k' => 'buy']] as $in) {
            self::assertFalse($this->hit($in), json_encode($in));
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM site_events')->fetchColumn());
    }

    public function testSourcesAndPaths(): void
    {
        $cases = [
            ['', '', '', 'Directo'],
            ['https://www.google.com.ar/', '', '', 'Google'],
            ['https://mail.google.com/mail/u/0/', '', '', 'Mail'],
            ['https://lm.facebook.com/l.php', '', '', 'Facebook'],
            ['https://fenia.com.ar/kits/', '', '', 'Interno'],
            ['https://www.example.org/nota', '', '', 'example.org'],
            ['', 'ig', '', 'Instagram'],
            ['https://www.google.com/', 'newsletter-nov', 'email', 'Mail'],
            ['', 'radio', '', 'Radio'],
        ];
        foreach ($cases as [$ref, $utm, $medium, $expected]) {
            self::assertSame($expected, Visits::source($ref, $utm, $medium, 'fenia.com.ar'), "$ref $utm $medium");
        }
        self::assertSame('/', Visits::path('/'));
        self::assertSame('/kits/kit-a/', Visits::path('/kits/kit-a'));
        self::assertSame('/descarga/', Visits::path('/descarga/index.html'));
        self::assertSame('/404.html', Visits::path('/404.html'));
        self::assertNull(Visits::path('//evil.example/'));
    }

    public function testNavigationInsideTheSiteIsNotANewVisit(): void
    {
        $this->hit(['p' => '/', 'r' => 'https://www.google.com/', 'w' => 1300]);
        $this->hit(['p' => '/kits/kit-a/', 'n' => 1, 'w' => 1300]);
        $this->hit(['k' => 'whatsapp', 'p' => '/kits/kit-a/', 'w' => 1300]);
        $rows = $this->pdo->query('SELECT kind, source, entry FROM site_events ORDER BY id')->fetchAll();
        self::assertSame([['view', 'Google', 1], ['view', 'Interno', 0], ['whatsapp', 'Directo', 0]], array_map(static fn (array $r): array => [$r['kind'], $r['source'], (int) $r['entry']], $rows));
    }

    // ── Owners' accounts ─────────────────────────────────────────────────────

    public function testOwnersLogInWithUserAndPassword(): void
    {
        $owners = new Owners($this->pdo, $this->config(['fab' => password_hash('clave-larga-de-fab', PASSWORD_DEFAULT)]), fn (): \DateTimeImmutable => $this->now);
        [$status, $body, $token] = $owners->login(' FAB ', 'clave-larga-de-fab', '1.2.3.4');
        self::assertSame([200, ['ok' => true, 'user' => 'fab']], [$status, $body]);
        self::assertSame('fab', $owners->current($token));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sessions WHERE token_hash = " . $this->pdo->quote($token))->fetchColumn(), 'only the hash is stored');

        $owners->logout($token);
        self::assertNull($owners->current($token), 'logged out');
    }

    public function testWrongPasswordAndUnknownUserGetTheSameAnswer(): void
    {
        $owners = new Owners($this->pdo, $this->config(['fab' => password_hash('clave-larga-de-fab', PASSWORD_DEFAULT)]), fn (): \DateTimeImmutable => $this->now);
        self::assertSame([401, ['error' => 'credentials']], $owners->login('fab', 'otra', '1.2.3.4'));
        self::assertSame([401, ['error' => 'credentials']], $owners->login('nadie', 'clave-larga-de-fab', '1.2.3.5'));
        self::assertSame([401, ['error' => 'credentials']], $owners->login("fab' OR 1=1 --", 'x', '1.2.3.6'));
        self::assertNull($owners->current(null));
        self::assertNull($owners->current(str_repeat('A', 43)));
    }

    public function testGuessingPasswordsIsCapped(): void
    {
        $owners = new Owners($this->pdo, $this->config(['fab' => password_hash('clave-larga-de-fab', PASSWORD_DEFAULT)]), fn (): \DateTimeImmutable => $this->now);
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(401, $owners->login('fab', 'intento-' . $i, '9.9.9.9')[0]);
        }
        self::assertSame(429, $owners->login('fab', 'clave-larga-de-fab', '9.9.9.9')[0], 'per IP');
        for ($i = 0; $i < 10; $i++) {
            $owners->login('fab', 'otro-' . $i, '8.8.' . $i . '.1');
        }
        self::assertSame(429, $owners->login('fab', 'clave-larga-de-fab', '7.7.7.7')[0], 'per user, from any IP');
    }

    public function testSessionsExpireAndDieWithTheAccount(): void
    {
        $hash = password_hash('clave-larga-de-fab', PASSWORD_DEFAULT);
        $owners = new Owners($this->pdo, $this->config(['fab' => $hash]), fn (): \DateTimeImmutable => $this->now);
        $token = $owners->login('fab', 'clave-larga-de-fab', '1.2.3.4')[2];
        self::assertNull((new Owners($this->pdo, $this->config(['dev' => $hash])))->current($token), 'removed from the config');
        $this->now = $this->now->modify('+31 days');
        self::assertNull($owners->current($token), 'expired');
        self::assertSame(1, $owners->purge());
        self::assertSame([503, ['error' => 'unavailable']], (new Owners($this->pdo, $this->config([])))->login('fab', 'x', '1.1.1.1'), 'no owners: closed');
    }

    // ── Report ───────────────────────────────────────────────────────────────

    public function testTheReportJoinsVisitsSalesAndKitUse(): void
    {
        $orders = new OrderStore($this->pdo);
        $tokens = new TokenStore($this->pdo);
        $order = function (string $kit, string $email, string $status) use ($orders, $tokens): int {
            $id = $orders->create(['reference' => Tokens::orderReference(), 'kit_id' => $kit, 'email' => $email, 'expected_cents' => 1300000, 'currency' => 'ARS', 'client_ip_hash' => null], '2026-11-14 12:00:00');
            $orders->bindMpOrder($id, 'ORD' . $id . 'TEST', '2026-11-14 12:00:00');
            if ($status !== 'pending') {
                $orders->transition($id, 'pending', 'paid', null, '2026-11-14 12:10:00');
                $tokens->issue($id, Tokens::generate()['hash'], '2026-12-14 12:10:00', 10, '2026-11-14 12:10:00');
            }
            return $id;
        };
        $paid = $order('kit-a', 'ana@gmail.com', 'paid');
        // A sale from before the visit counter existed: it counts as a sale, not in the funnel.
        $old = $orders->create(['reference' => Tokens::orderReference(), 'kit_id' => 'kit-a', 'email' => 'old@gmail.com', 'expected_cents' => 1300000, 'currency' => 'ARS', 'client_ip_hash' => null], '2026-11-10 12:00:00');
        $orders->bindMpOrder($old, 'ORDOLDTEST', '2026-11-10 12:00:00');
        $orders->transition($old, 'pending', 'paid', null, '2026-11-10 12:05:00');
        $order('kit-a', 'beto@hotmail.com', 'pending');
        $tokenId = (int) $this->pdo->query("SELECT id FROM download_tokens WHERE order_id = $paid")->fetchColumn();
        $tokens->logDownload($tokenId, 'h', null, '2026-11-14 12:20:00', 'open');
        $tokens->logDownload($tokenId, 'h', null, '2026-11-14 12:21:00', 'fichas');
        foreach (['200.0.0.1', '200.0.0.2', '200.0.0.3'] as $ip) {
            Visits::record($this->pdo, ['p' => '/kits/kit-a/', 'r' => 'https://www.instagram.com/', 'w' => 400], $ip, self::UA, self::SALT, 'fenia.com.ar', $this->now);
        }
        Visits::record($this->pdo, ['p' => '/', 'w' => 1400], '200.0.0.4', self::UA, self::SALT, 'fenia.com.ar', $this->now);

        $catalog = Catalog::fromJson((string) json_encode(['products' => [
            ['id' => 'kit-a', 'title' => 'Kit A', 'status' => 'live', 'price' => 13000, 'currency' => 'ARS'],
            ['id' => 'kit-b', 'title' => 'Kit B', 'status' => 'hidden', 'price' => 13000, 'currency' => 'ARS'],
        ]]));
        $report = (new Metrics($this->pdo, $catalog, $this->now))->report('7d');

        self::assertSame(['visitors' => 4, 'visits' => 4, 'views' => 4, 'whatsapp' => 0], array_intersect_key($report['visits'], ['visitors' => 0, 'visits' => 0, 'views' => 0, 'whatsapp' => 0]));
        self::assertSame(['name' => 'Instagram', 'n' => 3, 'pct' => 75], $report['visits']['sources'][0]);
        self::assertSame(['seen' => 3, 'checkout' => 2, 'paid' => 1, 'rate' => 33.3], $report['funnel']['total'], 'from the day the counter started');
        self::assertSame('14/11', $report['funnel']['since']);
        self::assertCount(1, $report['funnel']['kits'], 'only kits on sale');
        self::assertSame([2, 26000, 0], [$report['sales']['count'], $report['sales']['revenue'], $report['sales']['refunds']]);
        self::assertSame([['name' => 'Kit A', 'n' => 2]], $report['sales']['per_kit']);
        self::assertSame(1, $report['sales']['per_day'][29]['n'], 'today, the last bar');
        self::assertSame(['buyers' => 2, 'opened' => 1, 'fichas' => 1, 'zip' => 0], array_intersect_key($report['kit_use'], ['buyers' => 0, 'opened' => 0, 'fichas' => 0, 'zip' => 0]));
        self::assertSame(['kit' => 'Kit A', 'email' => 'a***@gmail.com', 'amount' => 13000, 'opened' => true], array_intersect_key($report['recent'][0], ['kit' => 0, 'email' => 0, 'amount' => 0, 'opened' => 0]));
        self::assertStringNotContainsString('ana@gmail.com', (string) json_encode($report), 'emails always masked');
        self::assertSame([null, null, null, 2], [$report['sales']['net'], $report['sales']['fees'], $report['sales']['fee_pct'], $report['sales']['fees_pending']], 'commission not known yet');

        // D29: once the hourly job knows what Mercado Pago kept.
        $orders->setFees($paid, 1196173, 103827, 'account_money', 1, '2026-11-14 13:00:00');
        $report = (new Metrics($this->pdo, $catalog, $this->now))->report('7d');
        self::assertSame([11961.73, 1038.27, 7.99, 11961.73, 1], [$report['sales']['net'], $report['sales']['fees'], $report['sales']['fee_pct'], $report['sales']['net_avg'], $report['sales']['fees_pending']]);
        self::assertSame([['name' => 'Dinero en cuenta', 'n' => 1, 'fee_pct' => 7.99]], $report['sales']['by_pay_type']);
        self::assertSame(11961.73, $report['recent'][0]['net']);
        self::assertSame('7d', (new Metrics($this->pdo, $catalog, $this->now))->report('cualquiera')['range']);
    }

    // ── Speed check ──────────────────────────────────────────────────────────

    public function testTheSpeedCheckKeepsScoreAndCoreWebVitals(): void
    {
        $answer = (string) json_encode([
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => 0.87]],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 2310.4],
                    'cumulative-layout-shift' => ['numericValue' => 0.042],
                    'total-blocking-time' => ['numericValue' => 120],
                ],
            ],
            'loadingExperience' => ['metrics' => [
                'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2100],
                'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 180],
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 5],
            ]],
        ]);
        $asked = [];
        $get = function (string $url) use (&$asked, $answer): array {
            $asked[] = $url;
            return str_contains($url, 'kits') ? ['status' => 429, 'body' => ''] : ['status' => 200, 'body' => $answer];
        };
        self::assertSame(2, SpeedCheck::run($this->pdo, ['https://fenia.com.ar/', 'https://fenia.com.ar/kits/kit-a/'], '', $get, $this->now));
        self::assertStringContainsString('strategy=mobile', $asked[0]);
        self::assertStringNotContainsString('key=', $asked[0]);
        $rows = $this->pdo->query('SELECT * FROM speed_checks ORDER BY id')->fetchAll();
        self::assertSame([87, 2310, 42, 120, 2100, 180, 50, null], array_map(static fn ($v) => $v === null ? null : (int) $v, [$rows[0]['score'], $rows[0]['lcp_ms'], $rows[0]['cls_milli'], $rows[0]['tbt_ms'], $rows[0]['field_lcp_ms'], $rows[0]['field_inp_ms'], $rows[0]['field_cls_milli'], $rows[0]['error']]));
        self::assertSame('PageSpeed respondió HTTP 429', $rows[1]['error'], 'a failure is kept, not thrown');
        self::assertSame(0.042, (new Metrics($this->pdo, Catalog::fromJson('{"products":[]}'), $this->now))->report('hoy')['speed'][0]['cls']);
    }

    public function testConfigOwnersAreValidated(): void
    {
        self::assertSame([], Config::fromArray(EnvConfigInboxTest::validConfig(['db_user' => 'u']), 'staging')->owners());
        foreach ([['Fab Zz' => '$2y$10$x'], ['fab' => 'clave-en-texto'], ['fab' => 123]] as $bad) {
            try {
                $this->config($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (\RuntimeException $e) {
                self::assertSame('Config owners malformed', $e->getMessage());
            }
        }
    }
}
