<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Tokens;
use PHPUnit\Framework\TestCase;

/** Test plan U-20, U-21 (threat model A3, A4, A14, C17). */
final class TokensTest extends TestCase
{
    public function testU20TokensAre256BitBase64UrlAndOnlyTheHashIsKept(): void
    {
        $generated = Tokens::generate();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $generated['token']);
        self::assertTrue(Tokens::isWellFormed($generated['token']));
        self::assertSame(hash('sha256', $generated['token']), $generated['hash']);
        self::assertStringNotContainsString($generated['token'], $generated['hash']);
    }

    public function testU20TokensDoNotRepeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $seen[Tokens::generate()['token']] = true;
        }
        self::assertCount(1000, $seen);
    }

    public function testU20RejectsMalformedTokensBeforeTheDatabase(): void
    {
        foreach (['', 'abc', str_repeat('a', 42), str_repeat('a', 44), str_repeat('a', 42) . '/', "' OR 1=1 --", '../../config'] as $bad) {
            self::assertFalse(Tokens::isWellFormed($bad), var_export($bad, true));
        }
    }

    public function testOrderReferenceIs128RandomBits(): void
    {
        $reference = Tokens::orderReference();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $reference);
        self::assertNotSame($reference, Tokens::orderReference());
    }

    public function testU21ExpiryIsComputedInUtc(): void
    {
        $now = new \DateTimeImmutable('2026-11-06 21:30:00', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        self::assertSame('2026-12-07 00:30:00', Tokens::expiresAt($now, 30));
    }

    private static function row(array $overrides = []): array
    {
        return array_merge([
            'expires_at' => '2026-12-06 12:00:00',
            'download_count' => 0,
            'max_downloads' => 5,
            'revoked_at' => null,
        ], $overrides);
    }

    public function testU21UsableTokenHasNoReason(): void
    {
        $now = new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'));
        self::assertNull(Tokens::unusableReason(self::row(), $now));
        self::assertNull(Tokens::unusableReason(self::row(['download_count' => 4]), $now));
    }

    public function testU21ExpiredTokenIsRejected(): void
    {
        $atExpiry = new \DateTimeImmutable('2026-12-06 12:00:00', new \DateTimeZone('UTC'));
        self::assertSame('expired', Tokens::unusableReason(self::row(), $atExpiry));
        self::assertSame('expired', Tokens::unusableReason(self::row(['expires_at' => 'not a date']), $atExpiry));
    }

    public function testU21SixthDownloadIsRejected(): void
    {
        $now = new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'));
        self::assertSame('exhausted', Tokens::unusableReason(self::row(['download_count' => 5]), $now));
        self::assertSame('exhausted', Tokens::unusableReason(self::row(['download_count' => '5', 'max_downloads' => '5']), $now));
    }

    public function testRevokedTokenIsRejectedFirst(): void
    {
        $now = new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC'));
        self::assertSame('revoked', Tokens::unusableReason(self::row(['revoked_at' => '2026-11-06 11:00:00']), $now));
    }
}
