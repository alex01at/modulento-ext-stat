<?php

declare(strict_types=1);

namespace Modulento\Stat;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Cookie-free, consent-free visitor statistics: page views, top pages/
 * offers, referrers, device/country, and (where this installation has
 * either) a conversion rate against orders or job applications. See
 * HitRecorder's own doc comment for what makes it consent-free - nothing
 * identifying is ever stored, only a hash that changes every day.
 *
 * An addon, not an app: works alongside freelancer, shop, jobs or any
 * other, and with no app at all.
 */
final class Extension implements ExtensionContract
{
    private const RETENTION_DAYS = 400;

    public function register(Registrar $registrar): void
    {
        $registrar->permission('stat.view', 'stat.permission.view');

        $registrar->routes(function (Router $router): void {
            $router->get('/stat.js', [StatController::class, 'script'], Router::PUBLIC);
            $router->post('/stat/hit', [StatController::class, 'hit'], Router::PUBLIC, csrfExempt: true);
            $router->get('/admin/stat', [StatController::class, 'admin'], 'stat.view');
            $router->get('/account/stats', [StatController::class, 'account']);
        });

        $registrar->bodyScript('@stat/script_tag.twig');
        $registrar->adminMenu('stat.admin.menu', '/admin/stat', 'stat.view', 'system');
        $registrar->accountLink('stat.nav.account', '/account/stats', fn (App $app): bool
            => $app->providers->findByAccount((int) ($app->auth->account()['id'] ?? 0)) !== null);

        // Keeps the table from growing forever - data minimisation, not
        // just housekeeping: a hit has no reason to exist once it is older
        // than the longest period any chart on either dashboard offers.
        $registrar->task('stat.prune', 1440, function (App $app): void {
            $app->db->prepare('DELETE FROM x_stat_hit WHERE day < :before')
                ->execute(['before' => date('Y-m-d', strtotime('-' . self::RETENTION_DAYS . ' days'))]);
        });
    }
}
