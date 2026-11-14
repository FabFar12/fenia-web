<?php

declare(strict_types=1);

namespace Fenia\Api;

defined('FENIA_API') || exit;

/**
 * Daily speed check of the site (ADR-029), run by the hourly job once a day.
 * Asks Google's PageSpeed Insights for a phone visit of each page and keeps
 * the score and the Core Web Vitals: the lab values every day, and the real
 * visitors' values (Chrome UX Report) when the site has enough traffic.
 * "Good" means LCP ≤ 2.5 s, INP ≤ 200 ms and CLS ≤ 0.1.
 *
 * The HTTP getter is injectable so the tests never touch the network.
 */
final class SpeedCheck
{
    private const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * @param list<string> $urls
     * @param callable(string): array{status: int, body: string} $get
     * @return int pages checked
     */
    public static function run(\PDO $pdo, array $urls, string $apiKey, callable $get, \DateTimeImmutable $now): int
    {
        $day = $now->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d');
        foreach ($urls as $url) {
            $query = ['url' => $url, 'strategy' => 'mobile', 'category' => 'performance'] + ($apiKey !== '' ? ['key' => $apiKey] : []);
            $row = ['score' => null, 'lcp_ms' => null, 'cls_milli' => null, 'tbt_ms' => null, 'field_lcp_ms' => null, 'field_inp_ms' => null, 'field_cls_milli' => null, 'error' => null];
            try {
                $res = $get(self::API . '?' . http_build_query($query));
                if ($res['status'] !== 200) {
                    throw new \RuntimeException('PageSpeed respondió HTTP ' . $res['status']);
                }
                $row = self::parse(json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR)) + $row;
            } catch (\Throwable $e) {
                $row['error'] = mb_substr($e->getMessage(), 0, 160);
            }
            $pdo->prepare(
                'INSERT INTO speed_checks (day, url, score, lcp_ms, cls_milli, tbt_ms, field_lcp_ms, field_inp_ms, field_cls_milli, error, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$day, mb_substr($url, 0, 191), $row['score'], $row['lcp_ms'], $row['cls_milli'], $row['tbt_ms'], $row['field_lcp_ms'], $row['field_inp_ms'], $row['field_cls_milli'], $row['error'], Db::now($now)]);
        }
        return count($urls);
    }

    /**
     * @param mixed $data PageSpeed Insights v5 answer
     * @return array<string, ?int>
     */
    public static function parse($data): array
    {
        $num = static fn ($v): ?int => is_int($v) || is_float($v) ? (int) round($v) : null;
        $lab = is_array($data) ? ($data['lighthouseResult'] ?? []) : [];
        $audits = is_array($lab['audits'] ?? null) ? $lab['audits'] : [];
        $score = $lab['categories']['performance']['score'] ?? null;
        $cls = $audits['cumulative-layout-shift']['numericValue'] ?? null;
        $field = is_array($data) ? ($data['loadingExperience']['metrics'] ?? []) : [];
        // The Chrome UX Report gives CLS multiplied by 100 (5 = 0.05).
        $fieldCls = $field['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? null;
        return [
            'score' => is_int($score) || is_float($score) ? (int) round($score * 100) : null,
            'lcp_ms' => $num($audits['largest-contentful-paint']['numericValue'] ?? null),
            'cls_milli' => is_int($cls) || is_float($cls) ? (int) round($cls * 1000) : null,
            'tbt_ms' => $num($audits['total-blocking-time']['numericValue'] ?? null),
            'field_lcp_ms' => $num($field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null),
            'field_inp_ms' => $num($field['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null),
            'field_cls_milli' => is_int($fieldCls) || is_float($fieldCls) ? (int) round($fieldCls * 10) : null,
        ];
    }

    /** @return array{status: int, body: string} */
    public static function curlGet(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}
