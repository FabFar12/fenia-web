<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Sales catalogue emitted by the Astro build (`src/pages/api/catalog.json.ts`)
 * from the product .md files: the single source of truth for price
 * (threat model A8, C12). The browser never sends a price.
 */
final class Catalog
{
    /** @var array<string, array{id: string, title: string, status: string, price_cents: ?int, currency: string}> */
    private array $products;

    /** @param array<string, array{id: string, title: string, status: string, price_cents: ?int, currency: string}> $products */
    private function __construct(array $products)
    {
        $this->products = $products;
    }

    public static function fromFile(string $path): self
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new \RuntimeException('Catalog not readable');
        }
        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
            throw new \RuntimeException('Catalog malformed');
        }
        $products = [];
        foreach ($data['products'] as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['id'], $entry['title'], $entry['status'], $entry['currency'])
                || !is_string($entry['id'])
                || !Validate::kitId($entry['id'])
            ) {
                throw new \RuntimeException('Catalog entry malformed');
            }
            $price = $entry['price'] ?? null;
            $products[$entry['id']] = [
                'id' => $entry['id'],
                'title' => (string) $entry['title'],
                'status' => (string) $entry['status'],
                'price_cents' => $price === null ? null : Money::toCents($price),
                'currency' => (string) $entry['currency'],
            ];
        }
        return new self($products);
    }

    /**
     * A kit can be bought only if it exists, is `live` and has a positive price
     * (threat model C18). Anything else is treated as non-existent.
     *
     * @return array{id: string, title: string, status: string, price_cents: int, currency: string}|null
     */
    public function purchasable(string $id): ?array
    {
        if (!Validate::kitId($id)) {
            return null;
        }
        $product = $this->products[$id] ?? null;
        if (
            $product === null
            || $product['status'] !== 'live'
            || $product['price_cents'] === null
            || $product['price_cents'] <= 0
        ) {
            return null;
        }
        return $product;
    }
}
