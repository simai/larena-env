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
use Illuminate\Contracts\Translation\Translator;
use Larena\Auth\Contracts\PasswordResetDelivery;
use Larena\Auth\Runtime\RejectingPasswordResetDelivery;
use Larena\Env\Area\ServerArea;
use Larena\Env\Console\PanelResetPasswordCommand;
use Larena\Env\Http\Controllers\PanelEntryController;
use Larena\Env\Http\Middleware\GuardFirstRun;
use Larena\Env\Mail\PanelMail;
use Larena\Env\Mail\PanelMailer;
use Larena\Env\Mail\PanelPasswordResetDelivery;
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
        $this->bindPasswordResetMail();
        if ($this->app->runningInConsole()) {
            // A new password from the server's console, for when there is no mail and nobody else to ask (C335).
            $this->commands([PanelResetPasswordCommand::class]);
            $events->listen(CommandFinished::class, function (CommandFinished $event): void {
                if ($event->command === 'migrate' && $event->exitCode === 0) {
                    $this->completeMigration();
                }
            });
        }
    }

    /**
     * The sign-in package's postman (owner decision C335): while the panel's mail is on, a password reset link goes
     * through it and «Забыли пароль?» is a real link; otherwise the package's rejecting postman stays, and the sign-in
     * page says whom to ask. Asked at each resolve, so switching mail on or off acts at once.
     */
    private function bindPasswordResetMail(): void
    {
        if (!interface_exists(PasswordResetDelivery::class)) {
            return;
        }
        $this->app->bind(PasswordResetDelivery::class, static function (Application $app): PasswordResetDelivery {
            $mail = $app->make(PanelMail::class);
            if (!$mail->isOn()) {
                return new RejectingPasswordResetDelivery();
            }
            /** @var Config $config */
            $config = $app->make(Config::class);
            $base = rtrim((string) $config->get('app.url'), '/');

            return new PanelPasswordResetDelivery($mail, new PanelMailer(), $app->make(Translator::class),
                // The panel's own address, never the visitor's Host header: a link in a letter must not be steered elsewhere.
                static fn (string $challenge, string $secret): string => $base.$app->make('url')->route('larena.auth.admin.password.reset', ['challenge' => $challenge, 'secret' => $secret], false));
        });
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
