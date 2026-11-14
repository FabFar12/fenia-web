<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * HTTP plumbing shared by the endpoints in public/api/*.php: boot (env →
 * inbox → config → database → services), common headers, JSON in/out,
 * Origin check. Fails closed: if anything in the boot fails, the endpoint
 * answers 503 and sells nothing (ADR-028).
 *
 * PHP errors go to `<privateDir>/logs/`, never to the screen or to an
 * `error_log` file under public_html.
 */
final class Api
{
    private const MAX_JSON_BYTES = 8192;

    /**
     * Runs one endpoint. The handler returns `[status, body]` to answer JSON,
     * or null when it already wrote its own response (file download).
     *
     * @param list<string> $methods
     * @param callable(Shop, Config, array<string, mixed>): ?array{0: int, 1: array<string, mixed>} $handler gets the booted app (pdo, log…) as third argument
     */
    public static function run(array $methods, callable $handler): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');

        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            self::json(405, ['error' => 'method']);
            return;
        }

        try {
            $app = self::boot();
        } catch (\Throwable $e) {
            self::json(503, ['error' => 'unavailable']);
            return;
        }
        $log = $app['log'];

        try {
            $result = $handler($app['shop'], $app['config'], $app);
            if ($result !== null) {
                self::json($result[0], $result[1]);
            }
        } catch (\Throwable $e) {
            $log('error', 'unhandled', ['type' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            if (!headers_sent()) {
                self::json(500, ['error' => 'internal']);
            }
        }
    }

    /**
     * Also used by the hourly job (cron.php).
     *
     * @return array{shop: Shop, config: Config, log: callable(string, string, array<string, mixed>): void, pdo: \PDO, mailer: MailSender, privateDir: string}
     */
    public static function boot(): array
    {
        $env = Env::detect(__DIR__, [
            'FENIA_ENV' => getenv('FENIA_ENV'),
            'FENIA_PRIVATE_DIR' => getenv('FENIA_PRIVATE_DIR'),
        ]);
        $private = $env['privateDir'];
        $apiDir = dirname(__DIR__);

        Inbox::promote($apiDir . '/_inbox', $private);

        $logDir = $private . '/logs';
        if (!is_dir($logDir) && !mkdir($logDir, 0700, true) && !is_dir($logDir)) {
            throw new \RuntimeException('Cannot create the log directory');
        }
        ini_set('error_log', $logDir . '/php-errors.log');
        ini_set('log_errors', '1');
        $log = self::fileLogger($logDir);

        $config = Config::fromFile($private . '/config.php', $env['name']);
        $pdo = Db::connect((string) $config->get('db_dsn'), (string) $config->get('db_user'), (string) $config->get('db_password'));
        $marker = $private . '/.schema-' . Db::SCHEMA_VERSION;
        if (!is_file($marker)) {
            Db::migrate($pdo);
            @touch($marker);
        }

        $mailer = new SmtpMailer($config);
        $shop = new Shop(
            $config,
            Catalog::fromFile($apiDir . '/catalog.json'),
            $pdo,
            new MpClient((string) $config->get('mp_access_token')),
            $mailer,
            $private . '/kits',
            $log
        );
        return ['shop' => $shop, 'config' => $config, 'log' => $log, 'pdo' => $pdo, 'mailer' => $mailer, 'privateDir' => $private];
    }

    /** JSON lines, one file per month. Context never carries tokens, emails or secrets. */
    private static function fileLogger(string $dir): callable
    {
        return static function (string $level, string $event, array $context) use ($dir): void {
            $line = json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'event' => $event] + $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $file = $dir . '/api-' . gmdate('Y-m') . '.log';
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            @chmod($file, 0600);
        };
    }

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed>|null the request's JSON object, or null if it is not one */
    public static function jsonInput(): ?array
    {
        if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            return null;
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_JSON_BYTES) {
            return null;
        }
        $data = json_decode($raw, true, 4);
        return is_array($data) ? $data : null;
    }

    /**
     * CSRF guard for the POST endpoints (threat model D4): a browser always
     * sends Origin on a cross-site POST, so it must be our own site.
     *
     * Firefox and Safari send `Origin: null` on same-origin POSTs from a page
     * with `Referrer-Policy: no-referrer` (/descarga), as the Fetch standard
     * says. Then `Sec-Fetch-Site`, which pages cannot forge, decides. Requests
     * with neither header (curl, very old browsers) still face the rate limits.
     */
    public static function originOk(Config $config): bool
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || $origin === 'null') {
            $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
            return in_array($site, ['', 'same-origin', 'none'], true);
        }
        return hash_equals(rtrim((string) $config->get('site_url'), '/'), $origin);
    }

    /**
     * Client address for rate limits and the download log (stored only as a
     * keyed hash). Staging has no CDN; behind production's CDN this must be
     * checked before launch (H-06).
     */
    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($agent) ? $agent : null;
    }

    /**
     * "Tu kit" view pass (KitPass): HttpOnly, only over HTTPS, only for /api/,
     * never sent from another site.
     *
     * @param array{name: string, value: string, max_age: int} $pass
     */
    public static function setPassCookie(array $pass): void
    {
        setcookie($pass['name'], $pass['value'], [
            'expires' => time() + $pass['max_age'],
            'path' => '/api/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function passCookie(string $kitId): ?string
    {
        $value = $_COOKIE[KitPass::cookieName($kitId)] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Streams one of the kit's pages for "Tu kit" (shown in the browser, not
     * saved), or a page that says what to do.
     *
     * @param array{status: int, path?: string, filename?: string, type?: string} $file
     */
    public static function sendKitPage(array $file, Config $config): void
    {
        if ($file['status'] !== 200 || !isset($file['path'], $file['filename'], $file['type'])) {
            [$title, $message] = [
                403 => ['Abrí tu kit desde tu link', 'Para ver este material, entrá con el link de tu compra: el del mail, o el de la página de pago.'],
                404 => ['No encontramos este material', 'Volvé a tu kit desde el link de tu compra.'],
                410 => ['Este acceso venció', 'El link de tu compra venció o fue anulado.'],
                429 => ['Hubo demasiados intentos', 'Probá de nuevo en unos minutos.'],
            ][$file['status']] ?? ['No está disponible en este momento', 'Probá más tarde o escribinos a ' . (string) $config->get('mail_reply_to') . '.'];
            self::messagePage($file['status'], $title, $message);
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $file['type']);
        header('Content-Disposition: inline; filename="' . $file['filename'] . '"');
        header('Content-Length: ' . (string) filesize($file['path']));
        readfile($file['path']);
    }

    /** A small page for the buyer when a file or a page of the kit cannot be served. */
    public static function messagePage(int $status, string $title, string $message): void
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . $e($title) . ' — FENIA</title></head>'
            . '<body style="margin:0;padding:48px 20px;background:#0b1a2e;color:#fff;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto"><h1 style="font-size:24px">' . $e($title) . '</h1>'
            . '<p style="font-size:16px;line-height:1.6">' . $e($message) . '</p>'
            . '<p style="font-size:16px;line-height:1.6">Pedí un link nuevo en <a style="color:#00b4d8" href="/no-me-llego/">No me llegó</a> con el email de tu compra.</p>'
            . '</div></body></html>';
    }

    /**
     * The JSON body whatever its Content-Type: navigator.sendBeacon posts it as
     * text/plain (no preflight). Small bodies only.
     *
     * @return array<string, mixed>|null
     */
    public static function beaconInput(int $maxBytes = 2048): ?array
    {
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || $raw === '' || strlen($raw) > $maxBytes) {
            return null;
        }
        $data = json_decode($raw, true, 3);
        return is_array($data) ? $data : null;
    }

    /** Owners' session (ADR-029): HttpOnly, HTTPS only, only for /api/, never from another site. */
    public static function setSessionCookies(string $token): void
    {
        $expires = time() + Owners::DAYS * 86400;
        setcookie(Owners::COOKIE, $token, ['expires' => $expires, 'path' => '/api/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        // Readable flag (no secret): the visit counter skips the owners' own visits.
        setcookie(Owners::FLAG, '1', ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => false, 'samesite' => 'Strict']);
    }

    public static function clearSessionCookies(): void
    {
        setcookie(Owners::COOKIE, '', ['expires' => 1, 'path' => '/api/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        setcookie(Owners::FLAG, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => false, 'samesite' => 'Strict']);
    }

    public static function sessionCookie(): ?string
    {
        $value = $_COOKIE[Owners::COOKIE] ?? null;
        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> request headers we read, lowercased names */
    public static function headers(): array
    {
        $out = [];
        foreach (['x-signature' => 'HTTP_X_SIGNATURE', 'x-request-id' => 'HTTP_X_REQUEST_ID'] as $name => $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $out[$name] = $_SERVER[$key];
            }
        }
        return $out;
    }
}
