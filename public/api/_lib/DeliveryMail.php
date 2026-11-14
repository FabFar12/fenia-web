<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * The delivery email (plain text + simple HTML). Same wording as the site
 * (src/data/site.ts `sales`). The link opens /descarga, which counts nothing
 * until the buyer clicks "Descargar" (threat model C6).
 */
final class DeliveryMail
{
    /**
     * @param bool $hasPages the kit opens as "Tu kit" (D25): guide to read, fichas to complete online
     * @return array{subject: string, text: string, html: string}
     */
    public static function build(string $kitTitle, string $link, string $siteUrl, string $supportEmail, int $days, int $maxDownloads, bool $hasGuide = false, bool $hasPages = false): array
    {
        if ($hasPages) {
            $ready = 'ya está listo';
            $button = 'Abrir mi kit';
            $what = 'En tu kit podés leer la guía, completar las fichas (en el celular o en la compu) y abrir los imprimibles. También podés descargar la guía en PDF y el kit completo en un ZIP.';
            $policy = "El link dura $days días. Leer la guía y completar las fichas no gasta nada; las descargas de archivos son hasta $maxDownloads.";
        } else {
            // Phones: the guide PDF opens directly; the full kit is a ZIP, easier on a computer.
            $ready = 'está listo para descargar';
            $button = 'Ir a la descarga';
            $what = $hasGuide
                ? 'En la página de descarga está la guía en PDF, que se abre directo (también en el celular), y el kit completo en un ZIP. Para completar las plantillas y fichas te conviene la compu: este mismo link funciona ahí.'
                : 'El kit viene en un ZIP. Para completar las plantillas y fichas te conviene la compu: este mismo link funciona ahí.';
            $policy = "El link dura $days días y permite $maxDownloads descargas. Abrirlo no gasta descargas: se cuenta solo cuando tocás \"Descargar\".";
        }
        $help = 'Si el link no te funciona, pedí uno nuevo en ' . $siteUrl . '/no-me-llego/ o escribinos a ' . $supportEmail . '.';
        $safety = 'FENIA nunca te va a pedir datos de tarjeta por mail ni por WhatsApp.';

        $text = "¡Gracias por tu compra!\n\n"
            . "Tu kit \"$kitTitle\" $ready:\n$link\n\n"
            . "$what\n\n$policy\n\n$help\n\n$safety\n\n"
            . "FENIA — Formación Estratégica de Neuro-Inteligencia Aumentada\n$siteUrl\n";

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#0b1a2e">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px">'
            . '<p style="font-size:13px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#5b6b7f;margin:0 0 12px">FENIA</p>'
            . '<h1 style="font-size:22px;line-height:1.3;margin:0 0 16px">¡Gracias por tu compra!</h1>'
            . '<p style="font-size:16px;line-height:1.6;margin:0 0 24px">Tu kit <strong>' . $e($kitTitle) . '</strong> ' . $e($ready) . '.</p>'
            . '<p style="margin:0 0 24px"><a href="' . $e($link) . '" style="display:inline-block;background:#e8573d;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:10px">' . $e($button) . '</a></p>'
            . '<p style="font-size:14px;line-height:1.6;color:#3d4b5c;margin:0 0 12px">' . $e($what) . '</p>'
            . '<p style="font-size:14px;line-height:1.6;color:#3d4b5c;margin:0 0 12px">' . $e($policy) . '</p>'
            . '<p style="font-size:14px;line-height:1.6;color:#3d4b5c;margin:0 0 12px">Si el botón no funciona, copiá este link en tu navegador:<br><span style="word-break:break-all">' . $e($link) . '</span></p>'
            . '<p style="font-size:14px;line-height:1.6;color:#3d4b5c;margin:0 0 12px">' . $e($help) . '</p>'
            . '<p style="font-size:13px;line-height:1.6;color:#5b6b7f;margin:24px 0 0">' . $e($safety) . '</p>'
            . '</div></body></html>';

        return ['subject' => 'Tu kit FENIA: ' . $kitTitle, 'text' => $text, 'html' => $html];
    }
}
