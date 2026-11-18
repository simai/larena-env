<?php

declare(strict_types=1);

namespace Larena\Env\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Larena\Env\People\ConsolePasswordReset;
use Larena\Env\People\PanelAudit;
use Psr\Log\LoggerInterface;

/**
 * `php artisan larena:panel-reset-password` on the panel's server, in the panel's folder: a new password for a panel
 * administrator when the panel cannot send mail and there is nobody else to ask (auditor's addition to C335). The
 * password is asked twice from the keyboard, never taken as an argument, so it is neither in the shell's history nor
 * in the list of processes; the log and «История» say «пароль сброшен с консоли» without it.
 */
final class PanelResetPasswordCommand extends Command
{
    protected $signature = 'larena:panel-reset-password {email? : the administrator\'s sign-in address}';

    protected $description = 'Set a new password for a Larena Panel administrator from the server console (asked from the keyboard)';

    public function handle(Container $app): int
    {
        if (!$this->input->isInteractive()) {
            $this->error('Нужен ввод с клавиатуры: пароль спрашивается у вас, а не передаётся в команде.');

            return self::FAILURE;
        }
        $email = trim((string) ($this->argument('email') ?? ''));
        if ($email === '') {
            $email = trim((string) $this->ask('Адрес, с которым администратор входит в панель'));
        }
        $password = (string) $this->secret('Новый пароль (не короче 12 знаков, хотя бы один не латинская буква и не цифра)');
        if ($password !== (string) $this->secret('Повторите новый пароль')) {
            $this->error('Пароли не совпадают, ничего не изменено.');

            return self::FAILURE;
        }
        $connection = $app->make('db')->connection();
        $sessions = class_exists(\Larena\Auth\Services\AdminSessionInvalidationService::class) && $app->bound(\Larena\Auth\Services\AdminSessionInvalidationService::class)
            ? $app->make(\Larena\Auth\Services\AdminSessionInvalidationService::class) : null;
        $reset = new ConsolePasswordReset(
            $connection,
            $sessions === null ? null : static function (int $id) use ($sessions): void {
                $sessions->invalidateIdentity($id, 'console', 'credential_rotated', true);
            },
            $app->make(PanelAudit::class),
        );
        $result = $reset->reset($email, $password);
        if ($result !== 'reset') {
            $this->error(match ($result) {
                'not_found' => 'Администратора с таким адресом нет, ничего не изменено.',
                'blocked' => 'Этот администратор заблокирован — разблокировать его может другой администратор в панели.',
                'too_short' => 'Пароль короче 12 знаков, ничего не изменено.',
                'too_long' => 'Пароль длиннее 72 байт, ничего не изменено.',
                default => 'Пароль слишком простой: добавьте знак, который не латинская буква и не цифра. Ничего не изменено.',
            });

            return self::FAILURE;
        }
        if ($app->bound(LoggerInterface::class)) {
            $app->make(LoggerInterface::class)->notice('пароль сброшен с консоли', ['email_hash' => hash('sha256', mb_strtolower($email))]);
        }
        $this->info('Пароль сброшен с консоли. Все входы этого администратора завершены — войдите с новым паролем.');

        return self::SUCCESS;
    }
}
