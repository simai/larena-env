<?php

declare(strict_types=1);

namespace Larena\Env\Providers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Larena\Env\Area\ServerArea;
use Larena\Env\Http\Controllers\PanelEntryController;
use Larena\Env\Http\Middleware\GuardFirstRun;
use Larena\Env\Panel\EnvFile;
use Larena\Env\Panel\ReleaseAssetState;
use Larena\Env\Panel\SetupLink;
use RuntimeException;

/**
 * The Larena Panel edition: only the panel's assembly lists this provider, so installing larena/env in a CMS changes
 * nothing there. It declares the product's first run, the address `/` with the setup link, and the release's asset state.
 */
final class PanelEditionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);
        $config->set('larena-core.first_run', ServerArea::firstRunDeclaration());
        $this->app->singleton(SetupLink::class, static fn (Application $app): SetupLink => new SetupLink(new EnvFile($app->basePath('.env'))));
    }

    public function boot(Router $router, Dispatcher $events): void
    {
        ReleaseAssetState::sync(
            $this->app->resourcePath('panel-release'),
            $this->app->storagePath('app/larena/core-assets'),
        );
        $router->pushMiddlewareToGroup('web', GuardFirstRun::class);
        $router->middleware('web')->get('/', PanelEntryController::class)->name('larena.env.panel.entry');
        // The admin's own home is a developer route; in the panel its address and home link lead to the server screen.
        $router->middleware('web')->get('admin', PanelEntryController::class)->name('larena.admin.entry');
        if ($this->app->runningInConsole()) {
            $events->listen(CommandFinished::class, function (CommandFinished $event): void {
                if ($event->command === 'migrate' && $event->exitCode === 0) {
                    $this->completeMigration();
                }
            });
        }
    }

    /**
     * What a release needs after its migrations; simai-env runs `artisan migrate` and nothing else. Both commands are safe
     * to run again, and a failure fails the deploy, so that the old release keeps serving.
     */
    private function completeMigration(): void
    {
        $kernel = $this->app->make(Kernel::class);
        foreach (['larena:access-sync-system-presets', 'larena:env:area'] as $command) {
            if ($kernel->call($command, ['--no-interaction' => true]) !== 0) {
                throw new RuntimeException('panel_migration_step_failed:'.$command);
            }
        }
    }
}
