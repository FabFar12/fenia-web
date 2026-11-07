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
        $kit = Catalog::fromJson(self::JSON)->purchasable('kit-live');
        self::assertNotNull($kit);
        self::assertSame(800000, $kit['price_cents']);
        self::assertSame('ARS', $kit['currency']);
    }

    public function testS19OnlyLivePricedKitsArePurchasable(): void
    {
        $catalog = Catalog::fromJson(self::JSON);
        foreach (['kit-soon', 'kit-free', 'kit-zero', 'kit-missing', '../kit-live', 'KIT-LIVE', ''] as $id) {
            self::assertNull($catalog->purchasable($id), $id);
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

    public function testMissingFileFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        Catalog::fromFile(__DIR__ . '/does-not-exist.json');
    }
}
