<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The server's warnings by mail (owner decision C335, «Что слать на почту»): the panel's check queues a letter for each
 * new warning the chosen levels take — urgent ones by default, warnings too when ticked — to the same people its
 * notifications go to: the whole server's administrators, and a site's own people for their site. Only while mail is
 * on; a blocked person or one without an address gets none. Ordinary letters: they wait in the queue and are tried
 * again like any other.
 */
final readonly class WarningLetters
{
    public function __construct(
        private ?ConnectionInterface $connection,
        private PanelMail $mail,
        private MailLetters $letters,
    ) {
    }

    /** Whether a warning of this severity goes by mail now: mail is on and «Что слать» takes it. */
    public function takes(bool $urgent): bool
    {
        $settings = $this->mail->isOn() ? $this->mail->settings() : null;

        return $settings !== null && in_array($urgent ? 'urgent' : 'warnings', $settings->levels, true);
    }

    /**
     * Queue one letter per person with an address.
     *
     * @param list<string> $recipients the people's subject refs, `user:admin_identity:<id>`
     *
     * @return int letters queued
     */
    public function queue(array $recipients, string $subject, string $body): int
    {
        $queued = 0;
        foreach ($this->addresses($recipients) as $subjectRef => $email) {
            try {
                $this->letters->queue('warning', $email, $subject, $body, $subjectRef);
                $queued++;
            } catch (Throwable) {
                // One letter that cannot wait keeps neither the others nor the check from going on.
            }
        }

        return $queued;
    }

    /**
     * @param list<string> $recipients
     *
     * @return array<string, string> the active people's addresses by subject ref
     */
    private function addresses(array $recipients): array
    {
        $ids = [];
        foreach (array_unique($recipients) as $recipient) {
            if (preg_match('/\Auser:admin_identity:(\d+)\z/D', $recipient, $found) === 1) {
                $ids[(int) $found[1]] = $recipient;
            }
        }
        if ($ids === [] || $this->connection === null) {
            return [];
        }
        try {
            $rows = $this->connection->table('larena_admin_identities')->whereIn('id', array_keys($ids))
                ->whereIn('status', ['active', 'rotation_required'])->whereNull('disabled_at')->get(['id', 'email']);
        } catch (Throwable) {
            return [];
        }
        $addresses = [];
        foreach ($rows as $row) {
            $email = (string) $row->email;
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[$ids[(int) $row->id]] = $email;
            }
        }

        return $addresses;
    }
}
