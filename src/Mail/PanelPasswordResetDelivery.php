<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Larena\Auth\Contracts\PasswordResetDelivery;
use Larena\Auth\ValueObjects\PasswordResetDeliveryMessage;

/**
 * The sign-in package's postman in the panel (owner decision C335): a password reset link sent at once through the
 * panel's mail, never queued or kept (the link lives only in memory). Bound only while mail is on, so «Забыли пароль?»
 * is a real link exactly then. A letter that does not leave answers false: the sign-in package then makes the link
 * stop working. «История» → «Почта» records that it was sent and how it ended, without its text.
 */
final readonly class PanelPasswordResetDelivery implements PasswordResetDelivery
{
    /** @param Closure(string, string): string $resetUrl the absolute address of the reset page for a challenge and its secret */
    public function __construct(
        private PanelMail $mail,
        private PanelMailer $mailer,
        private Translator $translator,
        private Closure $resetUrl,
        private ?MailLetters $letters = null,
    ) {
    }

    public function deliver(PasswordResetDeliveryMessage $message): bool
    {
        $settings = $this->mail->settings();
        $password = $this->mail->isOn() ? $this->mail->password() : null;
        if ($settings === null || $password === null) {
            return false;
        }
        $minutes = (string) max(1, (int) round((($message->expiresAt->getTimestamp() - time()) / 60)));
        $text = $this->text('letters.reset.body', [
            'url' => ($this->resetUrl)($message->challengeRef, $message->resetSecret),
            'minutes' => $minutes,
        ]);

        $subject = $this->text('letters.reset.subject');
        $reason = $this->mailer->send($settings, $password, $message->email, $subject, $text);
        // «История» → «Почта»: that it went and how it ended, never its text or the link.
        $this->letters?->record('reset', $message->email, $subject, $reason);

        return $reason === null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['mail' => '[panel]'];
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::mail.'.$key, $replace);
    }
}
