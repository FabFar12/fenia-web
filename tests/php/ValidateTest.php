<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Money;
use Fenia\Api\Validate;
use PHPUnit\Framework\TestCase;

/** Test plan U-32 and input validation (threat model D1, D2, C17). */
final class ValidateTest extends TestCase
{
    public function testU32AcceptsAndNormalizesValidEmails(): void
    {
        self::assertSame('ana@example.com', Validate::email('ana@example.com'));
        self::assertSame('ana.perez+kits@example.com.ar', Validate::email('  Ana.Perez+Kits@Example.com.AR '));
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidEmails(): iterable
    {
        yield 'header injection CRLF' => ["ana@example.com\r\nBcc: x@evil.com"];
        yield 'header injection LF' => ["ana@example.com\nBcc: x@evil.com"];
        yield 'trailing CRLF' => ["ana@example.com\r\n"];
        yield 'NUL byte' => ["ana@example.com\0"];
        yield 'empty' => [''];
        yield 'spaces only' => ['   '];
        yield 'no at' => ['ana.example.com'];
        yield 'two addresses' => ['ana@example.com, b@example.com'];
        yield 'inner space' => ['ana @example.com'];
        yield 'sql' => ["' OR 1=1 --@x.com"];
        yield 'html' => ['<script>@x.com'];
        yield 'too long' => [str_repeat('a', 250) . '@x.com'];
    }

    /** @dataProvider invalidEmails */
    public function testU32RejectsInvalidEmails(string $email): void
    {
        self::assertNull(Validate::email($email));
    }

    public function testKitIds(): void
    {
        self::assertTrue(Validate::kitId('kit-ensenar-con-ia'));
        self::assertTrue(Validate::kitId('guia-evaluar-tiempos-ia'));
        foreach (['', 'Kit', '../config', 'kit--doble', '-kit', 'kit-', 'kit.zip', "kit\0", "kit\n", str_repeat('a', 65)] as $bad) {
            self::assertFalse(Validate::kitId($bad), var_export($bad, true));
        }
    }

    public function testMpIdsAreAlphanumericOnly(): void
    {
        self::assertTrue(Validate::mpId('ORD01JQ4S4KY8HWQ6NA5PXB65B3D3'));
        self::assertTrue(Validate::mpId('1234567890'));
        foreach (['', 'ORD/../x', '12/../34', '1 OR 1', '-1', 'ORD01?x=1', "ORD\n", str_repeat('A', 65)] as $bad) {
            self::assertFalse(Validate::mpId($bad), var_export($bad, true));
        }
    }

    public function testOrderReferences(): void
    {
        self::assertTrue(Validate::orderReference('0123456789abcdef0123456789abcdef'));
        foreach (['', '0123456789ABCDEF0123456789ABCDEF', '0123456789abcdef', 'g123456789abcdef0123456789abcdef', "0123456789abcdef0123456789abcdef\n"] as $bad) {
            self::assertFalse(Validate::orderReference($bad), var_export($bad, true));
        }
    }

    public function testMoneyToCents(): void
    {
        self::assertSame(800000, Money::toCents(8000));
        self::assertSame(800000, Money::toCents(8000.0));
        self::assertSame(800000, Money::toCents('8000.00'));
        self::assertSame(799999, Money::toCents(7999.99));
        self::assertSame(1, Money::toCents(0.01));
        self::assertNull(Money::toCents(-1));
        self::assertNull(Money::toCents('abc'));
        self::assertNull(Money::toCents(null));
        self::assertNull(Money::toCents(INF));
        self::assertNull(Money::toCents(NAN));
        self::assertNull(Money::toCents([8000]));
    }
}
