<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * PDO connection and schema. MySQL on Hostinger, SQLite in the unit tests:
 * the queries in the stores are written to run on both. Every query is a
 * prepared statement (threat model D1). Times are stored as UTC
 * 'Y-m-d H:i:s' strings, which compare correctly on both engines.
 */
final class Db
{
    public const SCHEMA_VERSION = 1;

    public static function connect(string $dsn, string $user = '', string $password = ''): \PDO
    {
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (self::driver($pdo) === 'mysql') {
            $pdo->exec("SET time_zone = '+00:00'");
            // Same collation as the tables: Hostinger's MariaDB defaults the
            // connection to utf8mb4_general_ci, and mixing both fails (error 1267).
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        return $pdo;
    }

    public static function now(?\DateTimeImmutable $at = null): string
    {
        return ($at ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Idempotent: creates whatever is missing. Runs on the API's first use. */
    public static function migrate(\PDO $pdo): void
    {
        $mysql = self::driver($pdo) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $index = static fn (string $name, string $cols): string => $mysql ? ", KEY $name ($cols)" : '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id $id,
            reference CHAR(32) NOT NULL UNIQUE,
            kit_id VARCHAR(64) NOT NULL,
            email VARCHAR(254) NOT NULL,
            expected_cents INT NOT NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(16) NOT NULL,
            status_reason VARCHAR(64) NULL,
            mp_order_id VARCHAR(64) NULL UNIQUE,
            client_ip_hash CHAR(64) NULL,
            terms_accepted_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            email_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL" . $index('ix_orders_email', 'email') . "
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS download_tokens (
            id $id,
            order_id INT NOT NULL,
            generation INT NOT NULL DEFAULT 1,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            max_downloads INT NOT NULL,
            download_count INT NOT NULL DEFAULT 0,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (order_id, generation)" . $index('ix_tokens_order', 'order_id') . "
        )$tail");

        // Evidence for disputes (threat model A13) and link-sharing alerts (A14).
        $pdo->exec("CREATE TABLE IF NOT EXISTS download_log (
            id $id,
            token_id INT NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL" . $index('ix_log_token', 'token_id') . "
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_events (
            id $id,
            bucket VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL" . $index('ix_rate_bucket', 'bucket, created_at') . "
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (version INT NOT NULL)$tail");

        if (!$mysql) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_orders_email ON orders (email)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_tokens_order ON download_tokens (order_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_log_token ON download_log (token_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_rate_bucket ON rate_events (bucket, created_at)');
        }

        $current = $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        if ($current === null || (int) $current < self::SCHEMA_VERSION) {
            $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([self::SCHEMA_VERSION]);
        }
    }

    private static function driver(\PDO $pdo): string
    {
        return (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}
