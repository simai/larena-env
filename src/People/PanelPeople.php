<?php

declare(strict_types=1);

namespace Larena\Env\People;

use Larena\Access\Administration\AdminUserManagementService;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Auth\Contracts\AdminIdentityStore;
use Larena\Env\Server\ServerOverview;
use Throwable;

/**
 * The panel's people as «Люди» shows them (roles study part 7, PX-67…PX-70, PX-79): every person who may sign in, what
 * they may do in the panel's two levels (owner decision 2026-10-31) — the whole server, or their own sites through the
 * server account they are tied to — their sites, whether they are blocked, when they last signed in and whether they may
 * view files with passwords (q-4997ae4e93). The level is the person's role: the administrator is the whole server, the
 * editor works in their own sites, a reader only looks. Read through Access's user service and Auth's identity store,
 * never their tables.
 */
final readonly class PanelPeople
{
    public const string ALL = 'all';
    public const string OWN = 'own';
    public const string READ = 'read';

    /** The panel's level of a role: its two levels, and a reader who only looks. */
    public const array LEVELS = [SystemRolePresetSynchronizer::ADMINISTRATOR => self::ALL, SystemRolePresetSynchronizer::EDITOR => self::OWN,
        SystemRolePresetSynchronizer::READER => self::READ];

    public function __construct(
        private AdminUserManagementService $users,
        private PersonOwners $owners,
        private ?SecretFileAccess $secrets = null,
        private ?AdminIdentityStore $identities = null,
    ) {
    }

    /**
     * Every person, sorted by name, as rows: id, subject, name, email, level, blocked, owner (their server account), sites
     * (the domains of that account), last (ISO time of the last sign-in or null), secret (may view files with passwords).
     *
     * @return list<array{id: int, subject: string, name: string, email: string, level: string, blocked: bool, owner: ?string, sites: list<string>, last: ?string, secret: bool, pending: bool, until?: ?string}>
     */
    public function all(string $actor, ?ServerOverview $overview): array
    {
        $people = $this->users->users($actor);
        $assigned = $this->owners->all();
        $secret = $this->secrets?->all() ?? [];
        $last = [];
        try {
            $last = $this->identities?->lastSignIns(array_map(static fn (array $user): int => (int) $user['id'], $people)) ?? [];
        } catch (Throwable) {
            $last = [];
        }
        $sites = self::sitesByOwner($overview);
        $rows = [];
        foreach ($people as $user) {
            $subject = (string) $user['subject_ref'];
            $level = self::LEVELS[(string) ($user['role_code'] ?? '')] ?? self::READ;
            $owner = $level === self::ALL ? null : ($assigned[$subject] ?? null);
            $rows[] = [
                'id' => (int) $user['id'],
                'subject' => $subject,
                'name' => (string) $user['display_name'],
                'email' => (string) $user['email'],
                'level' => $level,
                'blocked' => in_array((string) $user['status'], ['disabled', 'revoked'], true) || ($owner !== null && in_array($owner, $overview->blockedOwners ?? [], true)),
                'owner' => $owner,
                'sites' => $owner === null ? [] : ($sites[$owner] ?? []),
                'last' => $last[(int) $user['id']] ?? null,
                // The whole server always may (SecretFileAccess::allows); a person of «свои сайты» when it is ticked.
                'secret' => $level === self::ALL || isset($secret[$subject]),
                // Invited by mail and not answered yet (C335): no password of their own, no level until they accept.
                'pending' => (string) $user['status'] === 'pending_activation',
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * One person, or null when there is no such person.
     *
     * @return array{id: int, subject: string, name: string, email: string, level: string, blocked: bool, owner: ?string, sites: list<string>, last: ?string, secret: bool, pending: bool, until?: ?string}|null
     */
    public function one(int $id, string $actor, ?ServerOverview $overview): ?array
    {
        foreach ($this->all($actor, $overview) as $person) {
            if ($person['id'] === $id) {
                return $person;
            }
        }

        return null;
    }

    /** @return array<string, list<string>> each server account's domains, by name */
    public static function sitesByOwner(?ServerOverview $overview): array
    {
        $sites = [];
        foreach ($overview->sites ?? [] as $site) {
            if ($site->owner !== null) {
                $sites[$site->owner][] = $site->domain;
            }
        }
        foreach ($sites as &$domains) {
            sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $sites;
    }

    /** The role a level gives. */
    public static function roleOf(string $level): string
    {
        return $level === self::ALL ? SystemRolePresetSynchronizer::ADMINISTRATOR : SystemRolePresetSynchronizer::EDITOR;
    }
}
