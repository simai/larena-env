<?php

declare(strict_types=1);

use Larena\Env\Http\Controllers\DnsController;
use Larena\Env\Http\Controllers\NewSiteController;
use Larena\Env\Http\Controllers\PeopleController;
use Larena\Env\Http\Controllers\ServerAdminController;
use Larena\Env\Http\Controllers\ServerJobController;
use Larena\Env\Http\Controllers\SiteAdminController;

/** @var Illuminate\Routing\Router $router */
$domain = '[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+';

// The sites are both levels' first screen; the server's overview is the system level's (env.server.read).
$router->get('/', [ServerAdminController::class, 'index'])->name('larena.env.admin.index');
$router->get('/overview', [ServerAdminController::class, 'overview'])->middleware('access:env.server.read')->name('larena.env.admin.overview');
// The people of the user level and their spaces, the system level's (Panel plan 2.4–2.6). Before the site routes.
$router->middleware(['access:env.server.read'])->group(static function ($router): void {
    $router->get('/people', [PeopleController::class, 'index'])->name('larena.env.admin.people');
    $router->post('/people/stop', [PeopleController::class, 'stop'])->name('larena.env.admin.people.stop');
});
$router->middleware(['access:env.server.operate'])->group(static function ($router): void {
    $router->post('/people/assign', [PeopleController::class, 'assign'])->name('larena.env.admin.people.assign');
    $router->post('/people/act', [PeopleController::class, 'actAs'])->name('larena.env.admin.people.act');
    $router->post('/people/owners/plan', [PeopleController::class, 'planOwner'])->name('larena.env.admin.people.owners.plan');
    $router->post('/people/owners/run', [PeopleController::class, 'runOwner'])->name('larena.env.admin.people.owners.run');
});
// DNS through the provider's API (Panel plan, stage 7): the zones and the connection are the system level's.
$router->middleware(['access:env.dns.read'])->group(static function ($router): void {
    $router->get('/dns', [DnsController::class, 'index'])->name('larena.env.admin.dns');
    $router->get('/dns/connection', [DnsController::class, 'connection'])->name('larena.env.admin.dns.connection');
    $router->get('/dns/zones/{zone}', [DnsController::class, 'zone'])->where('zone', '[a-f0-9]{32}')->name('larena.env.admin.dns.zone');
});
$router->middleware(['access:env.dns.operate'])->group(static function ($router): void {
    $router->post('/dns/connection', [DnsController::class, 'connect'])->name('larena.env.admin.dns.connection.save');
    $router->post('/dns/connection/addresses', [DnsController::class, 'addresses'])->name('larena.env.admin.dns.connection.addresses');
    $router->post('/dns/connection/disconnect', [DnsController::class, 'disconnect'])->name('larena.env.admin.dns.connection.disconnect');
    $router->post('/dns/zones/{zone}/plan', [DnsController::class, 'plan'])->where('zone', '[a-f0-9]{32}')->name('larena.env.admin.dns.zone.plan');
    $router->post('/dns/zones/{zone}/undo', [DnsController::class, 'undo'])->where('zone', '[a-f0-9]{32}')->name('larena.env.admin.dns.zone.undo');
    $router->post('/dns/run', [DnsController::class, 'run'])->name('larena.env.admin.dns.run');
});
$router->get('/sites/{domain}', [SiteAdminController::class, 'show'])->where('domain', $domain)->name('larena.env.admin.site');
$router->get('/jobs/{job}', [ServerJobController::class, 'show'])->where('job', '[a-f0-9]{24}')->name('larena.env.admin.job');

// Everything that changes the server needs its own right, and goes through a plan and a confirmation.
$router->middleware(['access:env.sites.operate'])->group(static function ($router) use ($domain): void {
    // A new site, at either level; a person's own lands in their space.
    $router->get('/sites/new', [NewSiteController::class, 'create'])->name('larena.env.admin.sites.new');
    $router->post('/sites/new/plan', [NewSiteController::class, 'plan'])->name('larena.env.admin.sites.plan');
    $router->post('/sites/new/run', [NewSiteController::class, 'run'])->name('larena.env.admin.sites.run');
    $router->post('/sites/{domain}/plan', [SiteAdminController::class, 'plan'])->where('domain', $domain)->name('larena.env.admin.site.plan');
    $router->post('/sites/{domain}/run', [SiteAdminController::class, 'run'])->where('domain', $domain)->name('larena.env.admin.site.run');
    // A site's own domain pointed at this server, at either level (Panel plan, stage 7).
    $router->post('/sites/{domain}/dns/plan', [DnsController::class, 'sitePlan'])->where('domain', $domain)->name('larena.env.admin.site.dns.plan');
    $router->post('/sites/{domain}/dns/run', [DnsController::class, 'siteRun'])->where('domain', $domain)->name('larena.env.admin.site.dns.run');
    $router->post('/jobs/{job}/cancel', [ServerJobController::class, 'cancel'])->where('job', '[a-f0-9]{24}')->name('larena.env.admin.job.cancel');
});
