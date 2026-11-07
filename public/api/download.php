<?php

declare(strict_types=1);

// POST /api/download.php — the /descarga page.
//   JSON {token, info: true} → what is left on the link (counts nothing).
//   Form POST token=…        → streams the ZIP and counts one download.
// GET and HEAD never count (threat model C6: mail scanners, link previews).
define('FENIA_API', true);
require __DIR__ . '/_lib/autoload.php';

use Fenia\Api\Api;
use Fenia\Api\Config;
use Fenia\Api\Shop;

Api::run(['POST'], static function (Shop $shop, Config $config): ?array {
    if (!Api::originOk($config)) {
        return [403, ['usable' => false]];
    }

    $in = Api::jsonInput();
    if ($in !== null) {
        $token = is_string($in['token'] ?? null) ? $in['token'] : '';
        return $shop->downloadInfo($token, Api::clientIp());
    }

    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    $agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    $file = $shop->download($token, Api::clientIp(), $agent);

    if ($file['status'] !== 200 || !isset($file['path'], $file['filename'])) {
        $support = htmlspecialchars((string) $config->get('mail_reply_to'), ENT_QUOTES, 'UTF-8');
        $message = [
            429 => 'Hubo demasiados intentos. Probá de nuevo en unos minutos.',
            503 => 'La descarga no está disponible en este momento. Probá más tarde o escribinos a ' . $support . '.',
        ][$file['status']] ?? 'Este link venció o ya no tiene descargas disponibles.';
        http_response_code($file['status']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Descarga — FENIA</title></head>'
            . '<body style="margin:0;padding:48px 20px;background:#0b1a2e;color:#fff;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto"><h1 style="font-size:24px">No pudimos iniciar la descarga</h1>'
            . '<p style="font-size:16px;line-height:1.6">' . $message . '</p>'
            . '<p style="font-size:16px;line-height:1.6">Pedí un link nuevo en <a style="color:#00b4d8" href="/no-me-llego/">No me llegó</a> con el email de tu compra.</p>'
            . '</div></body></html>';
        return null;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('Content-Length: ' . (string) filesize($file['path']));
    readfile($file['path']);
    return null;
});
