<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Accounts of the site's owners (the dev and Fab), who see the internal
 * metrics (ADR-029). User and password, as the dev chose; the users and their
 * password hashes live in the server config, never in git.
 *
 * Sessions are rows in `sessions` (only the SHA-256 of the cookie's token),
 * keyed by a subject such as "owner:fab", so the same table can hold buyers'
 * sessions in D25 phase 2. The session cookie is HttpOnly, HTTPS-only, never
 * sent from another site and only to /api/.
 */
final class Owners
{
    public const COOKIE = 'fenia_sesion';
    /** Readable flag so the visit counter skips the owners' own visits. Holds no secret. */
    public const FLAG = 'fm_owner';
    public const DAYS = 30;

    /** bcrypt (cost 10, like the owners' hashes) of a random value nobody knows: unknown users cost the same time. */
    private const DUMMY_HASH = '$2y$10$7Eyz3ZmIbmmyF5AWes/9e.GKdmvWKBjZDjnzHqPYl.BzUkgnyDmVa';

    /** bucket => [limit, window seconds]: guessing passwords (threat model D6). */
    private const LIMITS = [
        'login-ip' => [10, 900],
        'login-user' => [10, 3600],
    ];

    private \PDO $pdo;
    private Config $config;
    private RateLimiter $limiter;
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(\PDO $pdo, Config $config, ?callable $clock = null)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->limiter = new RateLimiter($pdo);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2?: string} status, body, and the new session token
     */
    public function login(string $user, string $password, string $ip): array
    {
        $owners = $this->config->owners();
        if ($owners === []) {
            return [503, ['error' => 'unavailable']];
        }
        if (!$this->allow('login-ip', $ip)) {
            return [429, ['error' => 'rate_limited']];
        }
        $user = strtolower(trim($user));
        $known = preg_match('/^[a-z0-9._-]{1,40}$/D', $user) === 1 && isset($owners[$user]);
        if (!$this->allow('login-user', $known ? $user : '?')) {
            return [429, ['error' => 'rate_limited']];
        }
        // The same work and the same answer for a wrong password and an unknown user.
        $ok = password_verify(mb_substr($password, 0, 200), $known ? $owners[$user] : self::DUMMY_HASH);
        if (!$ok || !$known) {
            return [401, ['error' => 'credentials']];
        }

        $token = Tokens::generate();
        $now = $this->now();
        $this->pdo->prepare('INSERT INTO sessions (token_hash, subject, created_at, expires_at, last_seen_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$token['hash'], 'owner:' . $user, Db::now($now), Db::now($now->modify('+' . self::DAYS . ' days')), Db::now($now)]);
        return [200, ['ok' => true, 'user' => $user], $token['token']];
    }

    /** The owner logged in with this cookie token, or null. */
    public function current(?string $token): ?string
    {
        if ($token === null || !Tokens::isWellFormed($token)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, subject, expires_at, last_seen_at FROM sessions WHERE token_hash = ?');
        $stmt->execute([Tokens::hash($token)]);
        $row = $stmt->fetch();
        if ($row === false || (string) $row['expires_at'] <= Db::now($this->now()) || !str_starts_with((string) $row['subject'], 'owner:')) {
            return null;
        }
        $user = substr((string) $row['subject'], 6);
        if (!isset($this->config->owners()[$user])) {
            return null; // removed from the config: the session dies with the account
        }
        $now = Db::now($this->now());
        if (substr((string) $row['last_seen_at'], 0, 13) !== substr($now, 0, 13)) {
            $this->pdo->prepare('UPDATE sessions SET last_seen_at = ? WHERE id = ?')->execute([$now, (int) $row['id']]);
        }
        return $user;
    }

    public function logout(?string $token): void
    {
        if ($token !== null && Tokens::isWellFormed($token)) {
            $this->pdo->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([Tokens::hash($token)]);
        }
    }

    /** Hourly job: forget expired sessions. */
    public function purge(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at <= ?');
        $stmt->execute([Db::now($this->now())]);
        return $stmt->rowCount();
    }

    private function allow(string $bucket, string $value): bool
    {
        [$limit, $window] = self::LIMITS[$bucket];
        return $this->limiter->allow(RateLimiter::key($bucket, $value, (string) $this->config->get('hash_salt')), $limit, $window, $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }
}
