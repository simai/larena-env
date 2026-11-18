<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Larena\Auth\Contracts\ActivationDelivery;
use Larena\Auth\ValueObjects\ActivationDeliveryMessage;

/**
 * The sign-in package's postman for invitations in the panel (owner decision C335, «Приглашение»): the letter with the
 * link «Задать пароль и войти» goes at once through the panel's mail, at most 10 seconds, and is never queued or kept
 * (the link lives only in memory). Who invites and what the person will be able to do come from the screen that asked
 * (InvitationLetter). A letter that does not leave answers false: the sign-in package then withdraws the invitation.
 * «История» → «Почта» records that it was sent, to whom and until when it is valid — never its text or the link.
 */
final readonly class PanelInvitationDelivery implements ActivationDelivery
{
    /** @param Closure(string, string): string $activationUrl the absolute address of the activation page for a challenge and its secret */
    public function __construct(
        private PanelMail $mail,
        private PanelMailer $mailer,
        private Translator $translator,
        private Closure $activationUrl,
        private InvitationLetter $letter,
        private string $panel,
        private ?MailLetters $letters = null,
    ) {
    }

    public function deliver(ActivationDeliveryMessage $message): bool
    {
        $settings = $this->mail->settings();
        $password = $this->mail->isOn() ? $this->mail->password() : null;
        if ($settings === null || $password === null) {
            return false;
        }
        $hours = (string) max(1, (int) round(($message->expiresAt->getTimestamp() - time()) / 3600));
        $subject = $this->text('letters.invitation.subject');
        $reason = $this->mailer->send($settings, $password, $message->email, $subject, $this->text('letters.invitation.body', [
            'inviter' => $this->letter->inviter ?? 'Larena Panel',
            'panel' => $this->panel,
            'level' => $this->letter->level ?? '',
            'hours' => $hours,
            'url' => ($this->activationUrl)($message->challengeRef, $message->activationSecret),
        ]));
        $this->letters?->record('invitation', $message->email, $subject, $reason, null, \DateTimeImmutable::createFromInterface($message->expiresAt));

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
