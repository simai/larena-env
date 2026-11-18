<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use Closure;
use Larena\Auth\Contracts\AuthSecurityEventSink;
use Larena\Auth\ValueObjects\AuthSecurityEvent;

/**
 * The sign-in package's security events pass through to where they were going; a completed password recovery also
 * queues «Ваш пароль изменён» for the person (owner decision C335). The letter is queued in the same database
 * transaction as the new password, so it exists exactly when the password changed.
 */
final readonly class PanelSecurityLetters implements AuthSecurityEventSink
{
    public const string RECOVERY_COMPLETED = 'auth.password_recovery.completed';

    /** @param Closure(): PasswordChangedLetter $letter made only when a password changed */
    public function __construct(
        private AuthSecurityEventSink $inner,
        private Closure $letter,
    ) {
    }

    public function record(AuthSecurityEvent $event): void
    {
        $this->inner->record($event);
        if ($event->type === self::RECOVERY_COMPLETED) {
            ($this->letter)()->queueFor($event->subject);
        }
    }
}
