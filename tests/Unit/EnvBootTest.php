<?php

declare(strict_types=1);

// larena/admin is optional: only its own pieces name it, the screen and the menu entry come only with it, and the
// gateway, the typed reads and the console work without it.

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
env_assert($without['commands'] === [RegisterServerAreaCommand::class], 'The console serves without the admin.');
env_assert($without['gateway_source'] === 'recorded' && $without['sites'] === 4, 'The gateway and the typed read work without the admin.');
env_assert($without['operation_owner'] === 'larena/env', 'The right is declared when the registry is resolved.');

$with = env_boot('with');
env_assert($with['admin_loadable'] === true, 'The admin must be installed for development.');
$navigation = $with['navigation'];
sort($navigation);
env_assert($navigation === ['env.people', 'env.server', 'env.server.overview'] && $with['navigation_valid'] === [true, true, true],
    'With the admin the sites, the overview and the spaces are valid menu entries.');
$names = array_keys($with['routes']);
sort($names);
env_assert($names === ['larena.env.admin.index', 'larena.env.admin.job', 'larena.env.admin.job.cancel', 'larena.env.admin.overview',
    'larena.env.admin.people', 'larena.env.admin.people.act', 'larena.env.admin.people.assign', 'larena.env.admin.people.owners.plan',
    'larena.env.admin.people.owners.run', 'larena.env.admin.people.stop', 'larena.env.admin.site', 'larena.env.admin.site.plan',
    'larena.env.admin.site.run', 'larena.env.admin.sites.new', 'larena.env.admin.sites.plan', 'larena.env.admin.sites.run'],
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
    // Changing a site is a right of its own: a new site, a site's action and stopping a job ask for it.
    $changes = !$people && (str_ends_with($name, '.plan') || str_ends_with($name, '.run') || str_ends_with($name, '.cancel') || $name === 'larena.env.admin.sites.new');
    env_assert(in_array('access:env.sites.operate', $route['middleware'], true) === $changes, "{$name} asks for the wrong right.");
    // The server's overview and the people's spaces are the system level's; giving a space or acting for someone changes the server.
    $reads = in_array($name, ['larena.env.admin.overview', 'larena.env.admin.people', 'larena.env.admin.people.stop'], true);
    env_assert(in_array('access:env.server.read', $route['middleware'], true) === $reads, "{$name} asks for the wrong level.");
    env_assert(in_array('access:env.server.operate', $route['middleware'], true) === ($people && !$reads), "{$name} must ask for the server's right.");
}
env_assert($with['commands'] === [RegisterServerAreaCommand::class] && $with['sites'] === 4, 'The rest does not change.');

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

echo "EnvBootTest passed.\n";
