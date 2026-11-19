<?php

declare(strict_types=1);

// Boots the provider in a process of its own and prints what it composed. argv[1] is "without" (the admin's classes
// cannot be loaded) or "with" (the admin is there, with its registry bound). argv[2] turns the scheduler on:
// "env" by LARENA_ENV_SCHEDULER_ENABLED, "panel" by the panel's edition; nothing leaves it as a foreign app has it.

use Composer\Autoload\ClassLoader;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Admin\Navigation\AdminNavigationRegistry;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Providers\EnvServiceProvider;
use Larena\Env\Server\ServerOverviewReader;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/AdminPresenceHost.php';

putenv('LARENA_ENV_GATEWAY=recorded');
$scheduler = $argv[2] ?? '';
putenv($scheduler === 'env' ? 'LARENA_ENV_SCHEDULER_ENABLED=true' : 'LARENA_ENV_SCHEDULER_ENABLED');

$mode = $argv[1] ?? '';
if ($mode !== 'with') {
    foreach (ClassLoader::getRegisteredLoaders() as $loader) {
        $loader->setPsr4('Larena\\Admin\\', []);
    }
}

$app = new AdminPresenceHost();
Container::setInstance($app);
$config = new class implements ConfigContract {
    /** @var array<string, mixed> */
    private array $items = [];

    public function has($key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function get($key, $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function set($key, $value = null): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function prepend($key, $value): void
    {
    }

    public function push($key, $value): void
    {
    }
};
$app->instance('config', $config);
$app->instance(ConfigContract::class, $config);
$app->instance('router', new Router(new Dispatcher($app), $app));
$navigation = null;
if ($mode === 'with') {
    $navigation = new AdminNavigationRegistry();
    $app->instance(AdminNavigationRegistry::class, $navigation);
}
$provider = new EnvServiceProvider($app);
$provider->register();
if ($scheduler === 'panel') {
    (new \Larena\Env\Providers\PanelEditionServiceProvider($app))->register();
}
$app->call([$provider, 'boot']);
// The scheduler's locks are the application's cache; here nothing runs, so they are never asked.
$app->instance(\Illuminate\Console\Scheduling\EventMutex::class, new class implements \Illuminate\Console\Scheduling\EventMutex {
    public function create($event): bool { return true; }
    public function exists($event): bool { return false; }
    public function forget($event): void {}
});
$app->instance(\Illuminate\Console\Scheduling\SchedulingMutex::class, new class implements \Illuminate\Console\Scheduling\SchedulingMutex {
    public function create($event, DateTimeInterface $time): bool { return true; }
    public function exists($event, DateTimeInterface $time): bool { return false; }
});
$scheduled = array_map(static fn ($event): string => trim((string) preg_replace('/^.*artisan\'?\s+/', '', (string) $event->command)),
    $app->make(\Illuminate\Console\Scheduling\Schedule::class)->events());

$routes = [];
foreach ($app->make('router')->getRoutes()->getRoutes() as $route) {
    $routes[(string) $route->getName()] = ['uri' => $route->uri(), 'middleware' => array_values((array) $route->middleware())];
}
$artisan = new class {
    /** @var list<string> */
    public array $commands = [];

    /** @param list<string> $commands */
    public function resolveCommands(array $commands): void
    {
        $this->commands = [...$this->commands, ...$commands];
    }
};
foreach ((new ReflectionProperty(Artisan::class, 'bootstrappers'))->getValue() as $bootstrapper) {
    $bootstrapper($artisan);
}
$operation = $app->make(AccessOperationRegistry::class)->get('env.server.read');
$overview = $app->make(ServerOverviewReader::class)->read(new \Larena\Env\Gateway\PanelActor('boot@example.com'));

echo json_encode([
    'admin_loadable' => class_exists(AdminNavigationRegistry::class),
    'routes' => $routes,
    'navigation' => $navigation === null ? [] : array_map(static fn ($descriptor): string => $descriptor->id, $navigation->descriptors()),
    'navigation_valid' => $navigation === null ? [] : array_map(static fn ($descriptor): bool => $descriptor->isValid(), $navigation->descriptors()),
    'commands' => $artisan->commands,
    'gateway_source' => $app->make(PanelGateway::class)->source(),
    'sites' => $overview->overview === null ? null : count($overview->overview->sites),
    'operation_owner' => $operation?->ownerPackage,
    'scheduled' => $scheduled,
    'notification_scheduler' => $config->get('larena-notification.scheduler.enabled'),
], JSON_THROW_ON_ERROR);
