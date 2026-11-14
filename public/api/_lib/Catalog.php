<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Sales catalogue emitted by the Astro build (`src/pages/api/catalog.json.ts`)
 * from the product .md files: the single source of truth for price
 * (threat model A8, C12). The browser never sends a price.
 *
 * A kit can carry a time-limited `promo` (D23): between `starts` and `ends`
 * it is charged `promo.price` instead of `price`. The price is decided here,
 * with the server's clock, at the moment the order is created.
 */
final class Catalog
{
    /** @var array<string, array{id: string, title: string, status: string, price_cents: ?int, currency: string, promo: ?array{price_cents: int, starts: \DateTimeImmutable, ends: \DateTimeImmutable}}> */
    private array $products;

    /** @param array<string, array{id: string, title: string, status: string, price_cents: ?int, currency: string, promo: ?array{price_cents: int, starts: \DateTimeImmutable, ends: \DateTimeImmutable}}> $products */
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
            $priceCents = $price === null ? null : Money::toCents($price);
            $products[$entry['id']] = [
                'id' => $entry['id'],
                'title' => (string) $entry['title'],
                'status' => (string) $entry['status'],
                'price_cents' => $priceCents,
                'currency' => (string) $entry['currency'],
                'promo' => self::promo($entry['promo'] ?? null, $priceCents),
            ];
        }
        return new self($products);
    }

    /**
     * A malformed promo fails the whole catalogue (and so the API) closed:
     * better "not available" than charging a wrong price.
     *
     * @param mixed $promo
     * @return array{price_cents: int, starts: \DateTimeImmutable, ends: \DateTimeImmutable}|null
     */
    private static function promo($promo, ?int $regularCents): ?array
    {
        if ($promo === null) {
            return null;
        }
        if (!is_array($promo) || !isset($promo['price'], $promo['starts'], $promo['ends']) || !is_string($promo['starts']) || !is_string($promo['ends'])) {
            throw new \RuntimeException('Catalog promo malformed');
        }
        $cents = Money::toCents($promo['price']);
        try {
            $starts = new \DateTimeImmutable($promo['starts']);
            $ends = new \DateTimeImmutable($promo['ends']);
        } catch (\Exception $e) {
            throw new \RuntimeException('Catalog promo dates malformed');
        }
        if ($cents === null || $cents <= 0 || $regularCents === null || $cents >= $regularCents || $ends <= $starts) {
            throw new \RuntimeException('Catalog promo inconsistent');
        }
        return ['price_cents' => $cents, 'starts' => $starts, 'ends' => $ends];
    }

    /**
     * Every listed kit, in catalogue order (internal metrics).
     *
     * @return list<array{id: string, title: string, status: string, price_cents: ?int, currency: string}>
     */
    public function all(): array
    {
        return array_values($this->products);
    }

    /**
     * Any listed kit, sellable or not: for titles in emails and on the download
     * page of kits already bought.
     *
     * @return array{id: string, title: string, status: string, price_cents: ?int, currency: string}|null
     */
    public function find(string $id): ?array
    {
        return Validate::kitId($id) ? ($this->products[$id] ?? null) : null;
    }

    /**
     * A kit can be bought only if it exists, is `live` and has a positive price
     * (threat model C18). Anything else is treated as non-existent.
     * `price_cents` is the price to charge at `$at`: the promo price while the
     * promo runs, the regular price otherwise.
     *
     * @return array{id: string, title: string, status: string, price_cents: int, currency: string, regular_cents: int, promo: bool}|null
     */
    public function purchasable(string $id, \DateTimeImmutable $at): ?array
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
        $promo = $product['promo'];
        $inPromo = $promo !== null && $at >= $promo['starts'] && $at < $promo['ends'];
        return [
            'id' => $product['id'],
            'title' => $product['title'],
            'status' => $product['status'],
            'price_cents' => $inPromo ? $promo['price_cents'] : $product['price_cents'],
            'currency' => $product['currency'],
            'regular_cents' => $product['price_cents'],
            'promo' => $inPromo,
        ];
    }
}
