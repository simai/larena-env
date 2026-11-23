<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Closure;
use Illuminate\Contracts\Container\Container;
use Larena\Access\Contracts\AccessSubjectDirectory;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Auth\Contracts\AdminIdentityStore;
use Throwable;

/**
 * Who hears about the server (the bell, the mail, any channel after them): one list for all of them, by the rights each
 * person holds now (auditor msg-3d61bd36, point 1). The whole server's people are the administrators who are active and
 * still hold `env.server.read`; a site's are, besides them, the people tied to the site's server account who are active
 * and still hold `env.sites.read`.
 *
 * Access and Auth say nothing when a person is blocked, loses a role or is removed, so the list is worked out at the
 * moment it is used. A link «person — server account» of a person who is removed, or who no longer holds
 * `env.sites.read`, is dropped here and the drop goes to the audit log (`env.person.owner_released`, reason `removed` or
 * `right_revoked`). A blocked person keeps the link — unblocking gives it back as it was — and is simply not told.
 * Without Access's rights or Auth's people the list is empty: nobody is told rather than somebody who may not see it.
 */
final class PanelRecipients
{
    /** Who drops a link that no longer holds, in the audit log. */
    public const string ACTOR = 'panel:env-check';

    public const string ACTIVE = 'active';
    public const string BLOCKED = 'blocked';
    public const string GONE = 'gone';

    public const string WHOLE_SERVER = 'env.server.read';
    public const string OWN_SITES = 'env.sites.read';

    /** @var array<string, string> what each person is now, asked once per list */
    private array $states = [];

    /** @var list<string>|null */
    private ?array $whole = null;

    /**
     * @param (Closure(): list<string>)|null $administrators the people of the administrator role; null: no rights to read
     * @param (Closure(string, string): bool)|null $allowed whether a person holds a right now
     * @param (Closure(string): string)|null $state ACTIVE, BLOCKED or GONE
     */
    public function __construct(
        private readonly ?Closure $administrators,
        private readonly ?Closure $allowed,
        private readonly ?Closure $state,
        private readonly ?PersonOwners $owners,
        private readonly ?PanelAudit $audit,
    ) {
    }

    /** The application's own: Access's rights and roles, Auth's people, the panel's links and its audit. */
    public static function from(Container $app): self
    {
        $access = $app->bound(PersistentAccessStore::class) ? $app->make(PersistentAccessStore::class) : null;
        if ($access instanceof PersistentAccessStore && !$access->schemaAvailable()) {
            $access = null;
        }
        $identities = interface_exists(AdminIdentityStore::class) && $app->bound(AdminIdentityStore::class) ? $app->make(AdminIdentityStore::class) : null;
        $directory = $app->bound(AccessSubjectDirectory::class) ? $app->make(AccessSubjectDirectory::class) : null;
        $state = match (true) {
            $identities instanceof AdminIdentityStore => static fn (string $subject): string => self::identityState($identities, $subject),
            $directory instanceof AccessSubjectDirectory => static fn (string $subject): string => !$directory->exists($subject) ? self::GONE
                : ($directory->isActive($subject) ? self::ACTIVE : self::BLOCKED),
            default => null,
        };

        return new self(
            $access === null ? null : static fn (): array => $access->subjectsWithRole(SystemRolePresetSynchronizer::ADMINISTRATOR, 1000),
            $access === null ? null : static fn (string $subject, string $right): bool => $access->isAllowed($subject, $right),
            $state,
            $app->bound('db') ? $app->make(PersonOwners::class) : null,
            $app->bound(PanelAudit::class) ? $app->make(PanelAudit::class) : new PanelAudit($app),
        );
    }

    /** Whether the list can be worked out at all: rights and people are both readable. */
    public function judges(): bool
    {
        return $this->administrators !== null && $this->allowed !== null && $this->state !== null;
    }

    /**
     * Who is told about a warning: the whole server's people, and the people of the site's server account when it has one.
     *
     * @return list<string>
     */
    public function of(?string $siteOwner): array
    {
        if (!$this->judges()) {
            return [];
        }
        $people = $this->wholeServer();
        if ($siteOwner !== null && $this->owners !== null) {
            foreach ($this->owners->all() as $subject => $owner) {
                if ($owner === $siteOwner && $this->counts($subject, $owner)) {
                    $people[] = $subject;
                }
            }
        }

        return array_values(array_unique($people));
    }

    /**
     * Goes through every link «person — server account» once and drops the ones that no longer hold, each in the audit
     * log; the panel's check does it on every run, so a removed person loses the link even when nothing is to be told.
     *
     * @return int how many links were dropped
     */
    public function sweep(): int
    {
        if (!$this->judges() || $this->owners === null) {
            return 0;
        }
        $dropped = 0;
        foreach ($this->owners->all() as $subject => $owner) {
            $before = $this->owners->ownerOf($subject);
            $this->counts($subject, $owner);
            $dropped += $before !== null && $this->owners->ownerOf($subject) === null ? 1 : 0;
        }

        return $dropped;
    }

    /** @return list<string> */
    private function wholeServer(): array
    {
        if ($this->whole !== null) {
            return $this->whole;
        }
        $people = [];
        foreach (($this->administrators ?? static fn (): array => [])() as $subject) {
            if ($this->stateOf($subject) === self::ACTIVE && $this->holds($subject, self::WHOLE_SERVER) === true) {
                $people[] = $subject;
            }
        }

        return $this->whole = $people;
    }

    /** Whether a person tied to a server account is told; a link that no longer holds is dropped on the way. */
    private function counts(string $subject, string $owner): bool
    {
        $state = $this->stateOf($subject);
        if ($state === self::GONE) {
            $this->drop($subject, $owner, 'removed');

            return false;
        }
        $holds = $this->holds($subject, self::OWN_SITES);
        if ($holds === false) {
            $this->drop($subject, $owner, 'right_revoked');
        }
        if ($holds !== true) {
            return false;
        }

        return $state === self::ACTIVE;
    }

    private function drop(string $subject, string $owner, string $reason): void
    {
        if ($this->owners === null || $this->owners->ownerOf($subject) !== $owner) {
            return;
        }
        $this->owners->release($subject);
        $this->audit?->record('env.person.owner_released', self::ACTOR, $subject, ['owner' => $owner, 'reason' => $reason]);
    }

    private function stateOf(string $subject): string
    {
        if (isset($this->states[$subject])) {
            return $this->states[$subject];
        }
        try {
            $state = ($this->state ?? static fn (): string => self::BLOCKED)($subject);
        } catch (Throwable) {
            // Not readable now: not told, and nothing is dropped on a guess.
            $state = self::BLOCKED;
        }

        return $this->states[$subject] = in_array($state, [self::ACTIVE, self::BLOCKED, self::GONE], true) ? $state : self::BLOCKED;
    }

    /** Whether a person holds a right now; null when it cannot be read, which tells nobody and drops nothing. */
    private function holds(string $subject, string $right): ?bool
    {
        try {
            return ($this->allowed ?? static fn (): ?bool => null)($subject, $right);
        } catch (Throwable) {
            return null;
        }
    }

    /** A person of Auth: active, blocked (disabled, suspended, not accepted yet), or removed (revoked, or not there). */
    private static function identityState(AdminIdentityStore $identities, string $subject): string
    {
        if (preg_match('/\Auser:admin_identity:([1-9][0-9]*)\z/D', $subject, $matches) !== 1) {
            // Not one of Auth's people: never told, and the panel does not judge it removed.
            return self::BLOCKED;
        }
        $id = (int) $matches[1];
        $identity = $identities->find($id);
        if ($identity === null || (string) ($identity->status ?? '') === 'revoked') {
            return self::GONE;
        }

        return $identities->isActive($id) ? self::ACTIVE : self::BLOCKED;
    }
}
