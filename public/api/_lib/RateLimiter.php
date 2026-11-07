<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Sliding-window limiter backed by the database (threat model A4, B3; tests
 * U-30, S-08, S-16). Buckets carry hashed IPs or emails, never raw ones
 * (threat model D6). Approximate under heavy concurrency, which is fine for
 * slowing down bots.
 */
final class RateLimiter
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Records an attempt and says whether the caller is still under the limit. */
    public function allow(string $bucket, int $limit, int $windowSeconds, \DateTimeImmutable $now): bool
    {
        $since = Db::now($now->modify('-' . $windowSeconds . ' seconds'));
        $this->pdo->prepare('DELETE FROM rate_events WHERE bucket = ? AND created_at <= ?')->execute([$bucket, $since]);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at > ?');
        $count->execute([$bucket, $since]);
        if ((int) $count->fetchColumn() >= $limit) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO rate_events (bucket, created_at) VALUES (?, ?)')->execute([$bucket, Db::now($now)]);
        return true;
    }

    /**
     * Stable bucket key for an IP or an email. Keyed (HMAC with the config's
     * `hash_salt`): a plain SHA-256 of an IPv4 address is reversible by trying
     * all ~4 billion of them.
     */
    public static function key(string $kind, string $value, string $salt): string
    {
        return $kind . ':' . hash_hmac('sha256', strtolower(trim($value)), $salt);
    }
}
