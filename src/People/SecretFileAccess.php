<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Illuminate\Database\ConnectionInterface;
use Larena\Env\Files\FileEntry;
use Larena\Env\Gateway\PanelActor;
use Throwable;

/**
 * Who may look at a site's files with passwords (owner decision 2026-11-18, q-4997ae4e93): a separate right. The whole
 * server has it; a person of the user level («свои сайты») only when it is switched on for them, by a tick on their
 * row of «Владельцы сайтов». Which files are such is FileEntry::secret, the one list that marks «секреты». Every open,
 * download and save of such a file is written to «История» (larena/audit): who, when, which file of which site, never
 * the file's text.
 */
final readonly class SecretFileAccess
{
    public const string TABLE = 'larena_env_secret_file_readers';
    /** The refusal said to a person without the right, the same line on every screen and address. */
    public const string REFUSED = 'secret_files_not_allowed';
    /** The subject of a file's event in «История»: `env.file:<domain>/<path>`, named by SecretFileAuditNames. */
    public const string SUBJECT = 'env.file:';

    public function __construct(private ?ConnectionInterface $connection = null, private ?PanelAudit $audit = null)
    {
    }

    /** Whether the name is a file with passwords, by the panel's one list. */
    public static function secret(string $path): bool
    {
        return FileEntry::secret(basename($path));
    }

    /**
     * Whether this actor may open, download and save files with passwords: the whole server always; a person of the
     * user level only when switched on for them. Someone looking at the panel as a person has that person's right.
     */
    public function allows(PanelActor $actor): bool
    {
        return $actor->owner === null || $this->granted($actor->user);
    }

    /** Whether the right is switched on for this person of the user level; a store that cannot be read says no. */
    public function granted(string $subjectRef): bool
    {
        if ($this->connection === null || $subjectRef === '') {
            return false;
        }
        try {
            return $this->connection->table(self::TABLE)->where('subject_ref', $subjectRef)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, true> the people it is switched on for */
    public function all(): array
    {
        if ($this->connection === null) {
            return [];
        }
        try {
            $people = [];
            foreach ($this->connection->table(self::TABLE)->pluck('subject_ref') as $subject) {
                $people[(string) $subject] = true;
            }

            return $people;
        } catch (Throwable) {
            return [];
        }
    }

    /** Switches the right on or off for a person; a change is written to «История», no change is not. */
    public function set(string $subjectRef, bool $on, string $by): void
    {
        if ($this->connection === null || $subjectRef === '' || strlen($subjectRef) > 191 || $on === $this->granted($subjectRef)) {
            return;
        }
        if ($on) {
            $this->connection->table(self::TABLE)->insert(['subject_ref' => $subjectRef, 'granted_by' => $by, 'granted_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->connection->table(self::TABLE)->where('subject_ref', $subjectRef)->delete();
        }
        $this->audit?->record($on ? 'env.person.secret_files_allowed' : 'env.person.secret_files_disallowed', $by, $subjectRef);
    }

    /** A file with passwords was shown in the panel. */
    public function opened(PanelActor $actor, string $domain, string $path): void
    {
        $this->record('env.secret_file.opened', $actor, $domain, $path);
    }

    /** A file with passwords left the server as a download. */
    public function downloaded(PanelActor $actor, string $domain, string $path): void
    {
        $this->record('env.secret_file.downloaded', $actor, $domain, $path);
    }

    /** A change of a file with passwords was confirmed and started. */
    public function saved(PanelActor $actor, string $domain, string $path): void
    {
        $this->record('env.secret_file.saved', $actor, $domain, $path);
    }

    /** Who did it (the person at the keyboard, also while looking as someone), the site and the file; never its text. */
    private function record(string $type, PanelActor $actor, string $domain, string $path): void
    {
        $payload = ['domain' => $domain, 'path' => $path];
        if ($actor->impersonatedBy !== null) {
            $payload['acting_as'] = $actor->user;
        }
        $this->audit?->record($type, $actor->impersonatedBy ?? $actor->user, mb_strcut(self::SUBJECT.$domain.'/'.$path, 0, 255, 'UTF-8'), $payload);
    }
}
