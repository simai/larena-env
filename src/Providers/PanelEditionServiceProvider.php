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
use Illuminate\Console\Scheduling\Schedule;
use Larena\Auth\Contracts\ActivationDelivery;
use Larena\Auth\Contracts\AuthSecurityEventSink;
use Larena\Auth\Contracts\PasswordResetDelivery;
use Larena\Auth\Runtime\RejectingActivationDelivery;
use Larena\Auth\Runtime\RejectingPasswordResetDelivery;
use Larena\Env\Admin\MailHistorySource;
use Larena\Env\Console\PanelMailCommand;
use Larena\Env\Mail\InvitationLetter;
use Larena\Env\Mail\MailLetters;
use Larena\Env\Mail\PanelInvitationDelivery;
use Larena\Env\Mail\PanelSecurityLetters;
use Larena\Env\Mail\PasswordChangedLetter;
use Larena\Env\Warnings\MailAlertSource;
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
        // An invitation by mail waits 72 hours for its answer (plan «Приглашение»): 30 minutes is too short for one.
        $config->set('larena-auth.user_invitation.activation_expiry_minutes', self::INVITATION_HOURS * 60);
        // Who invites and what the person will do, for the one invitation letter of a request.
        $this->app->scoped(InvitationLetter::class, static fn (): InvitationLetter => new InvitationLetter());
        $this->app->bind(PasswordChangedLetter::class, static fn (Application $app): PasswordChangedLetter => new PasswordChangedLetter(
            $app->bound('db') ? $app->make('db')->connection() : null,
            $app->make(PanelMail::class),
            $app->make(MailLetters::class),
            $app->make(Translator::class),
            self::panelName($app),
        ));
        // A completed password recovery queues «Ваш пароль изменён» (C335), in the same transaction as the new password.
        if (interface_exists(AuthSecurityEventSink::class)) {
            $this->app->extend(AuthSecurityEventSink::class, static fn (AuthSecurityEventSink $sink, Application $app): AuthSecurityEventSink =>
                $sink instanceof PanelSecurityLetters ? $sink : new PanelSecurityLetters($sink, static fn (): PasswordChangedLetter => $app->make(PasswordChangedLetter::class)));
        }
    }

    /** How long an invitation's link works, in hours. */
    public const int INVITATION_HOURS = 72;

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
        $this->bindInvitationMail();
        $this->composeMail();
        // The panel's letter carrier each minute: the waiting letters, tried again after 1, 5 and 30 minutes (C335, step 2).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('larena:env-mail')->everyMinute()->withoutOverlapping(5);
        });
        if ($this->app->runningInConsole()) {
            // A new password from the server's console, for when there is no mail and nobody else to ask (C335).
            $this->commands([PanelResetPasswordCommand::class, PanelMailCommand::class]);
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
                static fn (string $challenge, string $secret): string => $base.$app->make('url')->route('larena.auth.admin.password.reset', ['challenge' => $challenge, 'secret' => $secret], false),
                $app->make(MailLetters::class));
        });
    }

    /**
     * The sign-in package's postman for invitations (C335, «Приглашение»): while mail is on, «Пригласить человека» can
     * send the link «Задать пароль и войти»; otherwise the package's rejecting postman stays and only «Задать первый
     * пароль самому» is offered. Asked at each resolve, like the reset's.
     */
    private function bindInvitationMail(): void
    {
        if (!interface_exists(ActivationDelivery::class)) {
            return;
        }
        $this->app->bind(ActivationDelivery::class, static function (Application $app): ActivationDelivery {
            $mail = $app->make(PanelMail::class);
            if (!$mail->isOn()) {
                return new RejectingActivationDelivery();
            }
            /** @var Config $config */
            $config = $app->make(Config::class);
            $base = rtrim((string) $config->get('app.url'), '/');

            return new PanelInvitationDelivery($mail, new PanelMailer(), $app->make(Translator::class),
                static fn (string $challenge, string $secret): string => $base.$app->make('url')->route('larena.auth.admin.activation', ['challenge' => $challenge], false)
                    .'?'.http_build_query(['secret' => $secret]),
                $app->make(InvitationLetter::class), self::panelName($app), $app->make(MailLetters::class));
        });
    }

    /** «Почта не уходит» in the alert bar and «История» → «Почта», where the admin draws them (C335, step 2). */
    private function composeMail(): void
    {
        $app = $this->app;
        $authorizer = static fn (): ?\Larena\Access\Runtime\AccessOperationAuthorizer => $app->bound(\Larena\Access\Runtime\AccessOperationAuthorizer::class)
            ? $app->make(\Larena\Access\Runtime\AccessOperationAuthorizer::class) : null;
        if (class_exists(\Larena\Admin\Alerts\AdminAlerts::class)) {
            $this->callAfterResolving(\Larena\Admin\Alerts\AdminAlerts::class, static function (\Larena\Admin\Alerts\AdminAlerts $alerts) use ($app, $authorizer): void {
                $alerts->register('larena/env-mail', new MailAlertSource($app->make(PanelMail::class), $app->make(MailLetters::class), $app->make(Translator::class), $authorizer()));
            });
        }
        if (interface_exists(\Larena\Admin\Contracts\AdminHistorySource::class)) {
            $this->callAfterResolving(\Larena\Admin\History\AdminHistory::class, static function (\Larena\Admin\History\AdminHistory $history) use ($app, $authorizer): void {
                $history->register('mail', new MailHistorySource($app->make(MailLetters::class), $app->make(Translator::class), $authorizer()));
            });
        }
    }

    /** The panel as its letters name it: its own address's host. */
    private static function panelName(Application $app): string
    {
        $host = parse_url((string) $app->make(Config::class)->get('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'Larena Panel';
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
