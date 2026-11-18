<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The panel's letters (owner decision C335, step 2). Ordinary letters (notifications, «Ваш пароль изменён») wait in the
 * panel's database and the panel's scheduler sends them each minute (`larena:env-mail`): a letter that does not leave is
 * tried again after 1, 5 and 30 minutes, then given up as «не доставлено», and the alert bar says «Почта не уходит».
 * A letter with a sign-in link (a password reset, an invitation) never waits here: it is sent at once and only recorded
 * afterwards, without its text. «История» → «Почта» reads the record: when, to whom (the address partly hidden), which
 * letter and how it ended — never its text or a link. A waiting letter's text is wiped once it is sent or given up.
 */
final readonly class MailLetters
{
    public const string TABLE = 'larena_env_mail_letters';

    public const string QUEUED = 'queued';
    public const string SENT = 'sent';
    public const string FAILED = 'failed';

    /** The letters with a sign-in link: sent at once, recorded without text, never queued. */
    public const array LINK_KINDS = ['reset', 'invitation'];

    /** Minutes to the next try after each failure; after the last one the letter is «не доставлено». */
    public const array RETRY_MINUTES = [1, 5, 30];

    /** How long the record is kept for «История», in days. */
    public const int KEEP_DAYS = 90;

    /** The most letters one run of the scheduler sends: a minute's worth, each waiting at most 10 seconds. */
    public const int BATCH = 5;

    /** Invitations: at most 20 an hour for the panel, and one address again only after 5 minutes (plan «Приглашение»). */
    public const int INVITATIONS_PER_HOUR = 20;
    public const int INVITATION_GAP_SECONDS = 300;

    /** Why a letter was given up without trying: mail was switched off meanwhile. */
    public const string REASON_OFF = 'off';

    public function __construct(private ?ConnectionInterface $connection)
    {
    }

    /** An ordinary letter into the queue; the scheduler sends it within a minute. */
    public function queue(string $kind, string $to, string $subject, string $body, ?string $subjectRef = null): void
    {
        if (in_array($kind, self::LINK_KINDS, true)) {
            // A sign-in link is never kept (the sign-in package's rule): such a letter is only recorded after sending.
            throw new MailFailed('link_not_queued');
        }
        $this->table()->insert([
            'kind' => $kind,
            'to_address' => mb_strtolower(mb_substr(trim($to), 0, 254)),
            'subject' => mb_substr($subject, 0, 200),
            'body' => $body,
            'state' => self::QUEUED,
            'attempts' => 0,
            'next_at' => self::stamp(self::now()),
            'subject_ref' => $subjectRef,
            'created_at' => self::stamp(self::now()),
        ]);
    }

    /**
     * A letter sent at once (a sign-in link, the test letter): only that it was sent and how it ended, never its text.
     *
     * @param ?string $reason why it did not leave; null when it left
     */
    public function record(string $kind, string $to, string $subject, ?string $reason, ?string $subjectRef = null, ?DateTimeImmutable $expiresAt = null): void
    {
        $now = self::stamp(self::now());
        try {
            $this->table()->insert([
                'kind' => $kind,
                'to_address' => mb_strtolower(mb_substr(trim($to), 0, 254)),
                'subject' => mb_substr($subject, 0, 200),
                'body' => null,
                'state' => $reason === null ? self::SENT : self::FAILED,
                'attempts' => 1,
                'next_at' => null,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 32),
                'subject_ref' => $subjectRef,
                'expires_at' => $expiresAt === null ? null : self::stamp($expiresAt),
                'created_at' => $now,
                'finished_at' => $now,
            ]);
        } catch (Throwable) {
            // The letter itself went (or not) already; a record that cannot be written changes nothing for the person.
        }
    }

    /**
     * Send the letters that are due, at most BATCH. Mail switched off meanwhile gives them up at once (nothing to send
     * with); a failure is tried again after 1, 5 and 30 minutes, then given up as «не доставлено».
     *
     * @return array{sent: int, retried: int, failed: int}
     */
    public function run(PanelMail $mail, PanelMailer $mailer, ?DateTimeImmutable $now = null): array
    {
        $now ??= self::now();
        $done = ['sent' => 0, 'retried' => 0, 'failed' => 0];
        $due = $this->table()->where('state', self::QUEUED)->where('next_at', '<=', self::stamp($now))->orderBy('id')->limit(self::BATCH)->get();
        if ($due->isEmpty()) {
            return $done;
        }
        $settings = $mail->settings();
        $password = $mail->isOn() ? $mail->password() : null;
        foreach ($due as $letter) {
            $id = (int) $letter->id;
            if ($settings === null || $password === null) {
                $this->finish($id, self::FAILED, self::REASON_OFF, $now);
                $done['failed']++;

                continue;
            }
            $reason = $mailer->send($settings, $password, (string) $letter->to_address, (string) $letter->subject, (string) $letter->body);
            $attempts = (int) $letter->attempts + 1;
            if ($reason === null) {
                $this->finish($id, self::SENT, null, $now, $attempts);
                $done['sent']++;
            } elseif ($attempts > count(self::RETRY_MINUTES)) {
                $this->finish($id, self::FAILED, $reason, $now, $attempts);
                $done['failed']++;
            } else {
                $this->table()->where('id', $id)->update([
                    'attempts' => $attempts,
                    'reason' => mb_substr($reason, 0, 32),
                    'next_at' => self::stamp($now->modify('+'.self::RETRY_MINUTES[$attempts - 1].' minutes')),
                ]);
                $done['retried']++;
            }
        }

        return $done;
    }

    /**
     * «Почта не уходит — проверьте настройки»: the last letter that ended was not delivered (mail switched off is not
     * that), within the record's days. The next letter that leaves, or a test letter, clears it.
     */
    public function failing(): bool
    {
        try {
            $last = $this->table()->whereIn('state', [self::SENT, self::FAILED])->where(static function ($query): void {
                $query->whereNull('reason')->orWhere('reason', '!=', self::REASON_OFF);
            })->orderByDesc('finished_at')->orderByDesc('id')->first();
        } catch (Throwable) {
            return false;
        }

        return is_object($last) && (string) $last->state === self::FAILED;
    }

    /**
     * The record for «История», newest first: no text, no link.
     *
     * @return list<array{id: int, kind: string, to: string, subject: string, state: string, reason: ?string, attempts: int, at: string, finished: ?string, next: ?string}>
     */
    public function recent(int $limit = 100): array
    {
        try {
            $rows = $this->table()->orderByDesc('id')->limit(max(1, min($limit, 500)))
                ->get(['id', 'kind', 'to_address', 'subject', 'state', 'reason', 'attempts', 'created_at', 'finished_at', 'next_at']);
        } catch (Throwable) {
            return [];
        }
        $letters = [];
        foreach ($rows as $row) {
            $letters[] = [
                'id' => (int) $row->id,
                'kind' => (string) $row->kind,
                'to' => (string) $row->to_address,
                'subject' => (string) $row->subject,
                'state' => (string) $row->state,
                'reason' => $row->reason === null ? null : (string) $row->reason,
                'attempts' => (int) $row->attempts,
                'at' => (string) $row->created_at,
                'finished' => $row->finished_at === null ? null : (string) $row->finished_at,
                'next' => $row->next_at === null ? null : (string) $row->next_at,
            ];
        }

        return $letters;
    }

    /** @return array{id: int, kind: string, to: string, subject: string, state: string, reason: ?string, attempts: int, at: string, finished: ?string, next: ?string}|null */
    public function one(int $id): ?array
    {
        foreach ($this->recent(500) as $letter) {
            if ($letter['id'] === $id) {
                return $letter;
            }
        }

        return null;
    }

    /**
     * Whether another invitation may go now: at most 20 an hour for the panel, and the same address again only after 5
     * minutes. Answers the reason (`invitations_hour`, `invitation_again`) or null.
     */
    public function invitationRefusal(string $to, ?DateTimeImmutable $now = null): ?string
    {
        $now ??= self::now();
        try {
            $hour = $this->table()->where('kind', 'invitation')->where('created_at', '>=', self::stamp($now->modify('-1 hour')))->count();
            $again = $this->table()->where('kind', 'invitation')->where('to_address', mb_strtolower($to))
                ->where('created_at', '>=', self::stamp($now->modify('-'.self::INVITATION_GAP_SECONDS.' seconds')))->exists();
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            $again => 'invitation_again',
            $hour >= self::INVITATIONS_PER_HOUR => 'invitations_hour',
            default => null,
        };
    }

    /** @return array<string, string> each invited address's last invitation letter that left: until when its link works, UTC */
    public function invitedUntil(): array
    {
        try {
            $rows = $this->table()->where('kind', 'invitation')->where('state', self::SENT)->whereNotNull('expires_at')
                ->orderBy('id')->get(['to_address', 'expires_at']);
        } catch (Throwable) {
            return [];
        }
        $until = [];
        foreach ($rows as $row) {
            $until[(string) $row->to_address] = (string) $row->expires_at;
        }

        return $until;
    }

    /** The record older than KEEP_DAYS goes. */
    public function prune(?DateTimeImmutable $now = null): int
    {
        $now ??= self::now();

        return $this->table()->where('state', '!=', self::QUEUED)->where('created_at', '<', self::stamp($now->modify('-'.self::KEEP_DAYS.' days')))->delete();
    }

    /** An address partly hidden for «История»: r***@mail.ru. */
    public static function masked(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($address, 0, 1).'***'.substr($address, $at);
    }

    private function finish(int $id, string $state, ?string $reason, DateTimeImmutable $now, ?int $attempts = null): void
    {
        $this->table()->where('id', $id)->update(['state' => $state, 'reason' => $reason === null ? null : mb_substr($reason, 0, 32), 'next_at' => null,
            'finished_at' => self::stamp($now), 'body' => null] + ($attempts === null ? [] : ['attempts' => $attempts]));
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        if ($this->connection === null) {
            throw new MailFailed('not_available');
        }

        return $this->connection->table(self::TABLE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
