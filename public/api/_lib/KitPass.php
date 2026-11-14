<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * View pass for "Tu kit" (D25, docs/ventas/propuesta-visual.md §6).
 *
 * The download link travels after `#`, so the server never sees it in a URL.
 * When /descarga opens, the page sends the link token once (POST) and gets
 * this pass as an HttpOnly cookie for that device. The kit's pages (guide,
 * fichas, printables) are then plain links that carry only the cookie: the
 * token never lands in a URL, a server log, the history or a Referer.
 *
 * Value: "<token id>.<expiry unix>.<HMAC>", signed with a key derived from
 * hash_salt (separate from the download-token key). Stateless: every request
 * re-reads the token row, so a refund or an expired link closes the pass at
 * once (threat model A13).
 */
final class KitPass
{
    public static function cookieName(string $kitId): string
    {
        return 'fk_' . $kitId;
    }

    public static function issue(string $salt, string $kitId, int $tokenId, int $expires): string
    {
        return $tokenId . '.' . $expires . '.' . self::sign($salt, $kitId, $tokenId, $expires);
    }

    /** @return int|null the token id the pass was issued for, or null if forged, for another kit, or expired */
    public static function verify(string $salt, string $kitId, string $value, int $now): ?int
    {
        if (!preg_match('/^([1-9][0-9]{0,9})\.([1-9][0-9]{0,10})\.([A-Za-z0-9_-]{43})$/D', $value, $m)) {
            return null;
        }
        $tokenId = (int) $m[1];
        $expires = (int) $m[2];
        if (!hash_equals(self::sign($salt, $kitId, $tokenId, $expires), $m[3]) || $expires <= $now) {
            return null;
        }
        return $tokenId;
    }

    private static function sign(string $salt, string $kitId, int $tokenId, int $expires): string
    {
        $key = hash_hmac('sha256', 'kit-pass', $salt, true);
        $mac = hash_hmac('sha256', 'pass|' . $kitId . '|' . $tokenId . '|' . $expires, $key, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
