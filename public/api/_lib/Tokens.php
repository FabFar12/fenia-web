<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Download tokens and order codes (threat model A3, A4, A14).
 *
 * Only the SHA-256 hash of a download token is stored, and lookups go by hash:
 * a leaked database does not leak working links, and response timing reveals
 * nothing about valid tokens.
 */
final class Tokens
{
    /** 32 bytes = 256 bits → 43 base64url characters. */
    private const TOKEN_BYTES = 32;

    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Cheap shape check before touching the database. */
    public static function isWellFormed(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    /** New `external_reference` for an order: 128 random bits. */
    public static function orderReference(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Expiry timestamp in UTC, in the format stored in the database. */
    public static function expiresAt(\DateTimeImmutable $now, int $days): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . $days . ' days')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Why a stored token can no longer be used, or null if it can.
     *
     * @param array{expires_at: string, download_count: int|string, max_downloads: int|string, revoked_at: ?string} $row
     */
    public static function unusableReason(array $row, \DateTimeImmutable $now): ?string
    {
        if (!empty($row['revoked_at'])) {
            return 'revoked';
        }
        $expires = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $row['expires_at'], new \DateTimeZone('UTC'));
        if ($expires === false || $now >= $expires) {
            return 'expired';
        }
        if ((int) $row['download_count'] >= (int) $row['max_downloads']) {
            return 'exhausted';
        }
        return null;
    }
}
