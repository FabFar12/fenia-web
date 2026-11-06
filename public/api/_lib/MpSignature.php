<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Verifies the `x-signature` header Mercado Pago attaches to webhook
 * notifications (threat model A1).
 *
 * MP signs the manifest `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`
 * with HMAC-SHA256 using the application's secret key. Parts whose value is
 * missing from the notification are left out of the manifest, and an
 * alphanumeric data.id is lowercased first.
 *
 * A valid signature only proves the notification came from MP. The payment
 * itself is always re-read from MP's API before acting (see PaymentRules).
 */
final class MpSignature
{
    /**
     * Parses `ts=1742505638683,v1=<64 hex chars>`.
     *
     * @return array{ts: string, v1: string}|null null when malformed
     */
    public static function parseHeader(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
        }
        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (!preg_match('/^\d{1,20}$/', $ts) || !preg_match('/^[0-9a-fA-F]{64}$/', $v1)) {
            return null;
        }
        return ['ts' => $ts, 'v1' => strtolower($v1)];
    }

    public static function manifest(?string $dataId, ?string $requestId, string $ts): string
    {
        $manifest = '';
        if ($dataId !== null && $dataId !== '') {
            $manifest .= 'id:' . strtolower($dataId) . ';';
        }
        if ($requestId !== null && $requestId !== '') {
            $manifest .= 'request-id:' . $requestId . ';';
        }
        return $manifest . 'ts:' . $ts . ';';
    }

    public static function isValid(?string $xSignature, ?string $xRequestId, ?string $dataId, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }
        $signature = self::parseHeader($xSignature);
        if ($signature === null) {
            return false;
        }
        $expected = hash_hmac('sha256', self::manifest($dataId, $xRequestId, $signature['ts']), $secret);
        return hash_equals($expected, $signature['v1']);
    }

    /**
     * MP sends `?data.id=123&type=payment`, but PHP rewrites dots in $_GET keys
     * to underscores, so `data.id` arrives as `data_id` (threat model C9).
     * Reads both forms, then falls back to the raw query string.
     *
     * @param array<string, mixed> $get
     */
    public static function dataIdFromQuery(array $get, string $rawQuery = ''): ?string
    {
        foreach (['data_id', 'data.id'] as $key) {
            if (isset($get[$key]) && is_string($get[$key]) && $get[$key] !== '') {
                return $get[$key];
            }
        }
        foreach (explode('&', $rawQuery) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (rawurldecode($key) === 'data.id' && $value !== '') {
                return rawurldecode($value);
            }
        }
        return null;
    }
}
