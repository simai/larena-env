<?php

declare(strict_types=1);

// The panel's main scope is organization:server (owner decision 21); registering it twice changes nothing.

use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Contracts\ScopeRegistry;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\ScopeStatus;
use Larena\Core\Scope\ScopeRef;
use Larena\Env\Area\ServerArea;
use Larena\Env\Area\ServerAreaRegistrar;

require_once dirname(__DIR__).'/Support/env-test.php';

$scopes = new class implements ScopeRegistry {
    /** @var array<string, ScopeRecord> */
    public array $records = [];

    public int $created = 0;

    public function create(ScopeRef $ref, string $name, string $createdBy, ?ScopeRef $parentRef = null, ?string $correlationId = null): ScopeRecord
    {
        $this->created++;

        return $this->records[$ref->toString()] = new ScopeRecord($ref, $ref->kind, $parentRef, $name, ScopeStatus::Active, $createdBy, $correlationId);
    }

    public function read(ScopeRef $ref): ?ScopeRecord
    {
        return $this->records[$ref->toString()] ?? null;
    }

    public function list(?ScopeKind $kind = null, bool $includeArchived = false): array
    {
        return array_values($this->records);
    }

    public function archive(ScopeRef $ref, string $actorId, ?string $correlationId = null): ScopeRecord
    {
        $record = $this->records[$ref->toString()];

        return $this->records[$ref->toString()] = new ScopeRecord($ref, $ref->kind, null, $record->name, ScopeStatus::Archived, $record->createdBy);
    }

    public function explain(ScopeRef $ref): array
    {
        return [];
    }
};

env_assert(ServerArea::ref()->toString() === 'organization:server', 'The scope is organization:server.');
env_assert(ServerArea::ref()->kind === ScopeKind::Organization && ScopeRef::parse(ServerArea::SCOPE_REF)->equals(ServerArea::ref()), 'Core reads the reference.');

$registrar = new ServerAreaRegistrar($scopes);
env_assert($registrar->ensure('c1') === 'applied', 'The first run registers.');
$record = $scopes->read(ServerArea::ref());
env_assert($record !== null && $record->name === 'Server' && $record->parentRef === null && $record->createdBy === ServerAreaRegistrar::ACTOR && $record->correlationId === 'c1', 'The record.');
env_assert($registrar->ensure() === 'already_applied' && $scopes->created === 1, 'A second run changes nothing.');

$scopes->archive(ServerArea::ref(), 'someone');
env_assert($registrar->ensure() === 'archived' && $scopes->created === 1, 'An archived scope is reported, not recreated.');

$declaration = ServerArea::firstRunDeclaration();
env_assert($declaration['root_scope'] === 'organization:server' && !in_array('site', $declaration['steps'], true), 'The assembly\'s first run has no site step and the server as its root.');

echo "ServerAreaTest passed.\n";
