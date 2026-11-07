<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Catalog;
use PHPUnit\Framework\TestCase;

/**
 * Catalogue parsing and purchasability (threat model A8, C12, C18; test S-19
 * logic). The JSON shape mirrors src/pages/api/catalog.json.ts; CI also checks
 * the real build output against the .md files (U-31).
 */
final class CatalogTest extends TestCase
{
    private const JSON = <<<'JSON'
    {
      "products": [
        { "id": "kit-live", "title": "Kit live", "status": "live", "price": 8000, "currency": "ARS" },
        { "id": "kit-soon", "title": "Kit soon", "status": "coming-soon", "price": 8000, "currency": "ARS" },
        { "id": "kit-free", "title": "Kit sin precio", "status": "live", "price": null, "currency": "ARS" },
        { "id": "kit-zero", "title": "Kit en cero", "status": "live", "price": 0, "currency": "ARS" }
      ]
    }
    JSON;

    public function testLiveKitWithPriceIsPurchasable(): void
    {
        $kit = Catalog::fromJson(self::JSON)->purchasable('kit-live', self::at('2026-11-07 12:00:00'));
        self::assertNotNull($kit);
        self::assertSame(800000, $kit['price_cents']);
        self::assertSame('ARS', $kit['currency']);
    }

    public function testS19OnlyLivePricedKitsArePurchasable(): void
    {
        $catalog = Catalog::fromJson(self::JSON);
        foreach (['kit-soon', 'kit-free', 'kit-zero', 'kit-missing', '../kit-live', 'KIT-LIVE', ''] as $id) {
            self::assertNull($catalog->purchasable($id, self::at('2026-11-07 12:00:00')), $id);
        }
    }

    public function testMalformedCatalogFailsClosed(): void
    {
        foreach (['', '{', '[]', '{"products": "x"}', '{"products": [{"id": "../x", "title": "t", "status": "live", "currency": "ARS"}]}', '{"products": [{"title": "t"}]}'] as $json) {
            try {
                Catalog::fromJson($json);
                self::fail('Accepted malformed catalog: ' . $json);
            } catch (\Throwable $e) {
                self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            }
        }
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private static function withPromo(array $promo, int $price = 30000): Catalog
    {
        return Catalog::fromJson((string) json_encode(['products' => [
            ['id' => 'kit-promo', 'title' => 'Kit', 'status' => 'live', 'price' => $price, 'currency' => 'ARS', 'promo' => $promo],
        ]]));
    }

    public function testPromoPriceAppliesOnlyInsideItsWindow(): void
    {
        // As emitted by the build: ISO in UTC. 2026-11-07 00:00 ART → 2026-11-14 23:59:59 ART.
        $catalog = self::withPromo(['price' => 13000, 'label' => 'Precio de lanzamiento', 'starts' => '2026-11-07T03:00:00.000Z', 'ends' => '2026-11-15T02:59:59.000Z']);
        $cases = [
            '2026-11-07 02:59:59' => [3000000, false], // a second before it starts
            '2026-11-07 03:00:00' => [1300000, true],  // starts exactly
            '2026-11-15 02:59:58' => [1300000, true],  // last second
            '2026-11-15 02:59:59' => [3000000, false], // ended: regular price again, on its own
            '2026-12-01 00:00:00' => [3000000, false],
        ];
        foreach ($cases as $when => [$cents, $promo]) {
            $kit = $catalog->purchasable('kit-promo', self::at($when));
            self::assertSame($cents, $kit['price_cents'], $when);
            self::assertSame($promo, $kit['promo'], $when);
            self::assertSame(3000000, $kit['regular_cents']);
        }
    }

    public function testInconsistentPromoFailsClosed(): void
    {
        $ok = ['price' => 13000, 'starts' => '2026-11-07T03:00:00.000Z', 'ends' => '2026-11-15T02:59:59.000Z'];
        $bad = [
            'promo not cheaper' => [['price' => 30000] + $ok],
            'promo dearer' => [['price' => 40000] + $ok],
            'zero' => [['price' => 0] + $ok],
            'ends before starts' => [['ends' => '2026-11-06T00:00:00.000Z'] + $ok],
            'bad date' => [['starts' => 'pronto'] + $ok],
            'missing ends' => [array_diff_key($ok, ['ends' => 1])],
        ];
        foreach ($bad as $name => [$promo]) {
            try {
                self::withPromo($promo);
                self::fail('Accepted: ' . $name);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('promo', $e->getMessage(), $name);
            }
        }
    }

    public function testMissingFileFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        Catalog::fromFile(__DIR__ . '/does-not-exist.json');
    }
}
