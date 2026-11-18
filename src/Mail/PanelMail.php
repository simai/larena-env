<?php

declare(strict_types=1);

namespace Larena\Env\Mail;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionInterface;
use Larena\Env\People\PanelAudit;
use Throwable;

/**
 * The panel's mail settings (owner decision C335), kept in the panel's own database: one row, the password encrypted
 * with the application's key and never shown again, so it is neither in `.env` nor in a copy made without the files
 * with passwords. Mail is on only after a test letter left with these very settings; a change of how letters leave
 * (the server, the login, the password, the sender) needs a test again. A panel restored on a server with another key
 * cannot open the password: mail switches itself off and asks for the password again instead of failing.
 * Every change goes to «История» as «изменил настройки почты», never with its values.
 */
final readonly class PanelMail
{
    public const string TABLE = 'larena_env_mail';

    /** Not set up yet. */
    public const string NONE = 'none';

    /** Saved, waiting for a test letter. */
    public const string UNCHECKED = 'unchecked';

    public const string ON = 'on';

    /** Switched off by a person; a test letter switches it on again. */
    public const string OFF = 'off';

    /** The application's key no longer opens the password (a panel restored elsewhere): enter it again. */
    public const string PASSWORD_LOST = 'password_lost';

    public function __construct(
        private ?ConnectionInterface $connection,
        private StringEncrypter $encrypter,
        private ?PanelAudit $audit = null,
    ) {
    }

    public function settings(): ?MailSettings
    {
        $row = $this->row();

        return $row === null ? null : MailSettings::fromRow($row);
    }

    /** Where mail stands now; a password the key cannot open is found here and switches mail off. */
    public function state(): string
    {
        $row = $this->row();
        if ($row === null) {
            return self::NONE;
        }
        $state = (string) $row->state;
        if ($state !== self::PASSWORD_LOST && $this->open($row) === null) {
            return self::PASSWORD_LOST;
        }

        return in_array($state, [self::UNCHECKED, self::ON, self::OFF, self::PASSWORD_LOST], true) ? $state : self::UNCHECKED;
    }

    public function isOn(): bool
    {
        return $this->state() === self::ON;
    }

    public function hasPassword(): bool
    {
        $row = $this->row();

        return $row !== null && is_string($row->password_encrypted) && $row->password_encrypted !== '' && (string) $row->state !== self::PASSWORD_LOST;
    }

    /** The password to sign in to the mail server with, only to send; null when there is none or the key cannot open it. */
    public function password(): ?string
    {
        $row = $this->row();

        return $row === null ? null : $this->open($row);
    }

    /** When the last test letter left, in UTC. */
    public function checkedAt(): ?string
    {
        $row = $this->row();

        return $row === null || $row->checked_at === null ? null : (string) $row->checked_at;
    }

    /**
     * Keep the settings and, when given, a new password. Returns the state after: a change of how letters leave waits for
     * a test letter again; a change of «Что слать» alone keeps mail as it was.
     *
     * @throws MailFailed password_required when there is no password to keep
     */
    public function save(MailSettings $settings, #[\SensitiveParameter] ?string $password, string $actor): string
    {
        $row = $this->row();
        $state = $row === null ? self::NONE : $this->state();
        $password = $password === null || $password === '' ? null : $password;
        if ($password === null && ($row === null || $state === self::PASSWORD_LOST || !$this->hasPassword())) {
            throw new MailFailed('password_required');
        }
        if ($password !== null && (strlen($password) > 500 || preg_match('/[\x00-\x1f\x7f]/', $password) === 1)) {
            throw new MailFailed('password_invalid');
        }
        $changed = $row === null || $password !== null || !MailSettings::fromRow($row)->sameDelivery($settings);
        $after = $changed ? self::UNCHECKED : $state;
        $values = $settings->toRow() + [
            'state' => $after,
            'updated_by' => $actor,
            'updated_at' => self::now(),
        ];
        if ($password !== null) {
            $values['password_encrypted'] = $this->encrypter->encryptString($password);
        }
        if ($changed) {
            $values['checked_at'] = null;
        }
        $this->table()->updateOrInsert(['id' => 1], $values);
        $this->audit?->record('env.mail.changed', $actor, 'env.mail:panel', $changed ? ['needs_test' => 'yes'] : []);

        return $after;
    }

    /** A test letter left with these settings: mail is on. */
    public function checked(string $actor): void
    {
        $this->table()->where('id', 1)->update(['state' => self::ON, 'checked_at' => self::now()]);
        $this->audit?->record('env.mail.checked', $actor, 'env.mail:panel');
    }

    public function switchOff(string $actor): void
    {
        $this->table()->where('id', 1)->update(['state' => self::OFF, 'updated_by' => $actor, 'updated_at' => self::now()]);
        $this->audit?->record('env.mail.switched_off', $actor, 'env.mail:panel');
    }

    private function open(object $row): ?string
    {
        $sealed = $row->password_encrypted ?? null;
        if (!is_string($sealed) || $sealed === '') {
            return null;
        }
        try {
            $password = $this->encrypter->decryptString($sealed);
        } catch (DecryptException) {
            $this->lost($row);

            return null;
        }

        return $password === '' ? null : $password;
    }

    /** The key does not open the password: mail goes off by itself and says why, once. */
    private function lost(object $row): void
    {
        if ((string) $row->state === self::PASSWORD_LOST) {
            return;
        }
        try {
            $this->table()->where('id', 1)->update(['state' => self::PASSWORD_LOST, 'checked_at' => null]);
        } catch (Throwable) {
            return;
        }
        $this->audit?->record('env.mail.password_lost', 'system', 'env.mail:panel');
    }

    private function row(): ?object
    {
        if ($this->connection === null) {
            return null;
        }
        try {
            $row = $this->table()->where('id', 1)->first();
        } catch (Throwable) {
            // Before the panel's migrations (a fresh install), there are no settings yet.
            return null;
        }

        return is_object($row) ? $row : null;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        if ($this->connection === null) {
            throw new MailFailed('not_available');
        }

        return $this->connection->table(self::TABLE);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
