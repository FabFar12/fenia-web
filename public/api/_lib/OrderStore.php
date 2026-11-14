<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Our orders. Status changes are compare-and-set (`… WHERE status = :from`):
 * when MP notifies the same payment twice at the same time, exactly one
 * request wins and only that one delivers (threat model A2, C3, C5; tests
 * U-10, U-18).
 */
final class OrderStore
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array{reference: string, kit_id: string, email: string, expected_cents: int, currency: string, client_ip_hash: ?string} $order
     */
    public function create(array $order, string $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO orders (reference, kit_id, email, expected_cents, currency, status, client_ip_hash, terms_accepted_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $order['reference'], $order['kit_id'], $order['email'], $order['expected_cents'], $order['currency'],
            'pending', $order['client_ip_hash'], $now, $now, $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->one('SELECT * FROM orders WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        return $this->one('SELECT * FROM orders WHERE reference = ?', [$reference]);
    }

    /** @return array<string, mixed>|null */
    public function findByMpOrderId(string $mpOrderId): ?array
    {
        return $this->one('SELECT * FROM orders WHERE mp_order_id = ?', [$mpOrderId]);
    }

    /** Binds our order to the MP order created for it, once and only once. */
    public function bindMpOrder(int $id, string $mpOrderId, string $now): bool
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET mp_order_id = ?, updated_at = ? WHERE id = ? AND mp_order_id IS NULL');
        $stmt->execute([$mpOrderId, $now, $id]);
        return $stmt->rowCount() === 1;
    }

    /** Compare-and-set. True only for the one caller that made the change. */
    public function transition(int $id, string $from, string $to, ?string $reason, string $now): bool
    {
        // Decided in PHP: comparing a parameter with a literal in SQL failed on
        // Hostinger's MariaDB with "Illegal mix of collations" (2026-11-07).
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET status = ?, status_reason = ?, updated_at = ?, paid_at = COALESCE(paid_at, ?)
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([$to, $reason, $now, $to === 'paid' ? $now : null, $id, $from]);
        return $stmt->rowCount() === 1;
    }

    public function markEmailSent(int $id, string $now): void
    {
        $this->pdo->prepare('UPDATE orders SET email_sent_at = ?, updated_at = ? WHERE id = ?')->execute([$now, $now, $id]);
    }

    /**
     * Paid orders whose delivery email has not gone out yet (reconciliation
     * retries them; threat model C2), paid after `$paidAfter`: an address that
     * keeps bouncing is not retried forever.
     *
     * @return list<array<string, mixed>>
     */
    public function paidWithoutEmail(string $paidAfter): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE status = 'paid' AND email_sent_at IS NULL AND paid_at > ? ORDER BY id");
        $stmt->execute([$paidAfter]);
        return $stmt->fetchAll();
    }

    /**
     * Paid orders whose Mercado Pago commission is not known yet (D29), oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function paidWithoutFees(string $paidBefore, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare("SELECT id, reference FROM orders WHERE status = 'paid' AND fees_at IS NULL AND paid_at IS NOT NULL AND paid_at <= ? ORDER BY paid_at LIMIT $limit");
        $stmt->execute([$paidBefore]);
        return $stmt->fetchAll();
    }

    /** What Mercado Pago kept on a sale (D29): net received, the difference with the price, how it was paid. */
    public function setFees(int $id, int $netCents, int $feeCents, ?string $payType, ?int $installments, string $now): void
    {
        $this->pdo->prepare('UPDATE orders SET net_cents = ?, fee_cents = ?, pay_type = ?, installments = ?, fees_at = ? WHERE id = ?')
            ->execute([$netCents, $feeCents, $payType, $installments, $now, $id]);
    }

    /** @return list<array<string, mixed>> pending orders already bound to an MP order */
    public function pendingWithMpOrder(string $createdAfter): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE status = 'pending' AND mp_order_id IS NOT NULL AND created_at > ? ORDER BY id");
        $stmt->execute([$createdAfter]);
        return $stmt->fetchAll();
    }

    /**
     * Paid orders for "No me llegó" (threat model A15): only ever resent to
     * the email stored with the purchase.
     *
     * @return list<array<string, mixed>>
     */
    public function paidByEmail(string $email): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE email = ? AND status = 'paid' ORDER BY id");
        $stmt->execute([$email]);
        return $stmt->fetchAll();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
