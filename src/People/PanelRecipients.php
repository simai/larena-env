<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Closure;
use Illuminate\Contracts\Container\Container;
use Larena\Access\Contracts\AccessSubjectDirectory;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\EffectiveAccessReader;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Auth\Contracts\AdminIdentityStore;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Who hears about the server (the bell, the mail, any channel after them): one list for all of them, by the rights each
 * person holds now (auditor msg-3d61bd36, point 1). The whole server's people are the administrators who are active and
 * still hold `env.server.read`; a site's are, besides them, the people tied to the site's server account who are active
 * and still hold `env.sites.read`.
 *
 * The list is worked out at the moment it is used. Where Auth and Access signal a change (a person removed, a role or a
 * right taken away), the provider also acts at once: a removed person's link is dropped (releaseNow) and a lost role
 * sweeps the links (sweep). The whole server's people are everyone who holds `env.server.read` now, custom roles
 * included (Access's holders()); an answer Access could not give in full tells nobody. A link «person — server account» of a person who is removed, or who no longer holds
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
     * @param (Closure(): list<string>)|null $administrators the people who may hold the whole server's right; null: no rights
     *                                                     to read; a closure that throws tells nobody (fail closed)
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
            $access === null ? null : self::administratorsOf($app, $access),
            $access === null ? null : static fn (string $subject, string $right): bool => $access->isAllowed($subject, $right),
            $state,
            $app->bound('db') ? $app->make(PersonOwners::class) : null,
            $app->bound(PanelAudit::class) ? $app->make(PanelAudit::class) : new PanelAudit($app),
        );
    }

    /**
     * The whole server's people as Access answers them: everyone who holds `env.server.read` now, custom roles included,
     * Auth's people only. An answer that is not resolved throws, so nobody is told; an answer cut at the maximum is used
     * and logged. An Access without holders() keeps the administrator role's people.
     *
     * @return Closure(): list<string>
     */
    private static function administratorsOf(Container $app, PersistentAccessStore $access): Closure
    {
        // An older larena/access has no holders(): its administrator role's people, as before.
        $answers = class_exists(EffectiveAccessReader::class) && method_exists(EffectiveAccessReader::class, 'holders'); // @phpstan-ignore function.alreadyNarrowedType
        $reader = $answers && $app->bound(EffectiveAccessReader::class) ? $app->make(EffectiveAccessReader::class) : null;
        if (!$reader instanceof EffectiveAccessReader) {
            return static fn (): array => $access->subjectsWithRole(SystemRolePresetSynchronizer::ADMINISTRATOR, 1000);
        }
        $log = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : ($app->bound('log') ? $app->make('log') : null);

        return static function () use ($reader, $log): array {
            $answer = $reader->holders(self::WHOLE_SERVER, null, EffectiveAccessReader::HOLDERS_MAX);
            if ($answer['reason_code'] !== 'access_holders_resolved') {
                throw new RuntimeException('env_recipients_unresolved: '.$answer['reason_code']);
            }
            if (!$answer['complete'] && $log instanceof LoggerInterface) {
                $log->warning('larena/env: the people holding env.server.read were cut at the maximum; only those listed are told.', [
                    'listed' => count($answer['subjects']),
                ]);
            }

            return array_values(array_filter($answer['subjects'],
                static fn (string $subject): bool => str_starts_with($subject, 'user:admin_identity:')));
        };
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
     * With $only, just that person's link is looked at: a role taken away from one person (Access's RoleRevoked).
     *
     * @return int how many links were dropped
     */
    public function sweep(?string $only = null): int
    {
        if (!$this->judges() || $this->owners === null) {
            return 0;
        }
        $links = $this->owners->all();
        if ($only !== null) {
            $links = isset($links[$only]) ? [$only => $links[$only]] : [];
        }
        $dropped = 0;
        foreach ($links as $subject => $owner) {
            $before = $this->owners->ownerOf($subject);
            $this->counts($subject, $owner);
            $dropped += $before !== null && $this->owners->ownerOf($subject) === null ? 1 : 0;
        }

        return $dropped;
    }

    /**
     * Drops a person's link at once, in the audit log, without asking anybody: Auth said the person is removed
     * (PersonDeleted). Nothing happens when the person has no link.
     *
     * @return bool whether a link was dropped
     */
    public function releaseNow(string $subject, string $reason = 'removed'): bool
    {
        $owner = $this->owners?->ownerOf($subject);
        if ($owner === null) {
            return false;
        }
        $this->drop($subject, $owner, in_array($reason, ['removed', 'right_revoked'], true) ? $reason : 'removed');

        return $this->owners->ownerOf($subject) === null;
    }

    /** @return list<string> */
    private function wholeServer(): array
    {
        if ($this->whole !== null) {
            return $this->whole;
        }
        try {
            $candidates = ($this->administrators ?? static fn (): array => [])();
        } catch (Throwable) {
            // Not answered in full now: nobody of the whole server is told rather than somebody who may not see it.
            return $this->whole = [];
        }
        $people = [];
        foreach ($candidates as $subject) {
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
