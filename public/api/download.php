<?php

declare(strict_types=1);

// POST /api/download.php — the /descarga page.
//   JSON {token, info: true} → what is left on the link (counts nothing).
//   Form POST token=… file=zip|guide → streams the ZIP or the guide PDF and
//                                       counts one download.
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
    $which = ($_POST['file'] ?? 'zip') === 'guide' ? 'guide' : 'zip';
    $file = $shop->download($token, Api::clientIp(), $agent, $which);

    if ($file['status'] !== 200 || !isset($file['path'], $file['filename'])) {
        $message = [
            429 => 'Hubo demasiados intentos. Probá de nuevo en unos minutos.',
            503 => 'La descarga no está disponible en este momento. Probá más tarde o escribinos a ' . (string) $config->get('mail_reply_to') . '.',
        ][$file['status']] ?? 'Este link venció o ya no tiene descargas disponibles.';
        Api::messagePage($file['status'], 'No pudimos iniciar la descarga', $message);
        return null;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . (str_ends_with($file['filename'], '.pdf') ? 'application/pdf' : 'application/zip'));
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('Content-Length: ' . (string) filesize($file['path']));
    readfile($file['path']);
    return null;
});
