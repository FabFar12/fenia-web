<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * How secrets and kit ZIPs reach the private folder (open question Q5).
 *
 * Hostinger's file API and the deploy FTP account can only write inside
 * public_html. So files are dropped into `api/_inbox/` — denied over HTTP by
 * its .htaccess — and the API moves them into `<privateDir>` the next time it
 * runs, leaving nothing behind. Only `config.php`, kit ZIPs and guide PDFs are accepted;
 * anything else is left untouched (and stays unreachable).
 */
final class Inbox
{
    /** @return list<string> names of the files moved */
    public static function promote(string $inboxDir, string $privateDir): array
    {
        if (!is_dir($inboxDir)) {
            return [];
        }
        $moved = [];
        foreach (scandir($inboxDir) ?: [] as $name) {
            if ($name === 'config.php') {
                $target = $privateDir . '/config.php';
            } elseif (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.(zip|pdf)$/D', $name)) {
                $target = $privateDir . '/kits/' . $name;
            } else {
                continue;
            }
            $source = $inboxDir . '/' . $name;
            if (is_link($source) || !is_file($source)) {
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the private directory');
            }
            if (!rename($source, $target)) {
                throw new \RuntimeException('Cannot move an inbox file');
            }
            @chmod($target, 0600);
            $moved[] = $name;
        }
        return $moved;
    }
}
