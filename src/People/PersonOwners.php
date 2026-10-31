<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Illuminate\Database\ConnectionInterface;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;

/**
 * Which owner of the server a person of the panel works as (owner decision 2026-10-31: staff get their own space and
 * do not get in each other's way). A person with no owner here sees no site; the gateway narrows every answer to the
 * owner, so what this table says is the most a person can reach, never more.
 */
final readonly class PersonOwners
{
    public const string TABLE = 'larena_env_person_owners';
    private const string OWNER_PATTERN = '/\A[a-z][a-z0-9_-]{0,31}\z/D';

    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function ownerOf(string $subjectRef): ?string
    {
        $owner = $this->connection->table(self::TABLE)->where('subject_ref', $subjectRef)->value('owner');

        return is_string($owner) ? $owner : null;
    }

    /** @return array<string, string> person => owner */
    public function all(): array
    {
        $owners = [];
        foreach ($this->connection->table(self::TABLE)->orderBy('subject_ref')->get(['subject_ref', 'owner']) as $row) {
            $owners[(string) $row->subject_ref] = (string) $row->owner;
        }

        return $owners;
    }

    public function assign(string $subjectRef, string $owner, string $by): void
    {
        if (preg_match(self::OWNER_PATTERN, $owner) !== 1 || $subjectRef === '' || strlen($subjectRef) > 191) {
            throw new PanelRefused(PanelReason::INVALID_ARGUMENT);
        }
        $this->connection->table(self::TABLE)->updateOrInsert(
            ['subject_ref' => $subjectRef],
            ['owner' => $owner, 'assigned_by' => $by, 'assigned_at' => date('Y-m-d H:i:s')],
        );
    }

    public function release(string $subjectRef): void
    {
        $this->connection->table(self::TABLE)->where('subject_ref', $subjectRef)->delete();
    }
}
