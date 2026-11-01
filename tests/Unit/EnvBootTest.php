<?php

declare(strict_types=1);

// larena/admin is optional: only its own pieces name it, the screen and the menu entry come only with it, and the
// gateway, the typed reads and the console work without it.

use Larena\Env\Console\PanelCheckCommand;
use Larena\Env\Console\RegisterServerAreaCommand;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @return array<string, mixed> */
function env_boot(string $mode): array
{
    $script = dirname(__DIR__).'/Support/env-boot.php';
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($mode).' 2>&1', $output, $exitCode);
    env_assert($exitCode === 0, "The provider did not boot ({$mode}): ".implode("\n", $output));

    return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
}

$without = env_boot('without');
env_assert($without['admin_loadable'] === false, 'The proof must really run without the admin classes.');
env_assert($without['routes'] === [], 'Without the admin no route may exist.');
env_assert($without['navigation'] === [], 'Without the admin no menu entry.');
env_assert($without['commands'] === [RegisterServerAreaCommand::class, PanelCheckCommand::class], 'The console serves without the admin, the panel\'s check too.');
env_assert($without['gateway_source'] === 'recorded' && $without['sites'] === 4, 'The gateway and the typed read work without the admin.');
env_assert($without['operation_owner'] === 'larena/env', 'The right is declared when the registry is resolved.');

$with = env_boot('with');
env_assert($with['admin_loadable'] === true, 'The admin must be installed for development.');
$navigation = $with['navigation'];
sort($navigation);
// The recording's server (simai-env 1.33.0) lists its services, not yet the firewall nor who reaches the panel: their rows wait.
env_assert($navigation === ['env.alerts.channel', 'env.dns', 'env.dns.connection', 'env.people', 'env.server', 'env.server.overview',
    'env.services', 'env.update'] && $with['navigation_valid'] === array_fill(0, 8, true),
    'With the admin the sites, the overview, the spaces, DNS, the alerts\' channel, the update and the services are valid menu entries.');
$rows = static fn (?\Closure $listed): array => array_map(static fn ($row): string => $row->id,
    (new \Larena\Env\Navigation\ServerAdminNavigationContributor($listed))->navigationDescriptors());
env_assert(array_intersect(['env.services', 'env.firewall', 'env.panel.access'], $rows(null)) === []
    && array_values(array_intersect(['env.services', 'env.firewall', 'env.panel.access'], $rows(static fn (string $command): bool => true))) === ['env.services', 'env.firewall', 'env.panel.access']
    && in_array('env.firewall', $rows(static fn (string $command): bool => $command === 'firewall:status'), true)
    && !in_array('env.services', $rows(static fn (string $command): bool => $command === 'firewall:status'), true), 'Each row only where the server lists its command.');
$names = array_keys($with['routes']);
sort($names);
env_assert($names === ['larena.env.admin.alerts.ack.plan', 'larena.env.admin.alerts.ack.run', 'larena.env.admin.alerts.channel',
    'larena.env.admin.alerts.channel.plan', 'larena.env.admin.alerts.channel.run', 'larena.env.admin.alerts.test.plan', 'larena.env.admin.alerts.test.run',
    'larena.env.admin.change.keep', 'larena.env.admin.dns', 'larena.env.admin.dns.connection', 'larena.env.admin.dns.connection.addresses',
    'larena.env.admin.dns.connection.disconnect', 'larena.env.admin.dns.connection.save', 'larena.env.admin.dns.run', 'larena.env.admin.dns.zone',
    'larena.env.admin.dns.zone.plan', 'larena.env.admin.dns.zone.undo', 'larena.env.admin.firewall', 'larena.env.admin.firewall.plan', 'larena.env.admin.firewall.run',
    'larena.env.admin.index', 'larena.env.admin.job', 'larena.env.admin.job.cancel', 'larena.env.admin.overview',
    'larena.env.admin.panel.access', 'larena.env.admin.people', 'larena.env.admin.people.act', 'larena.env.admin.people.assign', 'larena.env.admin.people.owners.plan',
    'larena.env.admin.people.owners.run', 'larena.env.admin.people.stop', 'larena.env.admin.services', 'larena.env.admin.services.plan', 'larena.env.admin.services.run',
    'larena.env.admin.site', 'larena.env.admin.site.cron.plan', 'larena.env.admin.site.cron.run', 'larena.env.admin.site.dns.plan', 'larena.env.admin.site.dns.run',
    'larena.env.admin.site.files.download', 'larena.env.admin.site.files.plan', 'larena.env.admin.site.files.run', 'larena.env.admin.site.nginx.plan',
    'larena.env.admin.site.nginx.run', 'larena.env.admin.site.plan',
    'larena.env.admin.site.run', 'larena.env.admin.sites.new', 'larena.env.admin.sites.plan', 'larena.env.admin.sites.run',
    'larena.env.admin.update', 'larena.env.admin.update.plan', 'larena.env.admin.update.run'],
    'The screens of the panel: '.implode(', ', $names));
env_assert($with['routes']['larena.env.admin.index']['uri'] === 'admin/server', 'The sites keep their address.');
env_assert($with['routes']['larena.env.admin.overview']['uri'] === 'admin/server/overview', 'The server\'s overview has its own address.');
foreach ($with['routes'] as $name => $route) {
    // Both levels reach their sites; every screen asks the gateway as the person's level.
    foreach (['web', 'larena-auth.entry', 'larena-auth.admin-required', 'larena-admin.locale', 'access:env.sites.read',
        \Larena\Env\Http\Middleware\ResolvePanelActor::class] as $middleware) {
        env_assert(in_array($middleware, $route['middleware'], true), "{$name} lost {$middleware}.");
    }
    $people = str_starts_with($name, 'larena.env.admin.people');
    // DNS through the provider (stage 7): the zones and the connection are the system level's own rights.
    $dns = str_starts_with($name, 'larena.env.admin.dns');
    $dnsReads = in_array($name, ['larena.env.admin.dns', 'larena.env.admin.dns.connection', 'larena.env.admin.dns.zone'], true);
    env_assert(in_array('access:env.dns.read', $route['middleware'], true) === $dnsReads, "{$name} asks for the wrong DNS right.");
    env_assert(in_array('access:env.dns.operate', $route['middleware'], true) === ($dns && !$dnsReads), "{$name} must ask for the DNS right.");
    // A site's own nginx directives can break the sites' isolation: the server's right, not a site's (Panel plan 6.3).
    $nginx = str_starts_with($name, 'larena.env.admin.site.nginx');
    // Where the server sends its alerts, and a test, are the system level's; acknowledging one changes a site's state.
    $channel = str_starts_with($name, 'larena.env.admin.alerts.channel') || str_starts_with($name, 'larena.env.admin.alerts.test');
    $channelChanges = $channel && $name !== 'larena.env.admin.alerts.channel';
    // A safe update is the administrator's alone: the screen reads the server, the update changes it (simai-env 1.32.0).
    $update = str_starts_with($name, 'larena.env.admin.update');
    $updateChanges = $update && $name !== 'larena.env.admin.update';
    // The services, the firewall and who reaches the panel are the administrator's (Panel plan 5.1–5.3); keeping a
    // timed change is a change of the server too. A site's own scheduled jobs are a site's (5.4).
    $serverTools = str_starts_with($name, 'larena.env.admin.services') || str_starts_with($name, 'larena.env.admin.firewall')
        || $name === 'larena.env.admin.panel.access' || $name === 'larena.env.admin.change.keep';
    $serverToolChanges = $serverTools && (str_ends_with($name, '.plan') || str_ends_with($name, '.run') || $name === 'larena.env.admin.change.keep');
    // Changing a site is a right of its own: a new site, a site's action and stopping a job ask for it.
    $changes = !$people && !$dns && !$nginx && !$channel && !$update && !$serverTools && (str_ends_with($name, '.plan') || str_ends_with($name, '.run') || str_ends_with($name, '.cancel') || $name === 'larena.env.admin.sites.new');
    env_assert(in_array('access:env.sites.operate', $route['middleware'], true) === $changes, "{$name} asks for the wrong right.");
    // The server's overview and the people's spaces are the system level's; giving a space or acting for someone changes the server.
    $reads = in_array($name, ['larena.env.admin.overview', 'larena.env.admin.people', 'larena.env.admin.people.stop', 'larena.env.admin.alerts.channel',
        'larena.env.admin.update', 'larena.env.admin.services', 'larena.env.admin.firewall', 'larena.env.admin.panel.access'], true);
    env_assert(in_array('access:env.server.read', $route['middleware'], true) === $reads, "{$name} asks for the wrong level.");
    env_assert(in_array('access:env.server.operate', $route['middleware'], true) === (($people && !$reads) || $nginx || $channelChanges || $updateChanges || $serverToolChanges), "{$name} must ask for the server's right.");
}
env_assert($with['commands'] === [RegisterServerAreaCommand::class, PanelCheckCommand::class] && $with['sites'] === 4, 'The rest does not change.');

$root = dirname(__DIR__, 2);
$adminNames = [];
foreach (['src', 'routes', 'config'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && preg_match('/Larena\\\\{1,2}Admin\\\\{1,2}/', (string) file_get_contents($file->getPathname())) === 1) {
            $adminNames[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($adminNames);
env_assert($adminNames === [
    'src/Admin/ServerSitesPage.php',
    'src/Http/Controllers/ServerAdminController.php',
    'src/Navigation/ServerAdminNavigationContributor.php',
    'src/Providers/EnvServiceProvider.php',
], 'Only the admin\'s own pieces may name the admin: '.implode(', ', $adminNames));

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
env_assert(!isset($composer['require']['larena/admin']) && isset($composer['require-dev']['larena/admin'], $composer['suggest']['larena/admin']), 'The admin is a development requirement and a suggestion.');

// A page is marked by the first row whose route pattern matches it, and the sites' row matches every panel route:
// the spaces and the overview come before it, or their pages would be named «Sites».
$source = (string) file_get_contents($root.'/src/Navigation/ServerAdminNavigationContributor.php');
env_assert(strpos($source, "id: 'env.people'") < strpos($source, "id: 'env.server',") && strpos($source, "id: 'env.server.overview'") < strpos($source, "id: 'env.server',"),
    'The narrower rows come before the sites row.');
$provider = (string) file_get_contents($root.'/src/Providers/EnvServiceProvider.php');
env_assert(str_contains($provider, "'larena-audit.history_sources', ['prefix' => 'env.', 'label' => 'larena-env::admin.audit_activity']"), 'The panel declares its events for the change history.');

echo "EnvBootTest passed.\n";
