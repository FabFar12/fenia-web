<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\MpSignature;
use PHPUnit\Framework\TestCase;

/**
 * Test plan U-01…U-05 (threat model A1, C9).
 * Expected HMACs were computed with Node's crypto, an implementation
 * independent from the code under test.
 */
final class MpSignatureTest extends TestCase
{
    private const SECRET = 'fenia-test-secret';
    private const REQUEST_ID = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
    private const TS = '1742505638683';

    public function testU01AcceptsValidSignature(): void
    {
        $header = 'ts=' . self::TS . ',v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dff';
        self::assertTrue(MpSignature::isValid($header, self::REQUEST_ID, '123456789', self::SECRET));
    }

    public function testU01AcceptsHeaderWithSpacesAndUppercaseHex(): void
    {
        $header = ' ts=' . self::TS . ' , v1=DCC01DE923B48B2212E5025E4D299CE4AF815503D93AF5DB3BDACED0BC137DFF ';
        self::assertTrue(MpSignature::isValid($header, self::REQUEST_ID, '123456789', self::SECRET));
    }

    public function testU02RejectsTamperedSignature(): void
    {
        $header = 'ts=' . self::TS . ',v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dfe';
        self::assertFalse(MpSignature::isValid($header, self::REQUEST_ID, '123456789', self::SECRET));
    }

    public function testU02RejectsWrongSecret(): void
    {
        $header = 'ts=' . self::TS . ',v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dff';
        self::assertFalse(MpSignature::isValid($header, self::REQUEST_ID, '123456789', 'another-secret'));
        self::assertFalse(MpSignature::isValid($header, self::REQUEST_ID, '123456789', ''));
    }

    public function testU02RejectsSignatureReusedForAnotherPayment(): void
    {
        // A captured valid signature cannot vouch for a different payment id.
        $header = 'ts=' . self::TS . ',v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dff';
        self::assertFalse(MpSignature::isValid($header, self::REQUEST_ID, '987654321', self::SECRET));
    }

    public function testU03LowercasesAlphanumericDataId(): void
    {
        $header = 'ts=' . self::TS . ',v1=12fc11ecffc88a924c8b9642891c0b5a43c51d29a3633f64dd03b1f261167789';
        self::assertTrue(MpSignature::isValid($header, self::REQUEST_ID, 'ABC123XYZ', self::SECRET));
    }

    public function testU03ReadsDataIdInEveryForm(): void
    {
        self::assertSame('123', MpSignature::dataIdFromQuery(['data_id' => '123']));
        self::assertSame('456', MpSignature::dataIdFromQuery(['data.id' => '456']));
        self::assertSame('789', MpSignature::dataIdFromQuery([], 'type=payment&data.id=789'));
        self::assertSame('789', MpSignature::dataIdFromQuery([], 'type=payment&data%2Eid=789'));
        self::assertNull(MpSignature::dataIdFromQuery(['data_id' => ['array']], 'type=payment'));
        self::assertNull(MpSignature::dataIdFromQuery([], ''));
    }

    public function testU04OmitsMissingRequestId(): void
    {
        self::assertSame('id:123456789;ts:1742505638683;', MpSignature::manifest('123456789', null, self::TS));
        $header = 'ts=' . self::TS . ',v1=fb6fc00670d90d14c930b298ae584a4786bc144884bbc842ad26c87b7cf423ea';
        self::assertTrue(MpSignature::isValid($header, null, '123456789', self::SECRET));
        self::assertTrue(MpSignature::isValid($header, '', '123456789', self::SECRET));
    }

    public function testU04OmitsMissingDataId(): void
    {
        $header = 'ts=' . self::TS . ',v1=9d7deac5b12ac6b8f67142f2a3b7254712bff897c9c7051239dbf752b215432e';
        self::assertTrue(MpSignature::isValid($header, self::REQUEST_ID, null, self::SECRET));
    }

    /** @return iterable<string, array{0: ?string}> */
    public static function malformedHeaders(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'no v1' => ['ts=1742505638683'];
        yield 'no ts' => ['v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dff'];
        yield 'short hash' => ['ts=1742505638683,v1=abc'];
        yield 'non-hex hash' => ['ts=1742505638683,v1=' . str_repeat('z', 64)];
        yield 'non-numeric ts' => ['ts=abc,v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dff'];
        yield 'newline inside the timestamp' => ["ts=1742\n505638683,v1=dcc01de923b48b2212e5025e4d299ce4af815503d93af5db3bdaced0bc137dff"];
        yield 'newline inside the hash' => ["ts=1742505638683,v1=dcc01de923b48b2212e5025e4d299ce4af8155\n03d93af5db3bdaced0bc137dff"];
        yield 'garbage' => ['<script>alert(1)</script>'];
    }

    /** @dataProvider malformedHeaders */
    public function testU05RejectsMalformedHeader(?string $header): void
    {
        self::assertNull(MpSignature::parseHeader($header));
        self::assertFalse(MpSignature::isValid($header, self::REQUEST_ID, '123456789', self::SECRET));
    }
}
