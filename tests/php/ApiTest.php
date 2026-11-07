<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Api;
use Fenia\Api\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CSRF guard of the POST endpoints (threat model D4, test S-23). */
final class ApiTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    /** @return iterable<string, array{0: ?string, 1: ?string, 2: bool}> */
    public static function origins(): iterable
    {
        yield 'own site' => ['https://staging.fenia.com.ar', 'same-origin', true];
        yield 'other site' => ['https://evil.example', 'cross-site', false];
        yield 'production calling staging' => ['https://fenia.com.ar', 'same-site', false];
        yield 'look-alike' => ['https://staging.fenia.com.ar.evil.example', 'cross-site', false];
        yield 'Firefox on /descarga (no-referrer)' => ['null', 'same-origin', true];
        yield 'sandboxed iframe elsewhere' => ['null', 'cross-site', false];
        yield 'null from a sibling subdomain' => ['null', 'same-site', false];
        yield 'curl / old browser' => [null, null, true];
        yield 'no Origin, cross-site' => [null, 'cross-site', false];
    }

    #[DataProvider('origins')]
    public function testOriginCheck(?string $origin, ?string $fetchSite, bool $allowed): void
    {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
        if ($origin !== null) {
            $_SERVER['HTTP_ORIGIN'] = $origin;
        }
        if ($fetchSite !== null) {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $fetchSite;
        }
        $config = Config::fromArray(EnvConfigInboxTest::validConfig(['db_user' => 'u']), 'staging');
        self::assertSame($allowed, Api::originOk($config));
    }
}
