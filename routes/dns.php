<?php

declare(strict_types=1);

use Larena\Env\Http\Controllers\DnsCertificatesController;
use Larena\Env\Http\Controllers\DnsController;

// DNS through the provider's API (Panel plan, stage 7), loaded only where the product chose env.dns (auditor
// msg-3d61bd36, point 5), with the admin routes' middleware and prefix.

/** @var Illuminate\Routing\Router $router */
$domain = '[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}[A-Za-z0-9])?)+';

// The zones and the connection are the system level's.
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
// The panel's Cloudflare token given to the server for certificates through DNS, or taken back (simai-env 1.36.0): the
// server's change made with the DNS connection, so both rights.
$router->middleware(['access:env.server.operate'])->group(static function ($router): void {
    $router->post('/dns/certificates/plan', [DnsCertificatesController::class, 'plan'])->middleware('access:env.dns.operate')->name('larena.env.admin.dns.certificates.plan');
    $router->post('/dns/certificates/run', [DnsCertificatesController::class, 'run'])->middleware('access:env.dns.operate')->name('larena.env.admin.dns.certificates.run');
});
// A site's own domain pointed at this server, at either level.
$router->middleware(['access:env.sites.operate'])->group(static function ($router) use ($domain): void {
    $router->post('/sites/{domain}/dns/plan', [DnsController::class, 'sitePlan'])->where('domain', $domain)->name('larena.env.admin.site.dns.plan');
    $router->post('/sites/{domain}/dns/run', [DnsController::class, 'siteRun'])->where('domain', $domain)->name('larena.env.admin.site.dns.run');
});
