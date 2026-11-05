<?php

declare(strict_types=1);

namespace Modulento\Stat;

use Modulento\Core\App;
use Modulento\Core\Support\ClientIp;

/**
 * Records one page view - cookie-free, no persistent identifier: the
 * "visitor" is a salted hash of the IP and User-Agent that changes every
 * day, so two hits from the same person on different days can never be
 * linked back together, and the hash itself cannot be reversed to an IP.
 * This is what lets the addon run with no consent banner at all - nothing
 * identifying is ever stored, only today's hash of it.
 */
final class HitRecorder
{
    public static function record(App $app, string $path, string $userAgent, ?string $referrer): void
    {
        // A home page, a slug, a query string - only the path matters, and
        // only up to a sane length (a malformed/attack request should not
        // grow a row without bound).
        $path = mb_substr(parse_url($path, PHP_URL_PATH) ?: '/', 0, 255);

        $db = $app->db;
        $db->prepare(
            'INSERT INTO x_stat_hit (day, path, referrer_domain, device, country, visitor_hash)
             VALUES (:day, :path, :referrer_domain, :device, :country, :visitor_hash)'
        )->execute([
            'day' => date('Y-m-d'),
            'path' => $path,
            'referrer_domain' => self::refererDomain($referrer, $app),
            'device' => self::device($userAgent),
            'country' => self::country(),
            'visitor_hash' => self::visitorHash($app, $userAgent),
        ]);
    }

    /** null for no referrer, an internal one, or one that fails to parse - only another site is worth naming. */
    private static function refererDomain(?string $referrer, App $app): ?string
    {
        if ($referrer === null || $referrer === '') {
            return null;
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $ownHost = parse_url($app->url('/', absolute: true), PHP_URL_HOST);
        if (strcasecmp($host, (string) $ownHost) === 0) {
            return null;
        }

        return mb_substr(strtolower($host), 0, 255);
    }

    private static function device(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet') || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Best effort, never a lookup of our own: some hosts/CDNs already
     * resolve the visitor's country and hand it over in a header (e.g.
     * Cloudflare's CF-IPCountry) - used only if already there, never
     * fetched from a third party and never derived from a stored IP.
     */
    private static function country(): ?string
    {
        $header = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;

        return is_string($header) && preg_match('/^[A-Z]{2}$/', $header) === 1 ? $header : null;
    }

    private static function visitorHash(App $app, string $userAgent): string
    {
        $secret = @file_get_contents((string) ($app->config['app']['secret_key'] ?? ''));
        $salt = ($secret !== false ? $secret : '') . '|' . date('Y-m-d');

        return substr(hash('sha256', $salt . '|' . ClientIp::key() . '|' . $userAgent), 0, 16);
    }
}
