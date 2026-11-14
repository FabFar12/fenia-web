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
    /**
     * 2 (D25, "Tu kit"): download_log.kind, and links raised from 5 to 10 downloads.
     * 3 (ADR-029, metrics): site_events, sessions, speed_checks.
     */
    public const SCHEMA_VERSION = 3;

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
        // `kind`: what was served (see Shop::LOG_KINDS); NULL = a download
        // logged before schema 2.
        $pdo->exec("CREATE TABLE IF NOT EXISTS download_log (
            id $id,
            token_id INT NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            kind VARCHAR(16) NULL,
            created_at DATETIME NOT NULL" . $index('ix_log_token', 'token_id') . "
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_events (
            id $id,
            bucket VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL" . $index('ix_rate_bucket', 'bucket, created_at') . "
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (version INT NOT NULL)$tail");

        // Private visit counter (ADR-029). No cookies and nothing personal:
        // `visitor` is a hash that changes every day, so nobody can be followed
        // from one day to the next. `day` is the Argentina date, 'Y-m-d'.
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_events (
            id $id,
            at DATETIME NOT NULL,
            day CHAR(10) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            path VARCHAR(191) NOT NULL,
            source VARCHAR(64) NOT NULL,
            campaign VARCHAR(80) NULL,
            device VARCHAR(8) NOT NULL,
            visitor CHAR(16) NOT NULL,
            entry SMALLINT NOT NULL DEFAULT 0" . $index('ix_events_day', 'day, kind') . "
        )$tail");

        // Logged-in sessions: the owners today, buyers in D25 phase 2. Only the
        // SHA-256 of the cookie's token is stored.
        $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            id $id,
            token_hash CHAR(64) NOT NULL UNIQUE,
            subject VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL" . $index('ix_sessions_subject', 'subject') . "
        )$tail");

        // Daily speed check of the site (PageSpeed Insights, lab and field data).
        $pdo->exec("CREATE TABLE IF NOT EXISTS speed_checks (
            id $id,
            day CHAR(10) NOT NULL,
            url VARCHAR(191) NOT NULL,
            score INT NULL,
            lcp_ms INT NULL,
            cls_milli INT NULL,
            tbt_ms INT NULL,
            field_lcp_ms INT NULL,
            field_inp_ms INT NULL,
            field_cls_milli INT NULL,
            error VARCHAR(160) NULL,
            created_at DATETIME NOT NULL" . $index('ix_speed_day', 'day') . "
        )$tail");

        if (!$mysql) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_orders_email ON orders (email)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_tokens_order ON download_tokens (order_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_log_token ON download_log (token_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_rate_bucket ON rate_events (bucket, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_events_day ON site_events (day, kind)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_sessions_subject ON sessions (subject)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS ix_speed_day ON speed_checks (day)');
        }

        $current = $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        $current = $current === null || $current === false ? 0 : (int) $current;
        if ($current >= 1 && $current < 2) {
            self::upgradeTo2($pdo);
        }
        if ($current < self::SCHEMA_VERSION) {
            $pdo->prepare('INSERT INTO schema_version (version) VALUES (?)')->execute([self::SCHEMA_VERSION]);
        }
    }

    /**
     * Schema 1 → 2. Safe to run twice at the same time (two first requests
     * after a deploy): the column is added once, the UPDATE is idempotent.
     */
    private static function upgradeTo2(\PDO $pdo): void
    {
        if (!self::hasColumn($pdo, 'download_log', 'kind')) {
            try {
                $pdo->exec('ALTER TABLE download_log ADD COLUMN kind VARCHAR(16) NULL');
            } catch (\PDOException $e) {
                if (!self::hasColumn($pdo, 'download_log', 'kind')) {
                    throw $e;
                }
            }
        }
        // D25: the cap goes from 5 to 10 file downloads, also for links already sent.
        $pdo->exec('UPDATE download_tokens SET max_downloads = 10 WHERE max_downloads < 10');
    }

    private static function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        if (self::driver($pdo) === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        }
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $info) {
            if ($info['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    private static function driver(\PDO $pdo): string
    {
        return (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }
}
