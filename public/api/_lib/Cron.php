<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Hourly job (step 3.4, run by hourly.php). It does three things:
 *  - reconciliation: re-syncs pending orders whose webhook got lost and
 *    retries delivery mails that did not go out (threat model C2, C22);
 *  - alerts: mails every new `alert`/`error` log event to `alert_email`;
 *  - daily report: one summary mail a day, after 09:00 Argentina time.
 *
 * Logs carry order ids, never emails or tokens, so alert mails do too.
 */
final class Cron
{
    private const LOCAL_TZ = 'America/Argentina/Buenos_Aires';
    private const REPORT_HOUR = 9;

    /** Plain-language meaning of each event, for the person reading the alert. */
    private const EXPLAIN = [
        'order_review' => 'Una orden quedó para revisar a mano. No se entregó nada automáticamente.',
        'kit_file_problem' => 'El ZIP de un kit falta o no coincide con el revisado: las descargas de ese kit están frenadas.',
        'link_sharing' => 'Un link de descarga se usó desde más de 3 conexiones distintas (¿lo compartieron?).',
        'renewals_exhausted' => 'Un comprador pidió otro link y ya usó las 2 renovaciones: atenderlo por soporte.',
        'mail_failed' => 'Un mail de entrega no salió. Se reintenta cada hora; el comprador igual tiene /gracias y "No me llegó".',
        'checkout_mp_error' => 'Mercado Pago no pudo crear un pago (el comprador vio un error y puede reintentar).',
        'checkout_mp_malformed' => 'Mercado Pago respondió algo inesperado al crear un pago.',
        'webhook_mp_error' => 'No se pudo leer una orden en Mercado Pago; MP reintenta el aviso.',
        'unhandled' => 'Error interno inesperado en la API.',
    ];

    private Shop $shop;
    private \PDO $pdo;
    private MailSender $mailer;
    private Config $config;
    private string $privateDir;
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(Shop $shop, \PDO $pdo, MailSender $mailer, Config $config, string $privateDir, ?callable $clock = null)
    {
        $this->shop = $shop;
        $this->pdo = $pdo;
        $this->mailer = $mailer;
        $this->config = $config;
        $this->privateDir = rtrim($privateDir, '/');
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @return array<string, int|string> what happened, printed as the cron output */
    public function run(): array
    {
        $now = ($this->clock)();
        $state = $this->loadState();

        $summary = $this->shop->reconcile();
        $summary['purged'] = (new RateLimiter($this->pdo))->purge($now->modify('-2 days'));

        [$alerts, $state['offsets']] = $this->newAlerts((array) ($state['offsets'] ?? []));
        $summary['alerts'] = count($alerts);
        if ($alerts !== []) {
            $this->mail('[FENIA ventas] ' . count($alerts) . ' aviso(s) para revisar', $this->alertText($alerts));
        }

        $local = $now->setTimezone(new \DateTimeZone(self::LOCAL_TZ));
        $today = $local->format('Y-m-d');
        $summary['report'] = 'skip';
        if ($this->config->get('daily_report') === true && (int) $local->format('G') >= self::REPORT_HOUR && ($state['report'] ?? '') !== $today) {
            $this->mail('[FENIA ventas] Resumen del ' . $local->format('d/m'), $this->reportText($now));
            $state['report'] = $today;
            $summary['report'] = 'sent';
        }

        $this->saveState($state);
        return $summary;
    }

    /**
     * New alert/error lines since the last run, in this month's and last
     * month's log files.
     *
     * @param array<string, int> $offsets file name => bytes already read
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    private function newAlerts(array $offsets): array
    {
        $now = ($this->clock)();
        $files = ['api-' . $now->modify('first day of last month')->format('Y-m') . '.log', 'api-' . $now->format('Y-m') . '.log'];
        $alerts = [];
        $seen = [];
        $kept = [];
        foreach ($files as $name) {
            $path = $this->privateDir . '/logs/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $offset = (int) ($offsets[$name] ?? 0);
            if ($offset > (int) filesize($path)) {
                $offset = 0;
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                continue;
            }
            fseek($handle, $offset);
            while (($line = fgets($handle)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    break; // being written right now: read it next hour
                }
                $offset += strlen($line);
                $event = json_decode($line, true);
                if (!is_array($event) || !in_array($event['level'] ?? '', ['alert', 'error'], true)) {
                    continue;
                }
                // The same problem on the same order once per mail.
                $key = ($event['event'] ?? '') . '|' . ($event['order'] ?? '') . '|' . ($event['reason'] ?? '');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $alerts[] = $event;
                }
            }
            fclose($handle);
            $kept[$name] = $offset;
        }
        return [$alerts, $kept];
    }

    /** @param list<array<string, mixed>> $alerts */
    private function alertText(array $alerts): string
    {
        $lines = ['Avisos del sistema de ventas de FENIA (' . $this->config->get('env') . '):', ''];
        foreach ($alerts as $a) {
            $event = (string) ($a['event'] ?? '?');
            $details = [];
            foreach ($a as $k => $v) {
                if (!in_array($k, ['ts', 'level', 'event'], true) && is_scalar($v)) {
                    $details[] = $k . '=' . $v;
                }
            }
            $lines[] = '• ' . (self::EXPLAIN[$event] ?? $event);
            $lines[] = '  ' . ($a['ts'] ?? '') . ' · ' . $event . ($details === [] ? '' : ' · ' . implode(' ', $details));
        }
        $lines[] = '';
        $lines[] = 'Los números de orden se buscan en la base (tabla orders, columna id). Este mail no contiene datos de compradores.';
        return implode("\n", $lines) . "\n";
    }

    private function reportText(\DateTimeImmutable $now): string
    {
        $since = Db::now($now->modify('-24 hours'));
        $count = function (string $sql, array $params = []): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        $paid = $count('SELECT COUNT(*) FROM orders WHERE paid_at >= ?', [$since]);
        $cents = $count('SELECT COALESCE(SUM(expected_cents), 0) FROM orders WHERE paid_at >= ?', [$since]);

        return implode("\n", [
            'Resumen de las últimas 24 horas (' . $this->config->get('env') . '):',
            '',
            '• Ventas: ' . $paid . ' (AR$ ' . number_format($cents / 100, 0, ',', '.') . ')',
            '• Compras iniciadas: ' . $count('SELECT COUNT(*) FROM orders WHERE created_at >= ?', [$since]),
            '• Pagos que Mercado Pago no pudo crear: ' . $count("SELECT COUNT(*) FROM orders WHERE status = 'failed' AND created_at >= ?", [$since]),
            '• Reembolsos o contracargos: ' . $count("SELECT COUNT(*) FROM orders WHERE status = 'revoked' AND updated_at >= ?", [$since]),
            '• Órdenes para revisar a mano (en total): ' . $count("SELECT COUNT(*) FROM orders WHERE status = 'review'"),
            '• Mails de entrega pendientes (en total): ' . $count("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND email_sent_at IS NULL"),
            '• Mails de entrega enviados: ' . $count('SELECT COUNT(*) FROM orders WHERE email_sent_at >= ?', [$since])
                . ' (la casilla permite 100 por día, contando reenvíos y estos avisos)',
            '',
            'Cada venta también figura en Mercado Pago. Las facturas se emiten aparte.',
        ]) . "\n";
    }

    private function mail(string $subject, string $text): void
    {
        $to = (string) $this->config->get('alert_email');
        $to = Validate::email($to === '' ? (string) $this->config->get('mail_reply_to') : $to);
        if ($to === null) {
            throw new \RuntimeException('No valid alert address');
        }
        $html = '<pre style="font-family:Arial,Helvetica,sans-serif;font-size:14px;white-space:pre-wrap">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre>';
        $this->mailer->send($to, $subject, $text, $html);
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        $raw = @file_get_contents($this->privateDir . '/cron-state.json');
        $state = $raw === false ? null : json_decode($raw, true);
        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $path = $this->privateDir . '/cron-state.json';
        file_put_contents($path, (string) json_encode($state), LOCK_EX);
        @chmod($path, 0600);
    }
}
