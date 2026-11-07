<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * The sales flow (ADR-028, Checkout Pro via Orders, D14): checkout → MP
 * webhook / status poll → delivery (token + email) → download, plus
 * "No me llegó" and reconciliation.
 *
 * Endpoints in public/api/*.php only parse HTTP and call one method here.
 * Every method returns `[httpStatus, jsonBody]`, except download(), which
 * returns the file to stream. Clock, MP transport and mailer are injected, so
 * the whole flow runs in the unit tests without network (ShopTest).
 *
 * Download links are deterministic: token = HMAC(key, "dl|<ref>|<generation>").
 * Only its SHA-256 is stored; /gracias and the email recompute the same link
 * from the order reference, so nothing secret is ever stored in clear.
 */
final class Shop
{
    /** /gracias shows the download link this long after payment; later, only the email has it. */
    private const STATUS_LINK_HOURS = 24;
    /** Distinct IPs on one link before an alert (threat model A14). */
    private const SHARING_ALERT_IPS = 3;
    /** First link + 2 renewals through "No me llegó"; after that, support. */
    private const MAX_GENERATIONS = 3;

    /** bucket => [limit, window seconds]. Threat model A4, B3, A15; tests U-30, S-08, S-16. */
    private const LIMITS = [
        'checkout-ip' => [10, 3600],
        'checkout-email' => [5, 3600],
        'status-ip' => [120, 600],
        'status-sync' => [1, 5],
        'download-ip' => [60, 3600],
        'download-bad-ip' => [20, 3600],
        'resend-ip' => [5, 3600],
        'resend-email' => [3, 86400],
    ];

    private Config $config;
    private Catalog $catalog;
    private OrderStore $orders;
    private TokenStore $tokens;
    private RateLimiter $limiter;
    private MpClient $mp;
    private MailSender $mailer;
    private string $kitsDir;
    /** @var callable(): \DateTimeImmutable */
    private $clock;
    /** @var callable(string, string, array<string, mixed>): void */
    private $log;

    /**
     * @param callable(string, string, array<string, mixed>): void $log (level, event, context); never gets secrets
     * @param (callable(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        Config $config,
        Catalog $catalog,
        \PDO $pdo,
        MpClient $mp,
        MailSender $mailer,
        string $kitsDir,
        callable $log,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->catalog = $catalog;
        $this->orders = new OrderStore($pdo);
        $this->tokens = new TokenStore($pdo);
        $this->limiter = new RateLimiter($pdo);
        $this->mp = $mp;
        $this->mailer = $mailer;
        $this->kitsDir = rtrim($kitsDir, '/');
        $this->log = $log;
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    // ── Checkout ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $in {kit, email, email_confirm, accept_terms, website}
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function checkout(array $in, string $ip): array
    {
        if (!$this->config->salesEnabled()) {
            return [503, ['error' => 'unavailable']];
        }
        // Invalid requests are refused before the rate limits: they reach
        // neither MP nor the database. The limits count only attempts that
        // would create an order (card testing, threat model B3).
        // Honeypot: people never see the field, bots fill it.
        if (($in['website'] ?? '') !== '') {
            $this->log('warning', 'checkout_honeypot', ['ip' => $this->hashed($ip)]);
            return [400, ['error' => 'invalid']];
        }

        $kitId = is_string($in['kit'] ?? null) ? $in['kit'] : '';
        $product = $this->catalog->purchasable($kitId);
        if ($product === null || $this->config->kit($kitId) === null) {
            return [404, ['error' => 'not_for_sale']];
        }

        $email = is_string($in['email'] ?? null) ? Validate::email($in['email']) : null;
        $confirm = is_string($in['email_confirm'] ?? null) ? Validate::email($in['email_confirm']) : null;
        if ($email === null || $confirm !== $email) {
            return [400, ['error' => 'email']];
        }
        if (($in['accept_terms'] ?? null) !== true) {
            return [400, ['error' => 'terms']];
        }
        if (!$this->allow('checkout-ip', $ip) || !$this->allow('checkout-email', $email)) {
            return [429, ['error' => 'rate_limited']];
        }

        $now = $this->now();
        $reference = Tokens::orderReference();
        $orderId = $this->orders->create([
            'reference' => $reference,
            'kit_id' => $kitId,
            'email' => $email,
            'expected_cents' => $product['price_cents'],
            'currency' => $product['currency'],
            'client_ip_hash' => $this->hashed($ip),
        ], Db::now($now));

        try {
            $mpOrder = $this->mp->createOrder($this->orderPayload($product, $email, $reference), $reference);
        } catch (MpException $e) {
            $this->orders->transition($orderId, 'pending', 'failed', 'mp_create_' . $e->getCode(), Db::now($now));
            $this->log('error', 'checkout_mp_error', ['order' => $orderId, 'http' => $e->getCode()]);
            return [503, ['error' => 'unavailable']];
        }

        $mpOrderId = is_string($mpOrder['id'] ?? null) ? $mpOrder['id'] : '';
        $url = is_string($mpOrder['checkout_url'] ?? null) ? $mpOrder['checkout_url'] : '';
        if (!Validate::mpId($mpOrderId) || !self::isMpCheckoutUrl($url)) {
            $this->orders->transition($orderId, 'pending', 'failed', 'mp_create_malformed', Db::now($now));
            $this->log('error', 'checkout_mp_malformed', ['order' => $orderId]);
            return [503, ['error' => 'unavailable']];
        }
        $this->orders->bindMpOrder($orderId, $mpOrderId, Db::now($now));
        $this->log('info', 'checkout_created', ['order' => $orderId, 'kit' => $kitId, 'mp_order' => $mpOrderId]);

        return [200, ['checkout_url' => $url]];
    }

    /**
     * Request body for `POST /v1/orders` (shape from the official SDK example,
     * examples/Order/CreateCheckoutPRO.php). Price and title come from the
     * catalogue, never from the browser (threat model A8).
     *
     * @param array{id: string, title: string, price_cents: int, currency: string} $product
     * @return array<string, mixed>
     */
    private function orderPayload(array $product, string $email, string $reference): array
    {
        $amount = Money::fromCents($product['price_cents']);
        $site = rtrim((string) $this->config->get('site_url'), '/');
        $back = $site . '/gracias/?ref=' . $reference;

        return [
            'type' => 'online',
            'processing_mode' => 'manual',
            'total_amount' => $amount,
            'external_reference' => $reference,
            'capture_mode' => 'automatic',
            'description' => $product['title'],
            'expiration_time' => 'PT' . (int) $this->config->get('order_ttl_minutes') . 'M',
            'payer' => ['email' => $email],
            'config' => [
                'statement_descriptor' => 'FENIA',
                'online' => [
                    'success_url' => $back,
                    'pending_url' => $back,
                    'failure_url' => $site . '/kits/' . $product['id'] . '/',
                    'auto_return' => 'approved',
                ],
                // D4: instant approvals only, no cash vouchers.
                'payment_method' => ['not_allowed_types' => ['ticket', 'atm']],
            ],
            'items' => [[
                'external_code' => $product['id'],
                'title' => $product['title'],
                'quantity' => 1,
                'unit_price' => $amount,
            ]],
        ];
    }

    public static function isMpCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && (bool) preg_match('/(^|\.)mercadopago\.com(\.ar)?$/D', strtolower((string) ($parts['host'] ?? '')));
    }

    // ── Webhook and sync ────────────────────────────────────────────────────

    /**
     * MP notification. The body is only a hint: the order is always re-read
     * from MP's API (threat model A1). Answers 200 to anything that need not be
     * retried, 5xx when MP should retry.
     *
     * @param array<string, mixed> $query $_GET
     * @param array<string, string> $headers lowercased names
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function webhook(array $query, string $rawQuery, array $headers, string $body): array
    {
        $dataId = MpSignature::dataIdFromQuery($query, $rawQuery);
        $secret = (string) $this->config->get('mp_webhook_secret');
        if (!MpSignature::isValid($headers['x-signature'] ?? null, $headers['x-request-id'] ?? null, $dataId, $secret)) {
            $this->log('warning', 'webhook_bad_signature', []);
            return [401, ['error' => 'signature']];
        }

        $payload = json_decode($body, true);
        $type = (string) ($query['type'] ?? $query['topic'] ?? (is_array($payload) ? ($payload['type'] ?? '') : ''));
        if ($type !== 'order') {
            return [200, ['ok' => true, 'ignored' => 'type']];
        }
        if ($dataId === null || !Validate::mpId($dataId)) {
            return [200, ['ok' => true, 'ignored' => 'id']];
        }

        try {
            $result = $this->sync($dataId);
        } catch (MpException $e) {
            $this->log('error', 'webhook_mp_error', ['mp_order' => $dataId, 'http' => $e->getCode()]);
            return [502, ['error' => 'retry']];
        }
        return [200, ['ok' => true, 'result' => $result]];
    }

    /**
     * Reads an MP order and applies OrderRules to ours. Safe to call any number
     * of times, concurrently: transitions are compare-and-set.
     *
     * @return string what happened (for logs and tests)
     */
    public function sync(string $mpOrderId): string
    {
        $mpOrder = $this->mp->getOrder($mpOrderId);
        $reference = is_string($mpOrder['external_reference'] ?? null) ? $mpOrder['external_reference'] : '';
        $order = Validate::orderReference($reference) ? $this->orders->findByReference($reference) : null;
        if ($order === null) {
            $this->log('warning', 'sync_unknown_reference', ['mp_order' => $mpOrderId]);
            return 'unknown_reference';
        }

        $decision = OrderRules::decide($mpOrder, [
            'external_reference' => (string) $order['reference'],
            'status' => (string) $order['status'],
            'expected_cents' => (int) $order['expected_cents'],
            'currency' => (string) $order['currency'],
            'mp_order_id' => $order['mp_order_id'] === null ? null : (string) $order['mp_order_id'],
        ], ['collector_id' => (string) $this->config->get('mp_collector_id')]);

        $id = (int) $order['id'];
        $now = Db::now($this->now());
        $context = ['order' => $id, 'mp_order' => $mpOrderId, 'reason' => $decision['reason']];

        switch ($decision['action']) {
            case OrderRules::DELIVER:
                if (!$this->orders->transition($id, 'pending', 'paid', $decision['reason'], $now)) {
                    return 'already_handled';
                }
                $this->log('info', 'order_paid', $context);
                $this->sendDelivery($id); // issues the link first; /gracias works even if the mail fails
                return 'delivered';

            case OrderRules::REVOKE:
                if ($this->orders->transition($id, 'paid', 'revoked', $decision['reason'], $now)) {
                    $this->tokens->revokeForOrder($id, $now);
                    $this->log('warning', 'order_revoked', $context);
                    return 'revoked';
                }
                return 'already_handled';

            case OrderRules::REVIEW:
                // A paid order keeps its downloads (e.g. a duplicate second
                // payment is refunded by hand, B5); only pending ones are parked.
                if ($order['status'] === 'pending') {
                    $this->orders->transition($id, 'pending', 'review', $decision['reason'], $now);
                }
                $this->log('alert', 'order_review', $context);
                return 'review';

            default:
                return 'ignored:' . $decision['reason'];
        }
    }

    // ── Status (/gracias) ───────────────────────────────────────────────────

    /** @return array{0: int, 1: array<string, mixed>} */
    public function status(string $reference, string $ip): array
    {
        if (!Validate::orderReference($reference)) {
            return [400, ['state' => 'unknown']];
        }
        if (!$this->allow('status-ip', $ip)) {
            return [429, ['error' => 'rate_limited']];
        }
        $order = $this->orders->findByReference($reference);
        if ($order === null) {
            return [404, ['state' => 'unknown']];
        }

        // Pull fallback: if the webhook is late or lost, the buyer waiting on
        // /gracias triggers the same sync (at most once every few seconds).
        if ($order['status'] === 'pending' && $order['mp_order_id'] !== null && $this->allow('status-sync', $reference)) {
            try {
                $this->sync((string) $order['mp_order_id']);
                $order = $this->orders->findByReference($reference) ?? $order;
            } catch (MpException $e) {
                $this->log('warning', 'status_sync_error', ['order' => (int) $order['id'], 'http' => $e->getCode()]);
            }
        }

        switch ($order['status']) {
            case 'paid':
                $body = ['state' => 'paid', 'email_masked' => self::maskEmail((string) $order['email'])];
                $paidAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($order['paid_at'] ?? ''), new \DateTimeZone('UTC'));
                $token = $this->currentToken($order, false);
                if ($paidAt !== false && $token !== null && $this->now() < $paidAt->modify('+' . self::STATUS_LINK_HOURS . ' hours')) {
                    $body['download_url'] = '/descarga/#t=' . $this->tokenFor((string) $order['reference'], (int) $token['generation']);
                }
                return [200, $body];
            case 'pending':
            case 'review':
                return [200, ['state' => (string) $order['status']]];
            default:
                return [200, ['state' => 'unknown']];
        }
    }

    // ── Download ────────────────────────────────────────────────────────────

    /**
     * Read-only (the page shows what is left); counts nothing.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function downloadInfo(string $token, string $ip): array
    {
        $row = $this->lookupToken($token, $ip);
        if (is_int($row)) {
            return [$row, ['usable' => false]];
        }
        $product = $this->catalog->find((string) $row['kit_id']);
        $expires = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['expires_at'], new \DateTimeZone('UTC'));
        return [200, [
            'usable' => true,
            'kit_title' => $product['title'] ?? 'Tu kit FENIA',
            'remaining' => (int) $row['max_downloads'] - (int) $row['download_count'],
            'max' => (int) $row['max_downloads'],
            'expires_on' => $expires === false ? '' : $expires->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('d/m/Y'),
        ]];
    }

    /**
     * Counts one download (only on an explicit POST; threat model C6) and says
     * which file to stream.
     *
     * @return array{status: int, path?: string, filename?: string}
     */
    public function download(string $token, string $ip, ?string $userAgent): array
    {
        $row = $this->lookupToken($token, $ip);
        if (is_int($row)) {
            return ['status' => $row];
        }
        $kit = $this->config->kit((string) $row['kit_id']);
        $path = $kit === null ? '' : $this->kitsDir . '/' . $kit['file'];
        // The ZIP must be exactly the reviewed one (test E2E-02); check before counting.
        if ($kit === null || !is_file($path) || !hash_equals($kit['sha256'], (string) hash_file('sha256', $path))) {
            $this->log('alert', 'kit_file_problem', ['kit' => (string) $row['kit_id']]);
            return ['status' => 503];
        }

        $used = $this->tokens->consume(Tokens::hash($token), Db::now($this->now()));
        if ($used === null) {
            return ['status' => 410];
        }
        $this->tokens->logDownload($used['token_id'], $this->hashed($ip), $userAgent, Db::now($this->now()));
        if ($this->tokens->distinctIps($used['token_id']) > self::SHARING_ALERT_IPS) {
            $this->log('alert', 'link_sharing', ['order' => $used['order_id']]);
        }
        return ['status' => 200, 'path' => $path, 'filename' => $kit['file']];
    }

    /** @return array<string, mixed>|int the usable token row, or the HTTP status to answer */
    private function lookupToken(string $token, string $ip)
    {
        if (!$this->allow('download-ip', $ip)) {
            return 429;
        }
        if (!Tokens::isWellFormed($token)) {
            return 400;
        }
        $row = $this->tokens->findByHash(Tokens::hash($token));
        if ($row === null) {
            // Guessing links (threat model A4): failures are capped per IP.
            return $this->allow('download-bad-ip', $ip) ? 404 : 429;
        }
        if ($row['order_status'] !== 'paid' || Tokens::unusableReason($row, $this->now()) !== null) {
            return 410;
        }
        return $row;
    }

    // ── "No me llegó" ───────────────────────────────────────────────────────

    /**
     * Resends the links, only to the email stored with the purchase, with the
     * same answer whether or not there was one (threat model A15). A link that
     * still works is resent as is (no reset of its 30 days / 5 downloads); one
     * that expired or ran out is renewed, up to MAX_GENERATIONS.
     *
     * @param array<string, mixed> $in {email, website}
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function resend(array $in, string $ip): array
    {
        $same = [200, ['ok' => true]];
        if (!$this->allow('resend-ip', $ip)) {
            return [429, ['error' => 'rate_limited']];
        }
        if (($in['website'] ?? '') !== '') {
            return $same;
        }
        $email = is_string($in['email'] ?? null) ? Validate::email($in['email']) : null;
        if ($email === null) {
            return [400, ['error' => 'email']];
        }
        if (!$this->allow('resend-email', $email)) {
            return $same; // silent: a 429 here would reveal the address bought
        }
        foreach ($this->orders->paidByEmail($email) as $order) {
            $this->sendDelivery((int) $order['id'], true);
        }
        return $same;
    }

    // ── Reconciliation (cron, step 3.4) ─────────────────────────────────────

    /**
     * Re-syncs recent pending orders (lost webhooks) and retries delivery
     * emails that did not go out (threat model C2, C22).
     *
     * @return array{synced: int, emailed: int, errors: int}
     */
    public function reconcile(int $hours = 48): array
    {
        $stats = ['synced' => 0, 'emailed' => 0, 'errors' => 0];
        $since = Db::now($this->now()->modify('-' . $hours . ' hours'));
        foreach ($this->orders->pendingWithMpOrder($since) as $order) {
            try {
                $this->sync((string) $order['mp_order_id']);
                $stats['synced']++;
            } catch (MpException $e) {
                $stats['errors']++;
            }
        }
        foreach ($this->orders->paidWithoutEmail() as $order) {
            if ($this->sendDelivery((int) $order['id'])) {
                $stats['emailed']++;
            } else {
                $stats['errors']++;
            }
        }
        return $stats;
    }

    // ── Delivery ────────────────────────────────────────────────────────────

    /**
     * Sends the delivery email for a paid order. Never throws: the order stays
     * deliverable through /gracias and "No me llegó".
     *
     * @param bool $renew issue a fresh link if the current one expired or ran out
     */
    private function sendDelivery(int $orderId, bool $renew = false): bool
    {
        $order = $this->orders->findById($orderId);
        if ($order === null || $order['status'] !== 'paid') {
            return false;
        }
        $token = $this->currentToken($order, $renew);
        if ($token === null) {
            $this->log('alert', 'renewals_exhausted', ['order' => $orderId]);
            return false;
        }
        $site = rtrim((string) $this->config->get('site_url'), '/');
        $mail = DeliveryMail::build(
            $this->catalog->find((string) $order['kit_id'])['title'] ?? 'Tu kit FENIA',
            $site . '/descarga/#t=' . $this->tokenFor((string) $order['reference'], (int) $token['generation']),
            $site,
            (string) $this->config->get('mail_reply_to'),
            (int) $this->config->get('download_days'),
            (int) $token['max_downloads']
        );

        try {
            $this->mailer->send((string) $order['email'], $mail['subject'], $mail['text'], $mail['html']);
        } catch (\Throwable $e) {
            $this->log('error', 'mail_failed', ['order' => $orderId]);
            return false;
        }
        $this->orders->markEmailSent($orderId, Db::now($this->now()));
        $this->log('info', 'mail_sent', ['order' => $orderId, 'generation' => (int) $token['generation']]);
        return true;
    }

    /**
     * The order's newest link; issued if missing. With $renew, an expired or
     * exhausted link is replaced by a new one (fresh 30 days / 5 downloads),
     * at most MAX_GENERATIONS times; after that, support handles it.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>|null
     */
    private function currentToken(array $order, bool $renew): ?array
    {
        $id = (int) $order['id'];
        $token = $this->tokens->latestForOrder($id);
        if ($token === null) {
            $this->issueToken($id, (string) $order['reference'], 1);
            return $this->tokens->latestForOrder($id);
        }
        if (!$renew || Tokens::unusableReason($token, $this->now()) === null) {
            return $token;
        }
        $next = (int) $token['generation'] + 1;
        if ($next > self::MAX_GENERATIONS) {
            return null;
        }
        $this->issueToken($id, (string) $order['reference'], $next);
        $this->log('info', 'link_renewed', ['order' => $id, 'generation' => $next]);
        return $this->tokens->latestForOrder($id);
    }

    private function issueToken(int $orderId, string $reference, int $generation): void
    {
        try {
            $this->tokens->issue(
                $orderId,
                Tokens::hash($this->tokenFor($reference, $generation)),
                Tokens::expiresAt($this->now(), (int) $this->config->get('download_days')),
                (int) $this->config->get('max_downloads'),
                Db::now($this->now()),
                $generation
            );
        } catch (\PDOException $e) {
            // A simultaneous request (webhook + /gracias poll) issued the same
            // generation first: UNIQUE (order_id, generation) keeps just one.
            $latest = $this->tokens->latestForOrder($orderId);
            if ($latest === null || (int) $latest['generation'] < $generation) {
                throw $e;
            }
        }
    }

    /** 256-bit link token, recomputable from the order reference (see class doc). */
    private function tokenFor(string $reference, int $generation): string
    {
        $key = hash_hmac('sha256', 'download-tokens', (string) $this->config->get('hash_salt'), true);
        $raw = hash_hmac('sha256', 'dl|' . $reference . '|' . $generation, $key, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Every configured kit has its ZIP in place (health check after an upload). */
    public function kitsReady(): bool
    {
        $kits = (array) $this->config->get('kits');
        foreach (array_keys($kits) as $kitId) {
            $kit = $this->config->kit((string) $kitId);
            if ($kit === null || !is_file($this->kitsDir . '/' . $kit['file'])) {
                return false;
            }
        }
        return $kits !== [];
    }

    /** a***@gmail.com — enough for the buyer to recognise, useless to anyone else. */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private function allow(string $bucket, string $value): bool
    {
        [$limit, $window] = self::LIMITS[$bucket];
        return $this->limiter->allow(RateLimiter::key($bucket, $value, (string) $this->config->get('hash_salt')), $limit, $window, $this->now());
    }

    private function hashed(string $value): string
    {
        return hash_hmac('sha256', $value, (string) $this->config->get('hash_salt'));
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $event, array $context): void
    {
        ($this->log)($level, $event, $context);
    }
}
