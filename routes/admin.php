<?php

declare(strict_types=1);

use Larena\Env\Http\Controllers\ServerAdminController;
use Larena\Env\Http\Controllers\ServerJobController;
use Larena\Env\Http\Controllers\SiteAdminController;

/** @var Illuminate\Routing\Router $router */
$domain = '[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+';

$router->get('/', [ServerAdminController::class, 'index'])->name('larena.env.admin.index');
$router->get('/sites/{domain}', [SiteAdminController::class, 'show'])->where('domain', $domain)->name('larena.env.admin.site');
$router->get('/jobs/{job}', [ServerJobController::class, 'show'])->where('job', '[a-f0-9]{24}')->name('larena.env.admin.job');

// Everything that changes the server needs its own right, and goes through a plan and a confirmation.
$router->middleware(['access:env.server.operate'])->group(static function ($router) use ($domain): void {
    $router->post('/sites/{domain}/plan', [SiteAdminController::class, 'plan'])->where('domain', $domain)->name('larena.env.admin.site.plan');
    $router->post('/sites/{domain}/run', [SiteAdminController::class, 'run'])->where('domain', $domain)->name('larena.env.admin.site.run');
    $router->post('/jobs/{job}/cancel', [ServerJobController::class, 'cancel'])->where('job', '[a-f0-9]{24}')->name('larena.env.admin.job.cancel');
});
