<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Minimal Mercado Pago Orders API client (Checkout Pro, D14). Direct REST, no
 * SDK (ADR-028). Request shape taken from the official SDK example
 * `examples/Order/CreateCheckoutPRO.php`: `processing_mode: manual` is what
 * returns a `checkout_url`.
 *
 * The HTTP transport is injectable so tests never touch the network.
 */
final class MpClient
{
    private const BASE = 'https://api.mercadopago.com';

    private string $token;
    /** @var callable(string, string, array<string, string>, ?string): array{status: int, body: string} */
    private $transport;

    public function __construct(string $accessToken, ?callable $transport = null)
    {
        $this->token = $accessToken;
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    public function createOrder(array $order, string $idempotencyKey): array
    {
        return $this->call('POST', '/v1/orders', $order, ['X-Idempotency-Key' => $idempotencyKey]);
    }

    /** @return array<string, mixed> */
    public function getOrder(string $id): array
    {
        if (!Validate::mpId($id)) {
            throw new MpException('Invalid order id', 400);
        }
        return $this->call('GET', '/v1/orders/' . rawurlencode($id));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'] + $extraHeaders;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $response = ($this->transport)($method, self::BASE . $path, $headers, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] > 299 || !is_array($decoded)) {
            throw new MpException('Mercado Pago answered HTTP ' . $response['status'], $response['status']);
        }
        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($result === false) {
            throw new MpException('Network error talking to Mercado Pago', 0);
        }
        return ['status' => $status, 'body' => (string) $result];
    }
}
