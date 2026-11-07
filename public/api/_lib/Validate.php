<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Input validation shared by every endpoint. Anything that reaches a query,
 * a URL or a mail header goes through here first.
 */
final class Validate
{
    /**
     * Normalized (lowercased) email, or null. Rejects CR/LF/NUL anywhere in the
     * raw input to rule out mail header injection (threat model D2).
     */
    public static function email(string $raw): ?string
    {
        if (preg_match('/[\r\n\0]/', $raw)) {
            return null;
        }
        $email = trim($raw);
        if ($email === '' || strlen($email) > 254 || preg_match('/\s/', $email)) {
            return null;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return strtolower($email);
    }

    /** Product ids are the .md file slugs, e.g. `kit-ensenar-con-ia`. */
    public static function kitId(string $id): bool
    {
        return strlen($id) <= 64 && (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id);
    }

    /** MP payment ids are numeric; anything else never reaches MP's API URL. */
    public static function paymentId(string $id): bool
    {
        return (bool) preg_match('/^\d{1,20}$/', $id);
    }

    /** Our order code (`external_reference`): 128 random bits as 32 hex chars. */
    public static function orderReference(string $reference): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $reference);
    }
}
