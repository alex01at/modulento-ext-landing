<?php

declare(strict_types=1);

namespace Modulento\Landing;

use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * One marketing landing page, built from ready-made blocks (a hero, a row
 * of figures, a card grid, a text section, a closing call to action) - no
 * HTML or CSS needed, filled in directly on the page itself (?edit=1),
 * exactly as a visitor will see it. Not linked from anywhere on its own;
 * add it to the main menu or the footer (Administration -> Menu) like any
 * other page once it is ready, or tick "use as the start page" on its own
 * edit screen to have "/" lead straight to it.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION = 'landing.manage';

    public function register(Registrar $registrar): void
    {
        $registrar->permission(self::PERMISSION, 'landing.permission.manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/landing', [LandingController::class, 'index'], Router::PUBLIC);

            $router->get('/admin/landing', [AdminController::class, 'index'], self::PERMISSION);
            $router->post('/admin/landing', [AdminController::class, 'save'], self::PERMISSION);
            $router->post('/admin/landing/reorder', [AdminController::class, 'reorder'], self::PERMISSION);
            $router->post('/admin/landing/order', [AdminController::class, 'order'], self::PERMISSION);
            $router->post('/admin/landing/field', [AdminController::class, 'field'], self::PERMISSION);
        });

        $registrar->adminMenu('landing.admin.menu', '/admin/landing', self::PERMISSION, 'content');
    }
}
