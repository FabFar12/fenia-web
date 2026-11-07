<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Which environment this copy of the API is, and where its private folder is
 * (secrets, kit ZIPs, logs — never inside public_html).
 *
 * Hostinger keeps staging inside production's public_html
 * (`…/public_html/staging/api/_lib`), so the path tells them apart. The private
 * folder is `~/fenia-private/<env>`. FENIA_ENV / FENIA_PRIVATE_DIR override
 * both (tests, local runs).
 */
final class Env
{
    /**
     * @param array<string, string|false|null> $overrides usually ['FENIA_ENV' => getenv(...), ...]
     * @return array{name: string, privateDir: string}
     */
    public static function detect(string $libDir, array $overrides = []): array
    {
        $path = str_replace('\\', '/', $libDir);

        $name = (string) ($overrides['FENIA_ENV'] ?? '');
        if ($name === '') {
            $name = str_contains($path, '/public_html/staging/') ? 'staging' : 'production';
        }
        if (!in_array($name, ['production', 'staging', 'test'], true)) {
            throw new \RuntimeException('Unknown environment');
        }

        $private = (string) ($overrides['FENIA_PRIVATE_DIR'] ?? '');
        if ($private === '') {
            if (!preg_match('#^(/home/[^/]+)/#', $path, $m)) {
                throw new \RuntimeException('Cannot locate the account home directory');
            }
            $private = $m[1] . '/fenia-private/' . $name;
        }
        $private = rtrim(str_replace('\\', '/', $private), '/');

        // Secrets must never end up where the web server can serve them.
        if (str_contains($private . '/', '/public_html/')) {
            throw new \RuntimeException('Private directory must be outside public_html');
        }

        return ['name' => $name, 'privateDir' => $private];
    }
}
