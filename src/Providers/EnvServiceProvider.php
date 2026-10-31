<?php

declare(strict_types=1);

namespace Larena\Env\Providers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Admin\Navigation\AdminNavigationRegistry;
use Larena\Core\Process\ProcessExecutor;
use Larena\Env\Console\RegisterServerAreaCommand;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Navigation\ServerAdminNavigationContributor;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\People\PanelAudit;
use Larena\Env\People\PersonOwners;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;

final class EnvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/env.php', 'larena-env');
        $this->app->singleton(PanelGateway::class, static function (Application $app): PanelGateway {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return PanelGatewayFactory::make(
                (array) $config->get('larena-env.gateway', []),
                $app->environment((array) $config->get('larena-env.gateway.recorded.allowed_environments', [])),
                static fn (): ProcessExecutor => $app->make(ProcessExecutor::class),
            );
        });
        $this->app->bind(ServerOverviewReader::class, static fn (Application $app): ServerOverviewReader => new ServerOverviewReader($app->make(PanelGateway::class)));
        $this->app->bind(SiteDetailsReader::class, static fn (Application $app): SiteDetailsReader => new SiteDetailsReader($app->make(PanelGateway::class)));
        $this->app->bind(PanelOperations::class, static fn (Application $app): PanelOperations => new PanelOperations($app->make(PanelGateway::class)));
        // Which owner of the server a person at the user level works as (Panel plan 2.4).
        $this->app->bind(PersonOwners::class, static fn (Application $app): PersonOwners => new PersonOwners($app->make('db')->connection()));
        $this->app->bind(PanelAudit::class, static fn (Application $app): PanelAudit => new PanelAudit($app));
        $this->app->bind(PanelActorResolver::class, static fn (Application $app): PanelActorResolver => new PanelActorResolver(
            $app->make(\Larena\Access\Runtime\AccessOperationAuthorizer::class),
            $app->bound('db') ? $app->make(PersonOwners::class) : null,
        ));
        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
    }

    public function boot(Config $config): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'larena-env');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'larena-env');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        // Every panel screen says when a person of the whole server is looking at it as someone else.
        if ($this->app->bound('view')) {
            $app = $this->app;
            $this->app->make('view')->composer('larena-env::admin.*', static function ($view) use ($app): void {
                $request = $app->bound('request') ? $app->make('request') : null;
                if (!$request instanceof \Illuminate\Http\Request) {
                    return;
                }
                $actor = $request->attributes->get(ResolvePanelActor::ATTRIBUTE);
                $view->with('acting', $actor instanceof PanelActor && $actor->impersonatedBy !== null
                    ? ['label' => (string) ($request->hasSession() ? $request->session()->get(PanelActorResolver::ACTING_AS_LABEL, $actor->user) : $actor->user),
                        'owner' => (string) $actor->owner] : null);
            });
        }
        if ($this->composesAdmin()) {
            if ($this->app->bound(AdminNavigationRegistry::class)) {
                $this->app->make(AdminNavigationRegistry::class)->registerContributor(new ServerAdminNavigationContributor());
            }
            if ((bool) $config->get('larena-env.admin.enabled', true)) {
                $this->app->make('router')
                    ->middleware((array) $config->get('larena-env.admin.middleware', []))
                    ->prefix((string) $config->get('larena-env.admin.prefix', 'admin/server'))
                    ->group(__DIR__.'/../../routes/admin.php');
            }
        }
        if ($this->app->bound(AccessOperationRegistry::class)) {
            self::registerAccessOperations($this->app->make(AccessOperationRegistry::class));
        }
        if ($this->app->runningInConsole()) {
            $this->commands([RegisterServerAreaCommand::class]);
        }
    }

    /** The screen and its menu entry are composed only where larena/admin is installed; the rest works without it. */
    private function composesAdmin(): bool
    {
        return class_exists(AdminNavigationRegistry::class);
    }

    /** Reading the server is one right; changing it is another, and the screens ask for them apart. */
    private static function registerAccessOperations(AccessOperationRegistry $registry): bool
    {
        $read = $registry->register(new AccessOperationDescriptor(
            code: 'env.server.read',
            ownerPackage: 'larena/env',
            labelKey: 'larena-env::operations.server_read',
            target: 'env.server:all',
            requiredGrant: 'read',
            risk: 'normal',
            auditDenials: true,
        ));
        $operate = $registry->register(new AccessOperationDescriptor(
            code: 'env.server.operate',
            ownerPackage: 'larena/env',
            labelKey: 'larena-env::operations.server_operate',
            target: 'env.server:all',
            requiredGrant: 'write',
            risk: 'high',
            auditDenials: true,
        ));

        // The sites a person reaches at either level (owner decision 2026-10-31): the whole server's, or an owner's,
        // which the gateway narrows to.
        $sitesRead = $registry->register(new AccessOperationDescriptor(
            code: 'env.sites.read',
            ownerPackage: 'larena/env',
            labelKey: 'larena-env::operations.sites_read',
            target: 'env.sites:own',
            requiredGrant: 'read',
            risk: 'normal',
            auditDenials: true,
        ));
        $sitesOperate = $registry->register(new AccessOperationDescriptor(
            code: 'env.sites.operate',
            ownerPackage: 'larena/env',
            labelKey: 'larena-env::operations.sites_operate',
            target: 'env.sites:own',
            requiredGrant: 'write',
            risk: 'high',
            auditDenials: true,
        ));

        return $read && $operate && $sitesRead && $sitesOperate;
    }
}
