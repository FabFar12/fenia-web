<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Server configuration, read from `<privateDir>/config.php` (a PHP file that
 * returns an array; template in config.example.php). It holds every secret,
 * so it never lives in git or under public_html.
 *
 * Fails closed: a missing file, a missing key or a config written for another
 * environment throws, and endpoints answer "unavailable" (ADR-028).
 */
final class Config
{
    /** key => expected type */
    private const REQUIRED = [
        'env' => 'string',
        'site_url' => 'string',
        'sales_enabled' => 'bool',
        'mp_access_token' => 'string',
        'mp_webhook_secret' => 'string',
        'mp_collector_id' => 'string',
        'db_dsn' => 'string',
        'db_user' => 'string',
        'db_password' => 'string',
        'smtp_host' => 'string',
        'smtp_port' => 'int',
        'smtp_user' => 'string',
        'smtp_password' => 'string',
        'mail_from' => 'string',
        'mail_reply_to' => 'string',
        'hash_salt' => 'string',
        'kits' => 'array',
    ];

    private const DEFAULTS = [
        'download_days' => 30,      // D3
        'max_downloads' => 10,      // D25: only file downloads count; reading and the fichas do not
        'order_ttl_minutes' => 60,  // checkout links expire (threat model B3)
        'alert_email' => '',        // alerts and daily report; '' = mail_reply_to
        'daily_report' => true,
    ];

    /**
     * Optional files of a kit besides its ZIP: name => [extension, size key].
     * The size (pages, number of fichas) is only shown on the buyer's page.
     */
    public const RESOURCES = [
        'guide' => ['pdf', 'pages'],         // D24: the guide PDF, opens on a phone without unzipping
        'fichas' => ['html', 'count'],       // D25 "Tu kit": interactive fichas, served as a page of the site
        'imprimibles' => ['html', 'count'],  // D25 "Tu kit": the printable versions
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromFile(string $path, string $expectedEnv): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Config file missing');
        }
        return self::fromArray(require $path, $expectedEnv);
    }

    /** @param mixed $values */
    public static function fromArray($values, string $expectedEnv): self
    {
        if (!is_array($values)) {
            throw new \RuntimeException('Config must return an array');
        }
        foreach (self::REQUIRED as $key => $type) {
            if (!array_key_exists($key, $values) || get_debug_type($values[$key]) !== $type) {
                throw new \RuntimeException("Config key '$key' missing or not $type");
            }
            if ($type === 'string' && trim($values[$key]) === '' && $key !== 'db_password') {
                throw new \RuntimeException("Config key '$key' is empty");
            }
        }
        if (strlen($values['hash_salt']) < 32) {
            throw new \RuntimeException("Config key 'hash_salt' must be at least 32 characters");
        }
        // A staging config copied to production (or the reverse) must not run.
        if ($values['env'] !== $expectedEnv) {
            throw new \RuntimeException('Config belongs to another environment');
        }
        foreach ($values['kits'] as $kitId => $kit) {
            if (
                !is_string($kitId) || !Validate::kitId($kitId) || !is_array($kit)
                || !isset($kit['file'], $kit['sha256']) || !is_string($kit['file'])
                || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.zip$/D', $kit['file'])
                || !is_string($kit['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $kit['sha256'])
            ) {
                throw new \RuntimeException('Config kit entry malformed');
            }
            foreach (self::RESOURCES as $name => [$extension, $sizeKey]) {
                $resource = $kit[$name] ?? null;
                if ($resource !== null && (
                    !is_array($resource) || !isset($resource['file'], $resource['sha256'])
                    || !is_string($resource['file']) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.' . $extension . '$/D', $resource['file'])
                    || !is_string($resource['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $resource['sha256'])
                    || (isset($resource[$sizeKey]) && (!is_int($resource[$sizeKey]) || $resource[$sizeKey] < 1 || $resource[$sizeKey] > 999))
                )) {
                    throw new \RuntimeException("Config kit $name malformed");
                }
            }
        }
        return new self($values + self::DEFAULTS);
    }

    /** @return mixed */
    public function get(string $key)
    {
        if (!array_key_exists($key, $this->values)) {
            throw new \RuntimeException("Unknown config key '$key'");
        }
        return $this->values[$key];
    }

    /** Kill switch (threat model B3): checkout stops without a deploy. */
    public function salesEnabled(): bool
    {
        return $this->values['sales_enabled'] === true;
    }

    /** @return array{file: string, sha256: string}|null */
    public function kit(string $kitId): ?array
    {
        $kit = $this->values['kits'][$kitId] ?? null;
        return is_array($kit) ? ['file' => $kit['file'], 'sha256' => $kit['sha256']] : null;
    }

    /** The kit's guide PDF, served on its own, if configured. @return array{file: string, sha256: string}|null */
    public function guide(string $kitId): ?array
    {
        $guide = $this->resource($kitId, 'guide');
        return $guide === null ? null : ['file' => $guide['file'], 'sha256' => $guide['sha256']];
    }

    /**
     * One of the kit's optional files (see RESOURCES), if configured.
     *
     * @return array{file: string, sha256: string, size: ?int}|null
     */
    public function resource(string $kitId, string $name): ?array
    {
        $resource = isset(self::RESOURCES[$name]) ? ($this->values['kits'][$kitId][$name] ?? null) : null;
        if (!is_array($resource)) {
            return null;
        }
        $size = $resource[self::RESOURCES[$name][1]] ?? null;
        return ['file' => $resource['file'], 'sha256' => $resource['sha256'], 'size' => is_int($size) ? $size : null];
    }
}
