<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * «Ваш пароль изменён; если это были не вы — …» (owner decision C335, «Восстановление пароля»): after a password reset by
 * the link, or from the server's console, the person gets an ordinary letter through the panel's queue — it carries no
 * link, so it may wait and be tried again. Only while mail is on; a person without an address gets none.
 */
final readonly class PasswordChangedLetter
{
    public function __construct(
        private ?ConnectionInterface $connection,
        private PanelMail $mail,
        private MailLetters $letters,
        private Translator $translator,
        private string $panel,
    ) {
    }

    /** @param string $subjectRef the person, `user:admin_identity:<id>` */
    public function queueFor(string $subjectRef): bool
    {
        if ($this->connection === null || preg_match('/\Auser:admin_identity:(\d+)\z/D', $subjectRef, $found) !== 1 || !$this->mail->isOn()) {
            return false;
        }
        try {
            $email = $this->connection->table('larena_admin_identities')->where('id', (int) $found[1])->value('email');
            if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
            $this->letters->queue('password_changed', $email, $this->text('letters.password_changed.subject'),
                $this->text('letters.password_changed.body', ['panel' => $this->panel]), $subjectRef);
        } catch (Throwable) {
            // The password is changed either way; a letter that cannot be queued must not undo it.
            return false;
        }

        return true;
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::mail.'.$key, $replace);
    }
}
