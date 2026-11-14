<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Download tokens (only their SHA-256 is stored; see Tokens.php).
 *
 * `consume()` is a single conditional UPDATE: a token counts a download only
 * if it is not revoked, not expired, under its limit and its order is paid.
 * Two simultaneous clicks on the 5th download cannot both get a 6th
 * (threat model A4, A13, A14; tests U-21, S-13, S-14).
 */
final class TokenStore
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function issue(int $orderId, string $tokenHash, string $expiresAt, int $maxDownloads, string $now, int $generation = 1): int
    {
        $this->pdo->prepare(
            'INSERT INTO download_tokens (order_id, generation, token_hash, expires_at, max_downloads, download_count, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        )->execute([$orderId, $generation, $tokenHash, $expiresAt, $maxDownloads, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null the newest token of an order */
    public function latestForOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM download_tokens WHERE order_id = ? ORDER BY generation DESC LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Read-only view of a token for the download page (consumes nothing).
     *
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        return $this->findWhere('t.token_hash = ?', $tokenHash);
    }

    /**
     * Same view by id, for a view pass ("Tu kit"), which carries the token id
     * and never the token itself.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $tokenId): ?array
    {
        return $this->findWhere('t.id = ?', $tokenId);
    }

    /**
     * @param int|string $value
     * @return array<string, mixed>|null
     */
    private function findWhere(string $condition, $value): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*, o.kit_id, o.status AS order_status, o.email AS order_email, o.paid_at AS order_paid_at
             FROM download_tokens t JOIN orders o ON o.id = t.order_id WHERE $condition"
        );
        $stmt->execute([$value]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Counts one download and returns what to serve, or null if the token
     * cannot be used (unknown, revoked, expired, exhausted, order not paid).
     *
     * @return array{token_id: int, order_id: int, kit_id: string}|null
     */
    public function consume(string $tokenHash, string $now): ?array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE download_tokens SET download_count = download_count + 1
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > ? AND download_count < max_downloads
               AND order_id IN (SELECT id FROM orders WHERE status = 'paid')"
        );
        $stmt->execute([$tokenHash, $now]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $row = $this->pdo->prepare(
            'SELECT t.id AS token_id, t.order_id, o.kit_id FROM download_tokens t JOIN orders o ON o.id = t.order_id WHERE t.token_hash = ?'
        );
        $row->execute([$tokenHash]);
        $found = $row->fetch();
        return $found === false ? null : [
            'token_id' => (int) $found['token_id'],
            'order_id' => (int) $found['order_id'],
            'kit_id' => (string) $found['kit_id'],
        ];
    }

    /** Refund / chargeback (threat model A13). Returns how many tokens were revoked. */
    public function revokeForOrder(int $orderId, string $now): int
    {
        $stmt = $this->pdo->prepare('UPDATE download_tokens SET revoked_at = ? WHERE order_id = ? AND revoked_at IS NULL');
        $stmt->execute([$now, $orderId]);
        return $stmt->rowCount();
    }

    /** @param string $kind what was served: a download ('zip', 'guide') or a view (see Shop::LOG_KINDS) */
    public function logDownload(int $tokenId, ?string $ipHash, ?string $userAgent, string $now, string $kind = 'zip'): void
    {
        $this->pdo->prepare('INSERT INTO download_log (token_id, ip_hash, user_agent, kind, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$tokenId, $ipHash, $userAgent === null ? null : mb_substr($userAgent, 0, 255), $kind, $now]);
    }

    /**
     * Distinct IPs that downloaded files with a token (link-sharing alert,
     * threat model A14). Views do not count: a phone changes IP all the time,
     * and reading the kit is free.
     */
    public function distinctIps(int $tokenId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT ip_hash) FROM download_log WHERE token_id = ? AND (kind IS NULL OR kind IN ('zip', 'guide'))");
        $stmt->execute([$tokenId]);
        return (int) $stmt->fetchColumn();
    }
}
