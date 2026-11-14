<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Private visit counter (ADR-029). Every page of the site sends one small
 * beacon per page view (and per WhatsApp click) to /api/hit.php, and the data
 * stays in our own database: nothing goes to third parties.
 *
 * No cookie is set and nothing personal is stored. Instead of the IP, each
 * event carries a hash of IP + browser keyed with the day (Argentina time):
 * it tells visitors apart within a day, and a person cannot be followed from
 * one day to the next (the method Plausible uses). Bots are not counted, and
 * the owners' own visits are skipped by the page itself.
 */
final class Visits
{
    public const KINDS = ['view', 'whatsapp'];

    /** Automated clients, link previews and our own test browsers. */
    private const BOTS = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|whatsapp|telegram|discord|skype|curl|wget|python|java\/|go-http|node-fetch|undici|axios|okhttp|scrapy|phantom|selenium|playwright|puppeteer/i';

    /** Referrer host (or a parent domain) => source name, checked in order. */
    private const REFERRERS = [
        'mail.google.com' => 'Mail',
        'outlook.live.com' => 'Mail',
        'outlook.office.com' => 'Mail',
        'mail.yahoo.com' => 'Mail',
        'instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook',
        'fb.com' => 'Facebook',
        'fb.me' => 'Facebook',
        'whatsapp.com' => 'WhatsApp',
        'wa.me' => 'WhatsApp',
        'linkedin.com' => 'LinkedIn',
        'lnkd.in' => 'LinkedIn',
        't.co' => 'X',
        'x.com' => 'X',
        'twitter.com' => 'X',
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'tiktok.com' => 'TikTok',
        'bing.com' => 'Bing',
        'duckduckgo.com' => 'DuckDuckGo',
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
    ];

    /** utm_source values people write by hand => source name. */
    private const UTM_NAMES = [
        'ig' => 'Instagram', 'instagram' => 'Instagram',
        'fb' => 'Facebook', 'facebook' => 'Facebook',
        'wa' => 'WhatsApp', 'whatsapp' => 'WhatsApp',
        'google' => 'Google', 'linkedin' => 'LinkedIn',
        'mail' => 'Mail', 'email' => 'Mail', 'newsletter' => 'Mail', 'mailerlite' => 'Mail',
        'tiktok' => 'TikTok', 'youtube' => 'YouTube',
    ];

    /**
     * Stores one event. Returns false (and stores nothing) for bots and
     * malformed input; the endpoint answers the same either way.
     *
     * @param array<string, mixed> $in {k: kind, p: path, r: referrer, s/m/c: utm source/medium/campaign, w: viewport width, n: 1 = navigation inside the site}
     */
    public static function record(\PDO $pdo, array $in, string $ip, ?string $userAgent, string $salt, string $siteHost, \DateTimeImmutable $now): bool
    {
        if ($userAgent === null || trim($userAgent) === '' || preg_match(self::BOTS, $userAgent)) {
            return false;
        }
        $kind = $in['k'] ?? 'view';
        $path = self::path($in['p'] ?? null);
        if (!is_string($kind) || !in_array($kind, self::KINDS, true) || $path === null) {
            return false;
        }
        $inside = ($in['n'] ?? 0) === 1;
        $source = $inside ? 'Interno' : self::source(self::text($in['r'] ?? ''), self::text($in['s'] ?? ''), self::text($in['m'] ?? ''), $siteHost);
        $campaign = preg_replace('/[^A-Za-z0-9 _.\-]/', '', mb_substr(self::text($in['c'] ?? ''), 0, 80));
        $width = is_int($in['w'] ?? null) ? $in['w'] : 0;

        $local = $now->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $day = $local->format('Y-m-d');
        $stmt = $pdo->prepare('INSERT INTO site_events (at, day, kind, path, source, campaign, device, visitor, entry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            Db::now($now),
            $day,
            $kind,
            $path,
            $source,
            $campaign === '' ? null : $campaign,
            self::device($width),
            self::visitor($ip, $userAgent, $salt, $day),
            $kind === 'view' && !$inside ? 1 : 0,
        ]);
        return true;
    }

    /** Hash of IP + browser keyed with the day: same within a day, unrelated the next. */
    public static function visitor(string $ip, string $userAgent, string $salt, string $day): string
    {
        $key = hash_hmac('sha256', 'visits|' . $day, $salt, true);
        return substr(hash_hmac('sha256', $ip . '|' . $userAgent, $key), 0, 16);
    }

    /** A page of our site, normalized to its canonical form; null for anything else (the API, the internal metrics). */
    public static function path($raw): ?string
    {
        if (!is_string($raw) || $raw === '' || $raw[0] !== '/' || strlen($raw) > 180
            || !preg_match('~^/[A-Za-z0-9/_.\-]*$~D', $raw) || str_contains($raw, '//') || str_contains($raw, '..')
            || str_starts_with($raw, '/api/') || str_starts_with($raw, '/metricas')) {
            return null;
        }
        if (str_ends_with($raw, '/index.html')) {
            $raw = substr($raw, 0, -10);
        }
        return str_ends_with($raw, '/') || str_ends_with($raw, '.html') ? $raw : $raw . '/';
    }

    public static function source(string $referrer, string $utmSource, string $utmMedium, string $siteHost): string
    {
        $utm = strtolower(trim($utmSource));
        $medium = strtolower(trim($utmMedium));
        if (in_array($medium, ['email', 'mail', 'newsletter'], true)) {
            return 'Mail';
        }
        if ($utm !== '') {
            $clean = preg_replace('/[^a-z0-9._\-]/', '', $utm);
            return self::UTM_NAMES[$clean] ?? (ucfirst(substr((string) $clean, 0, 30)) ?: 'Campaña');
        }
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        if ($host === '') {
            return 'Directo';
        }
        $site = strtolower($siteHost);
        if ($host === $site || str_ends_with($host, '.' . $site) || str_ends_with($site, '.' . $host)) {
            return 'Interno';
        }
        foreach (self::REFERRERS as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $name;
            }
        }
        if (preg_match('/(^|\.)google\.[a-z.]{2,8}$/D', $host)) {
            return 'Google';
        }
        // Anything a page could put in its address: only host characters survive.
        $other = (string) preg_replace('/[^a-z0-9.\-]/', '', (string) preg_replace('/^www\./', '', $host));
        return $other === '' ? 'Otros' : substr($other, 0, 64);
    }

    private static function device(int $width): string
    {
        if ($width <= 0) {
            return 'otro';
        }
        return $width < 768 ? 'celular' : ($width < 1024 ? 'tablet' : 'compu');
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return is_string($value) ? mb_substr($value, 0, 500) : '';
    }
}
