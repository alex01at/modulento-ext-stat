<?php

declare(strict_types=1);

namespace Modulento\Stat;

use Modulento\Core\App;
use PDO;

/**
 * Every aggregate StatController's dashboards show - nothing here ever
 * reads a single hit's own fields back out, only grouped/counted ones, so
 * what is already an anonymous row in the database stays anonymous in
 * what is shown too.
 */
final class StatQuery
{
    /** @return array<int, array{day: string, views: int, visitors: int}> one row per day in the range, zero-filled */
    public static function viewsOverTime(App $app, string $from, string $to): array
    {
        $stmt = $app->db->prepare(
            'SELECT day, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors
             FROM x_stat_hit WHERE day BETWEEN :from AND :to GROUP BY day'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[$row['day']] = ['views' => (int) $row['views'], 'visitors' => (int) $row['visitors']];
        }

        $rows = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $rows[] = ['day' => $day] + ($byDay[$day] ?? ['views' => 0, 'visitors' => 0]);
            $cursor = $cursor->modify('+1 day');
        }

        return $rows;
    }

    /** @return array{views: int, visitors: int} */
    public static function totals(App $app, string $from, string $to): array
    {
        $stmt = $app->db->prepare('SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors FROM x_stat_hit WHERE day BETWEEN :from AND :to');
        $stmt->execute(['from' => $from, 'to' => $to]);
        $row = $stmt->fetch();

        return ['views' => (int) ($row['views'] ?? 0), 'visitors' => (int) ($row['visitors'] ?? 0)];
    }

    /**
     * Paths under /offers/ (any locale prefix - LIKE, not a strict
     * comparison) separated out from everything else, each limited and
     * ordered by views.
     *
     * @return array{pages: array<int, array{path: string, views: int}>, offers: array<int, array{path: string, views: int}>}
     */
    public static function topPaths(App $app, string $from, string $to, int $limit = 10): array
    {
        $stmt = $app->db->prepare(
            "SELECT path, COUNT(*) AS views FROM x_stat_hit WHERE day BETWEEN :from AND :to AND path LIKE '%/offers/%'
             GROUP BY path ORDER BY views DESC LIMIT " . $limit
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $offers = array_map(fn (array $r) => ['path' => (string) $r['path'], 'views' => (int) $r['views']], $stmt->fetchAll());

        $stmt = $app->db->prepare(
            "SELECT path, COUNT(*) AS views FROM x_stat_hit WHERE day BETWEEN :from AND :to AND path NOT LIKE '%/offers/%'
             GROUP BY path ORDER BY views DESC LIMIT " . $limit
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        $pages = array_map(fn (array $r) => ['path' => (string) $r['path'], 'views' => (int) $r['views']], $stmt->fetchAll());

        return ['pages' => $pages, 'offers' => $offers];
    }

    /** Same as topPaths(), but only paths ending in one of the given offer slugs - a provider's own numbers. */
    public static function topPathsForSlugs(App $app, string $from, string $to, array $slugs, int $limit = 10): array
    {
        if ($slugs === []) {
            return [];
        }
        $stmt = $app->db->prepare(
            'SELECT path, COUNT(*) AS views FROM x_stat_hit WHERE day BETWEEN ? AND ?
             AND (' . implode(' OR ', array_fill(0, count($slugs), 'path LIKE ?')) . ')
             GROUP BY path ORDER BY views DESC LIMIT ' . $limit
        );
        // The '%' is part of the bound value, not the SQL - CONCAT() isn't portable (SQLite has no such function).
        $suffixes = array_map(fn (string $slug) => '%/offers/' . $slug, $slugs);
        $stmt->execute([$from, $to, ...$suffixes]);

        return array_map(fn (array $r) => ['path' => (string) $r['path'], 'views' => (int) $r['views']], $stmt->fetchAll());
    }

    /** @return array<int, array{domain: string, views: int}> */
    public static function referrers(App $app, string $from, string $to, int $limit = 10): array
    {
        $stmt = $app->db->prepare(
            'SELECT referrer_domain, COUNT(*) AS views FROM x_stat_hit WHERE day BETWEEN :from AND :to AND referrer_domain IS NOT NULL
             GROUP BY referrer_domain ORDER BY views DESC LIMIT ' . $limit
        );
        $stmt->execute(['from' => $from, 'to' => $to]);

        return array_map(fn (array $r) => ['domain' => (string) $r['referrer_domain'], 'views' => (int) $r['views']], $stmt->fetchAll());
    }

    /** @return array<string, int> device => views, every known device present even at 0 */
    public static function devices(App $app, string $from, string $to): array
    {
        $stmt = $app->db->prepare('SELECT device, COUNT(*) AS views FROM x_stat_hit WHERE day BETWEEN :from AND :to GROUP BY device');
        $stmt->execute(['from' => $from, 'to' => $to]);
        $counts = array_column($stmt->fetchAll(), 'views', 'device');

        return ['desktop' => (int) ($counts['desktop'] ?? 0), 'mobile' => (int) ($counts['mobile'] ?? 0), 'tablet' => (int) ($counts['tablet'] ?? 0)];
    }

    /** @return array<int, array{country: string, views: int}> empty when no host/CDN ever supplied a country - see HitRecorder::country() */
    public static function countries(App $app, string $from, string $to, int $limit = 10): array
    {
        $stmt = $app->db->prepare(
            'SELECT country, COUNT(*) AS views FROM x_stat_hit WHERE day BETWEEN :from AND :to AND country IS NOT NULL
             GROUP BY country ORDER BY views DESC LIMIT ' . $limit
        );
        $stmt->execute(['from' => $from, 'to' => $to]);

        return array_map(fn (array $r) => ['country' => (string) $r['country'], 'views' => (int) $r['views']], $stmt->fetchAll());
    }

    /**
     * Orders (core OrderFlow) or applications (jobs, which has none - see
     * Applications's own doc comment) placed in the period, whichever this
     * installation actually has - null when neither applies, so the
     * dashboard leaves the whole section out rather than show a count of
     * nothing meaningful.
     *
     * @return ?array{label_key: string, count: int, rate: ?float}
     */
    public static function conversion(App $app, string $from, string $to, int $visitors): ?array
    {
        $toExclusive = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');

        if ($app->hasOrderFlow()) {
            $stmt = $app->db->prepare('SELECT COUNT(*) FROM orders WHERE created_at >= :from AND created_at < :to');
            $stmt->execute(['from' => $from, 'to' => $toExclusive]);
            $count = (int) $stmt->fetchColumn();

            return ['label_key' => 'stat.conversion.orders', 'count' => $count, 'rate' => $visitors > 0 ? $count / $visitors : null];
        }

        if (isset($app->extensions->loaded()['jobs'])) {
            $stmt = $app->db->prepare('SELECT COUNT(*) FROM x_jobs_application WHERE created_at >= :from AND created_at < :to');
            $stmt->execute(['from' => $from, 'to' => $toExclusive]);
            $count = (int) $stmt->fetchColumn();

            return ['label_key' => 'stat.conversion.applications', 'count' => $count, 'rate' => $visitors > 0 ? $count / $visitors : null];
        }

        return null;
    }
}
