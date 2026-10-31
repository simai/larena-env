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
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Navigation\ServerAdminNavigationContributor;
use Larena\Env\Operations\PanelOperations;
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
        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
    }

    public function boot(Config $config): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'larena-env');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'larena-env');
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
