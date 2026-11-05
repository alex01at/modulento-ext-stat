<?php

declare(strict_types=1);

namespace Modulento\Stat;

use Modulento\Core\Controller\Controller;

final class StatController extends Controller
{
    /** The beacon itself, rendered rather than a static file - url() needs to know the install's own base path (e.g. a subdirectory). */
    public function script(array $params): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $this->app->view()->render('@stat/beacon.twig', []);
    }

    /**
     * The beacon's own request on every page load - a plain JSON POST, no
     * CSRF token (there is no session this belongs to) and nothing to
     * read back, so a 204 with no body either way is all a caller needs.
     * A JS-only hit is itself a basic, incidental bot filter: a crawler
     * that does not execute the page's script never calls this at all.
     */
    public function hit(array $params): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $path = is_array($body) && is_string($body['path'] ?? null) ? $body['path'] : null;
        $referrer = is_array($body) && is_string($body['referrer'] ?? null) ? $body['referrer'] : null;

        if ($path !== null) {
            HitRecorder::record($this->app, $path, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $referrer);
        }

        http_response_code(204);
    }

    /** The whole site's own numbers - Administration → Statistik. */
    public function admin(array $params): void
    {
        [$from, $to, $range] = $this->period();

        $totals = StatQuery::totals($this->app, $from, $to);
        $series = StatQuery::viewsOverTime($this->app, $from, $to);
        $this->render('@stat/admin_dashboard.twig', [
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'series' => $series,
            'chart' => ChartGeometry::lineChart($series),
            'top' => StatQuery::topPaths($this->app, $from, $to),
            'referrers' => StatQuery::referrers($this->app, $from, $to),
            'devices' => StatQuery::devices($this->app, $from, $to),
            'countries' => StatQuery::countries($this->app, $from, $to),
            'conversion' => StatQuery::conversion($this->app, $from, $to, $totals['visitors']),
        ]);
    }

    /** A provider's own numbers, for their own offers only - /account/stats. */
    public function account(array $params): void
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        [$from, $to, $range] = $this->period();
        $locale = $this->app->translator->locale();
        $offers = $this->app->offers->listPublic(['provider_id' => (int) $provider['id']], $locale, 1, 200)['rows'];
        $texts = array_filter(array_map(fn (array $o) => $this->app->offers->text($o, $locale), $offers));
        $slugs = array_values(array_filter(array_map(fn (array $t) => (string) ($t['slug'] ?? ''), $texts)));
        $titles = array_column($texts, 'title', 'slug');

        $top = array_map(
            fn (array $row) => $row + ['title' => $titles[self::slugFromPath($row['path'])] ?? $row['path']],
            StatQuery::topPathsForSlugs($this->app, $from, $to, $slugs)
        );

        $this->render('@stat/account_dashboard.twig', [
            'range' => $range,
            'from' => $from,
            'to' => $to,
            'top' => $top,
            'total_views' => array_sum(array_column($top, 'views')),
        ]);
    }

    private static function slugFromPath(string $path): string
    {
        $segments = explode('/', rtrim($path, '/'));

        return (string) end($segments);
    }

    /** @return array{0: string, 1: string, 2: string} from, to, the range name shown as selected */
    private function period(): array
    {
        $range = in_array($_GET['range'] ?? '', ['7', '30', '90'], true) ? $_GET['range'] : '30';
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime("-{$range} days"));

        return [$from, $to, $range];
    }
}
