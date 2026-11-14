<?php

declare(strict_types=1);

namespace Fenia\Api\Tests;

use Fenia\Api\Config;
use Fenia\Api\Env;
use Fenia\Api\Inbox;
use PHPUnit\Framework\TestCase;

/** Environment detection, fail-closed config and the inbox (Q5, threat model A7, C15). */
final class EnvConfigInboxTest extends TestCase
{
    private const LIB_PROD = '/home/u123/domains/fenia.com.ar/public_html/api/_lib';
    private const LIB_STAGING = '/home/u123/domains/fenia.com.ar/public_html/staging/api/_lib';

    public function testDetectsEnvironmentAndPrivateDirFromThePath(): void
    {
        self::assertSame(['name' => 'production', 'privateDir' => '/home/u123/fenia-private/production'], Env::detect(self::LIB_PROD));
        self::assertSame(['name' => 'staging', 'privateDir' => '/home/u123/fenia-private/staging'], Env::detect(self::LIB_STAGING));
    }

    public function testOverridesForTestsAndLocalRuns(): void
    {
        $env = Env::detect('C:\\anything', ['FENIA_ENV' => 'test', 'FENIA_PRIVATE_DIR' => 'C:\\tmp\\private\\']);
        self::assertSame(['name' => 'test', 'privateDir' => 'C:/tmp/private'], $env);
    }

    public function testRefusesPrivateDirInsidePublicHtml(): void
    {
        $this->expectException(\RuntimeException::class);
        Env::detect(self::LIB_PROD, ['FENIA_PRIVATE_DIR' => '/home/u123/domains/fenia.com.ar/public_html/secrets']);
    }

    public function testRefusesUnknownEnvironmentOrHome(): void
    {
        foreach ([[self::LIB_PROD, ['FENIA_ENV' => 'dev']], ['/var/www/api/_lib', []]] as [$dir, $overrides]) {
            try {
                Env::detect($dir, $overrides);
                self::fail('accepted ' . $dir);
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    public static function validConfig(array $overrides = []): array
    {
        return array_merge([
            'env' => 'staging',
            'site_url' => 'https://staging.fenia.com.ar',
            'sales_enabled' => true,
            'mp_access_token' => 'APP_USR-test',
            'mp_webhook_secret' => 'secret',
            'mp_collector_id' => '5550001111',
            'db_dsn' => 'sqlite::memory:',
            'db_user' => '',
            'db_password' => '',
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => 465,
            'smtp_user' => 'kits@fenia.com.ar',
            'smtp_password' => 'x',
            'mail_from' => 'kits@fenia.com.ar',
            'mail_reply_to' => 'soporte@fenia.com.ar',
            'hash_salt' => str_repeat('s', 32),
            'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64)]],
        ], $overrides);
    }

    public function testValidConfigWithDefaults(): void
    {
        $config = Config::fromArray(self::validConfig(['db_user' => 'u']), 'staging');
        self::assertTrue($config->salesEnabled());
        self::assertSame(30, $config->get('download_days'));
        self::assertSame(10, $config->get('max_downloads'), 'D25');
        self::assertSame(['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64)], $config->kit('kit-a'));
        self::assertNull($config->kit('kit-b'));
        self::assertNull($config->resource('kit-a', 'fichas'), 'optional');
        self::assertFalse(Config::fromArray(self::validConfig(['db_user' => 'u', 'sales_enabled' => false]), 'staging')->salesEnabled());
    }

    public function testTuKitResourcesAreReadWithTheirSize(): void
    {
        $sha = str_repeat('b', 64);
        $config = Config::fromArray(self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => [
            'file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64),
            'guide' => ['file' => 'kit-a-guia.pdf', 'sha256' => $sha, 'pages' => 37],
            'fichas' => ['file' => 'kit-a-fichas.html', 'sha256' => $sha, 'count' => 8],
            'imprimibles' => ['file' => 'kit-a-imprimibles.html', 'sha256' => $sha],
        ]]]), 'staging');
        self::assertSame(['file' => 'kit-a-guia.pdf', 'sha256' => $sha], $config->guide('kit-a'));
        self::assertSame(['file' => 'kit-a-guia.pdf', 'sha256' => $sha, 'size' => 37], $config->resource('kit-a', 'guide'));
        self::assertSame(['file' => 'kit-a-fichas.html', 'sha256' => $sha, 'size' => 8], $config->resource('kit-a', 'fichas'));
        self::assertSame(['file' => 'kit-a-imprimibles.html', 'sha256' => $sha, 'size' => null], $config->resource('kit-a', 'imprimibles'));
        self::assertNull($config->resource('kit-a', 'file'), 'only the known resources');
        self::assertNull($config->resource('kit-b', 'fichas'));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function brokenConfigs(): iterable
    {
        $missing = self::validConfig(['db_user' => 'u']);
        unset($missing['mp_access_token']);
        yield 'missing secret' => [$missing];
        yield 'wrong type' => [self::validConfig(['db_user' => 'u', 'smtp_port' => '465'])];
        yield 'sales flag as string' => [self::validConfig(['db_user' => 'u', 'sales_enabled' => 'yes'])];
        yield 'empty token' => [self::validConfig(['db_user' => 'u', 'mp_access_token' => ' '])];
        yield 'short salt' => [self::validConfig(['db_user' => 'u', 'hash_salt' => 'short'])];
        yield 'config for production' => [self::validConfig(['db_user' => 'u', 'env' => 'production'])];
        yield 'kit path traversal' => [self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => ['file' => '../config.php', 'sha256' => str_repeat('a', 64)]]])];
        yield 'kit bad checksum' => [self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => 'abc']]])];
        yield 'kit bad id' => [self::validConfig(['db_user' => 'u', 'kits' => ['../x' => ['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64)]]])];
        yield 'guide path traversal' => [self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64), 'guide' => ['file' => '../config.php', 'sha256' => str_repeat('a', 64)]]]])];
        yield 'guide not a pdf' => [self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64), 'guide' => ['file' => 'kit-a.php', 'sha256' => str_repeat('a', 64)]]]])];
        yield 'guide without checksum' => [self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64), 'guide' => ['file' => 'kit-a.pdf']]]])];
        $kit = static fn (array $extra): array => self::validConfig(['db_user' => 'u', 'kits' => ['kit-a' => ['file' => 'kit-a.zip', 'sha256' => str_repeat('a', 64)] + $extra]]);
        $sha = str_repeat('a', 64);
        yield 'guide pages as text' => [$kit(['guide' => ['file' => 'kit-a.pdf', 'sha256' => $sha, 'pages' => '37']])];
        yield 'fichas not html' => [$kit(['fichas' => ['file' => 'kit-a-fichas.php', 'sha256' => $sha]])];
        yield 'fichas path traversal' => [$kit(['fichas' => ['file' => '../kit-a.html', 'sha256' => $sha]])];
        yield 'fichas count zero' => [$kit(['fichas' => ['file' => 'kit-a-fichas.html', 'sha256' => $sha, 'count' => 0]])];
        yield 'imprimibles without checksum' => [$kit(['imprimibles' => ['file' => 'kit-a-imprimibles.html']])];
        yield 'imprimibles as a string' => [$kit(['imprimibles' => 'kit-a-imprimibles.html'])];
    }

    /** @dataProvider brokenConfigs */
    public function testBrokenConfigFailsClosed(array $values): void
    {
        $this->expectException(\RuntimeException::class);
        Config::fromArray($values, 'staging');
    }

    public function testMissingConfigFileFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::fromFile(sys_get_temp_dir() . '/fenia-no-such-config.php', 'staging');
    }

    public function testInboxMovesOnlyConfigAndKitZipsToThePrivateDir(): void
    {
        $base = sys_get_temp_dir() . '/fenia-inbox-' . bin2hex(random_bytes(4));
        $inbox = $base . '/inbox';
        $private = $base . '/private';
        mkdir($inbox, 0700, true);
        file_put_contents($inbox . '/config.php', '<?php return [];');
        file_put_contents($inbox . '/kit-ensenar-con-ia.zip', 'zip');
        file_put_contents($inbox . '/kit-ensenar-con-ia-guia.pdf', 'pdf');
        file_put_contents($inbox . '/kit-ensenar-con-ia-fichas.html', '<!doctype html>');
        file_put_contents($inbox . '/guia.PDF.php', '<?php echo 1;');
        file_put_contents($inbox . '/fichas.html.php', '<?php echo 1;');
        file_put_contents($inbox . '/evil.php', '<?php echo 1;');
        file_put_contents($inbox . '/../x.zip', 'outside');

        $moved = Inbox::promote($inbox, $private);
        sort($moved);

        self::assertSame(['config.php', 'kit-ensenar-con-ia-fichas.html', 'kit-ensenar-con-ia-guia.pdf', 'kit-ensenar-con-ia.zip'], $moved);
        self::assertFileExists($private . '/kits/kit-ensenar-con-ia-guia.pdf');
        self::assertFileExists($private . '/kits/kit-ensenar-con-ia-fichas.html');
        self::assertFileExists($inbox . '/guia.PDF.php', 'only real .pdf names');
        self::assertFileExists($inbox . '/fichas.html.php', 'only real .html names');
        self::assertFileExists($private . '/config.php');
        self::assertFileExists($private . '/kits/kit-ensenar-con-ia.zip');
        self::assertFileDoesNotExist($inbox . '/config.php');
        self::assertFileExists($inbox . '/evil.php', 'unknown files are left alone');
        self::assertFileDoesNotExist($private . '/evil.php');
        self::assertSame([], Inbox::promote($base . '/no-inbox', $private));
    }
}
