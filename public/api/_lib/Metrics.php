<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * The internal metrics screen (ADR-029, docs/ventas/propuesta-metricas.md):
 * visits, purchase funnel, sales, how buyers use "Tu kit", latest sales and
 * the site's speed. Everything comes from our own database; buyers' emails
 * are always masked. Only for logged-in owners (Owners).
 */
final class Metrics
{
    public const RANGES = ['hoy' => 0, '7d' => 6, '30d' => 29, 'todo' => null];

    /** Mercado Pago's payment_type_id, in plain words. */
    private const PAY_TYPES = [
        'account_money' => 'Dinero en cuenta',
        'credit_card' => 'Tarjeta de crédito',
        'debit_card' => 'Tarjeta de débito',
        'prepaid_card' => 'Tarjeta prepaga',
        'bank_transfer' => 'Transferencia',
        'ticket' => 'Efectivo',
        'digital_currency' => 'Crédito de Mercado Pago',
    ];
    private const TZ = 'America/Argentina/Buenos_Aires';

    private \PDO $pdo;
    private Catalog $catalog;
    private \DateTimeImmutable $now;

    public function __construct(\PDO $pdo, Catalog $catalog, \DateTimeImmutable $now)
    {
        $this->pdo = $pdo;
        $this->catalog = $catalog;
        $this->now = $now;
    }

    /** @return array<string, mixed> */
    public function report(string $range): array
    {
        if (!array_key_exists($range, self::RANGES)) {
            $range = '7d';
        }
        $today = $this->now->setTimezone(new \DateTimeZone(self::TZ))->setTime(0, 0);
        $days = self::RANGES[$range];
        $fromLocal = $days === null ? new \DateTimeImmutable('2000-01-01', new \DateTimeZone(self::TZ)) : $today->modify('-' . $days . ' days');
        $fromDay = $fromLocal->format('Y-m-d');
        $fromUtc = Db::now($fromLocal);

        return [
            'range' => $range,
            'from' => $days === null ? null : $fromLocal->format('d/m'),
            'updated' => $this->now->setTimezone(new \DateTimeZone(self::TZ))->format('d/m H:i'),
            'visits' => $this->visits($fromDay, $today->modify('-29 days')->format('Y-m-d')),
            'funnel' => $this->funnel($fromDay, $fromUtc),
            'sales' => $this->sales($fromUtc, $today),
            'kit_use' => $this->kitUse($fromUtc),
            'recent' => $this->recent(),
            'speed' => $this->speed(),
        ];
    }

    /** @return array<string, mixed> */
    private function visits(string $fromDay, string $chartFrom): array
    {
        $visitors = $this->count('SELECT COUNT(*) FROM (SELECT DISTINCT day, visitor FROM site_events WHERE kind = ? AND day >= ?) t', ['view', $fromDay]);
        $sources = $this->rows("SELECT source AS name, COUNT(*) AS n FROM site_events WHERE kind = 'view' AND entry = 1 AND day >= ? GROUP BY source ORDER BY n DESC, name", [$fromDay]);
        $devices = $this->rows("SELECT device AS name, COUNT(*) AS n FROM (SELECT DISTINCT day, visitor, device FROM site_events WHERE kind = 'view' AND day >= ?) t GROUP BY device ORDER BY n DESC", [$fromDay]);
        $pages = $this->rows("SELECT path AS name, COUNT(*) AS n FROM site_events WHERE kind = 'view' AND day >= ? GROUP BY path ORDER BY n DESC, name LIMIT 8", [$fromDay]);
        $perDay = [];
        foreach ($this->rows("SELECT day AS name, COUNT(DISTINCT visitor) AS n FROM site_events WHERE kind = 'view' AND day >= ? GROUP BY day", [$chartFrom]) as $row) {
            $perDay[$row['name']] = $row['n'];
        }
        return [
            'visitors' => $visitors,
            'visits' => $this->count("SELECT COUNT(*) FROM site_events WHERE kind = 'view' AND entry = 1 AND day >= ?", [$fromDay]),
            'views' => $this->count("SELECT COUNT(*) FROM site_events WHERE kind = 'view' AND day >= ?", [$fromDay]),
            'whatsapp' => $this->count("SELECT COUNT(*) FROM site_events WHERE kind = 'whatsapp' AND day >= ?", [$fromDay]),
            'sources' => self::shares(array_slice($sources, 0, 7)),
            'devices' => self::shares($devices),
            'pages' => $pages,
            'per_day' => $this->series($chartFrom, $perDay),
        ];
    }

    /**
     * Kit page → went to pay at Mercado Pago → paid, per kit on sale. Starts
     * when the visit counter started: earlier purchases have no visits to
     * compare with, and the rate would make no sense.
     *
     * @return array<string, mixed>
     */
    private function funnel(string $fromDay, string $fromUtc): array
    {
        $first = $this->pdo->query('SELECT MIN(day) FROM site_events')->fetchColumn();
        if (!is_string($first) || $first === '') {
            return ['total' => ['seen' => 0, 'checkout' => 0, 'paid' => 0, 'rate' => null], 'kits' => [], 'since' => null];
        }
        if ($first > $fromDay) {
            $fromDay = $first;
            $fromUtc = Db::now(new \DateTimeImmutable($first, new \DateTimeZone(self::TZ)));
        }
        $kits = [];
        $total = ['seen' => 0, 'checkout' => 0, 'paid' => 0];
        foreach ($this->catalog->all() as $kit) {
            if (($kit['status'] ?? '') !== 'live') {
                continue;
            }
            $id = (string) $kit['id'];
            $row = [
                'id' => $id,
                'title' => (string) $kit['title'],
                'seen' => $this->count("SELECT COUNT(*) FROM (SELECT DISTINCT day, visitor FROM site_events WHERE kind = 'view' AND path = ? AND day >= ?) t", ['/kits/' . $id . '/', $fromDay]),
                'checkout' => $this->count('SELECT COUNT(*) FROM orders WHERE kit_id = ? AND mp_order_id IS NOT NULL AND created_at >= ?', [$id, $fromUtc]),
                'paid' => $this->count('SELECT COUNT(*) FROM orders WHERE kit_id = ? AND paid_at IS NOT NULL AND paid_at >= ?', [$id, $fromUtc]),
            ];
            foreach (['seen', 'checkout', 'paid'] as $step) {
                $total[$step] += $row[$step];
            }
            $kits[] = $row;
        }
        $total['rate'] = $total['seen'] > 0 ? round(100 * $total['paid'] / $total['seen'], 1) : null;
        return ['total' => $total, 'kits' => $kits, 'since' => (new \DateTimeImmutable($fromDay))->format('d/m')];
    }

    /** @return array<string, mixed> */
    private function sales(string $fromUtc, \DateTimeImmutable $today): array
    {
        $paid = $this->rows('SELECT paid_at, expected_cents FROM orders WHERE paid_at IS NOT NULL AND paid_at >= ?', [$fromUtc]);
        $cents = array_sum(array_map(static fn (array $r): int => (int) $r['expected_cents'], $paid));
        $perDay = [];
        $chartFrom = $today->modify('-29 days')->format('Y-m-d');
        foreach ($this->rows('SELECT paid_at FROM orders WHERE paid_at IS NOT NULL AND paid_at >= ?', [Db::now($today->modify('-29 days'))]) as $row) {
            $day = $this->localDay((string) $row['paid_at']);
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }
        $perKit = [];
        foreach ($this->rows('SELECT kit_id, COUNT(*) AS n FROM orders WHERE paid_at IS NOT NULL AND paid_at >= ? GROUP BY kit_id ORDER BY n DESC', [$fromUtc]) as $row) {
            $perKit[] = ['name' => (string) ($this->catalog->find((string) $row['kit_id'])['title'] ?? $row['kit_id']), 'n' => (int) $row['n']];
        }
        // D29: what Mercado Pago kept, as it reports it on each sale (filled by the hourly job).
        $known = $this->rows('SELECT expected_cents, net_cents, fee_cents, pay_type FROM orders WHERE paid_at IS NOT NULL AND paid_at >= ? AND fees_at IS NOT NULL', [$fromUtc]);
        $knownGross = array_sum(array_map(static fn (array $r): int => (int) $r['expected_cents'], $known));
        $net = array_sum(array_map(static fn (array $r): int => (int) $r['net_cents'], $known));
        $fees = array_sum(array_map(static fn (array $r): int => (int) $r['fee_cents'], $known));
        $byType = [];
        foreach ($known as $row) {
            $type = (string) ($row['pay_type'] ?? '');
            $byType[$type] ??= ['n' => 0, 'gross' => 0, 'fee' => 0];
            $byType[$type]['n']++;
            $byType[$type]['gross'] += (int) $row['expected_cents'];
            $byType[$type]['fee'] += (int) $row['fee_cents'];
        }
        uasort($byType, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);
        $payTypes = [];
        foreach ($byType as $type => $t) {
            $payTypes[] = ['name' => self::PAY_TYPES[$type] ?? ($type === '' ? 'Sin dato' : $type), 'n' => $t['n'], 'fee_pct' => $t['gross'] > 0 ? round(100 * $t['fee'] / $t['gross'], 2) : null];
        }
        return [
            'count' => count($paid),
            'revenue' => intdiv($cents, 100),
            'net' => $known === [] ? null : $net / 100,
            'fees' => $known === [] ? null : $fees / 100,
            'fee_pct' => $knownGross > 0 ? round(100 * $fees / $knownGross, 2) : null,
            'net_avg' => $known === [] ? null : round($net / 100 / count($known), 2),
            'fees_pending' => count($paid) - count($known),
            'by_pay_type' => $payTypes,
            'per_kit' => $perKit,
            'refunds' => $this->count("SELECT COUNT(*) FROM orders WHERE status = 'revoked' AND updated_at >= ?", [$fromUtc]),
            'failed' => $this->count("SELECT COUNT(*) FROM orders WHERE status = 'failed' AND created_at >= ?", [$fromUtc]),
            'review' => $this->count("SELECT COUNT(*) FROM orders WHERE status = 'review'"),
            'mail_pending' => $this->count("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND email_sent_at IS NULL"),
            'per_day' => $this->series($chartFrom, $perDay),
        ];
    }

    /** What buyers of the period did with their kit (logged since 14/11/2026). @return array<string, int> */
    private function kitUse(string $fromUtc): array
    {
        $buyers = $this->count('SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_at >= ?', [$fromUtc]);
        $did = fn (string $kind): int => $this->count(
            'SELECT COUNT(DISTINCT t.order_id) FROM download_log l JOIN download_tokens t ON t.id = l.token_id JOIN orders o ON o.id = t.order_id
             WHERE l.kind = ? AND o.paid_at IS NOT NULL AND o.paid_at >= ?',
            [$kind, $fromUtc]
        );
        return [
            'buyers' => $buyers,
            'opened' => $did('open'),
            'read' => $did('read'),
            'fichas' => $did('fichas'),
            'imprimibles' => $did('imprimibles'),
            'zip' => $did('zip'),
            'guide' => $did('guide'),
            'renewed' => $this->count('SELECT COUNT(*) FROM download_tokens t JOIN orders o ON o.id = t.order_id WHERE t.generation > 1 AND t.created_at >= ?', [$fromUtc]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recent(): array
    {
        $out = [];
        $rows = $this->rows(
            "SELECT o.id, o.kit_id, o.email, o.expected_cents, o.net_cents, o.fees_at, o.paid_at, o.status,
                    (SELECT COUNT(*) FROM download_log l JOIN download_tokens t ON t.id = l.token_id WHERE t.order_id = o.id AND l.kind = 'open') AS opens
             FROM orders o WHERE o.paid_at IS NOT NULL ORDER BY o.paid_at DESC LIMIT 10"
        );
        foreach ($rows as $row) {
            $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['paid_at'], new \DateTimeZone('UTC'));
            $out[] = [
                'when' => $at === false ? '' : $at->setTimezone(new \DateTimeZone(self::TZ))->format('d/m H:i'),
                'kit' => (string) ($this->catalog->find((string) $row['kit_id'])['title'] ?? $row['kit_id']),
                'email' => Shop::maskEmail((string) $row['email']),
                'amount' => intdiv((int) $row['expected_cents'], 100),
                'net' => $row['fees_at'] === null ? null : ((int) $row['net_cents']) / 100,
                'refunded' => $row['status'] === 'revoked',
                'opened' => (int) $row['opens'] > 0,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the latest check of each page */
    private function speed(): array
    {
        $out = [];
        $rows = $this->rows('SELECT * FROM speed_checks WHERE day = (SELECT MAX(day) FROM speed_checks) ORDER BY id');
        foreach ($rows as $row) {
            $out[] = [
                'day' => (string) $row['day'],
                'url' => (string) $row['url'],
                'score' => $row['score'] === null ? null : (int) $row['score'],
                'lcp_ms' => $row['lcp_ms'] === null ? null : (int) $row['lcp_ms'],
                'cls' => $row['cls_milli'] === null ? null : ((int) $row['cls_milli']) / 1000,
                'tbt_ms' => $row['tbt_ms'] === null ? null : (int) $row['tbt_ms'],
                'field_lcp_ms' => $row['field_lcp_ms'] === null ? null : (int) $row['field_lcp_ms'],
                'field_inp_ms' => $row['field_inp_ms'] === null ? null : (int) $row['field_inp_ms'],
                'field_cls' => $row['field_cls_milli'] === null ? null : ((int) $row['field_cls_milli']) / 1000,
                'error' => $row['error'] === null ? null : (string) $row['error'],
            ];
        }
        return $out;
    }

    /** @param list<array{name: string, n: int}> $rows @return list<array{name: string, n: int, pct: int}> */
    private static function shares(array $rows): array
    {
        $sum = array_sum(array_column($rows, 'n'));
        return array_map(static fn (array $r): array => $r + ['pct' => $sum > 0 ? (int) round(100 * $r['n'] / $sum) : 0], $rows);
    }

    /** 30 days ending today, zero-filled. @param array<string, int> $values @return list<array{day: string, n: int}> */
    private function series(string $from, array $values): array
    {
        $out = [];
        $day = new \DateTimeImmutable($from, new \DateTimeZone(self::TZ));
        for ($i = 0; $i < 30; $i++) {
            $key = $day->format('Y-m-d');
            $out[] = ['day' => $day->format('j/n'), 'n' => (int) ($values[$key] ?? 0)];
            $day = $day->modify('+1 day');
        }
        return $out;
    }

    private function localDay(string $utc): string
    {
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        return $at === false ? '' : $at->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (isset($row['n'])) {
                $row['n'] = (int) $row['n'];
            }
        }
        return $rows;
    }
}
