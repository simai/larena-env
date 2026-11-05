<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/**
 * A program of the server (software:list, simai-software/1): whether it is there and in which version, the version the
 * system would update it to, its service and whether that runs. From simai-env 1.43.0 the list is a catalogue: each
 * row has its group, what it is for, and whether the server would install, update or remove it, with the reason when
 * it would not (a program simai-env needs, one the sites use, PHP kept on its own screen). An older server's row
 * (1.38.0) says only whether software:install adds it: node, redis and postgresql.
 */
final readonly class SoftwareComponent
{
    /** The programs a server before the catalogue names (simai-env 1.38.0). */
    public const array NAMES = ['nginx', 'apache', 'mysql', 'postgresql', 'redis', 'node', 'certbot'];

    /** The programs software:install adds on a server before the catalogue (redis takes no version). */
    public const array INSTALLABLE = ['node', 'redis', 'postgresql'];

    /** The programs software:install takes a major version for. */
    public const array VERSIONED = ['node', 'postgresql'];

    /**
     * @param list<string> $sites the sites that use it (node, apache, postgresql, mysql)
     * @param array{code: string, text: string, sites: list<string>, count: ?int}|null $installBlocked why it is not installed
     * @param array{code: string, text: string, sites: list<string>, count: ?int}|null $removeBlocked why it is not removed
     * @param list<string> $keeps the paths a removal leaves on the server
     */
    public function __construct(
        public string $name,
        public ?string $package,
        public bool $installed,
        public ?string $version,
        public ?string $candidate,
        public bool $update,
        public ?string $service,
        public ?bool $active,
        public bool $installable,
        public array $sites = [],
        public ?string $title = null,
        public ?string $purpose = null,
        public ?string $group = null,
        public bool $canUpdate = false,
        public bool $canRemove = false,
        public ?array $installBlocked = null,
        public ?array $removeBlocked = null,
        public array $keeps = [],
    ) {
    }

    /** The program simai-env needs itself: never installed or removed from the panel. */
    public function requiredByEnv(): bool
    {
        return ($this->removeBlocked['code'] ?? $this->installBlocked['code'] ?? null) === 'required_by_env';
    }

    /** PHP: its versions are on their own screen. */
    public function managedElsewhere(): bool
    {
        return ($this->installBlocked['code'] ?? $this->removeBlocked['code'] ?? null) === 'managed_elsewhere';
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['key'] ?? $data['name'] ?? null;
        if (!is_string($name) || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $name) !== 1) {
            return null;
        }
        $catalogue = array_key_exists('can_install', $data);
        // A server before the catalogue names its fixed list only.
        if (!$catalogue && !in_array($name, self::NAMES, true)) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $strings = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, static fn (mixed $item): bool => is_string($item) && $item !== '')) : [];
        $installed = ($data['installed'] ?? false) === true;

        return new self(
            name: $name,
            package: $text('package'),
            installed: $installed,
            version: $text('version'),
            candidate: $text('candidate'),
            update: $installed && ($data['update'] ?? false) === true,
            service: $text('service'),
            active: is_bool($data['active'] ?? null) ? $data['active'] : null,
            // What the server says it would add; before the catalogue, only what software:install took.
            installable: !$installed && ($catalogue ? ($data['can_install'] ?? false) === true
                : ($data['installable'] ?? false) === true && in_array($name, self::INSTALLABLE, true)),
            sites: $strings($data['sites'] ?? null),
            title: $text('title'),
            purpose: $text('purpose'),
            group: is_string($data['group'] ?? null) && preg_match('/\A[a-z]{2,24}\z/D', $data['group']) === 1 ? $data['group'] : null,
            canUpdate: $installed && ($data['can_update'] ?? false) === true,
            canRemove: $installed && ($data['can_remove'] ?? false) === true,
            installBlocked: self::blocked($data['install_blocked'] ?? null),
            removeBlocked: self::blocked($data['remove_blocked'] ?? null),
            keeps: $strings($data['keeps'] ?? null),
        );
    }

    /** @return array{code: string, text: string, sites: list<string>, count: ?int}|null */
    private static function blocked(mixed $blocked): ?array
    {
        if (!is_array($blocked) || !is_string($blocked['code'] ?? null) || preg_match('/\A[a-z_]{2,40}\z/D', $blocked['code']) !== 1) {
            return null;
        }
        $sites = is_array($blocked['sites'] ?? null) ? array_values(array_filter($blocked['sites'], static fn (mixed $site): bool => is_string($site) && $site !== '')) : [];

        return [
            'code' => $blocked['code'],
            'text' => is_string($blocked['text'] ?? null) ? $blocked['text'] : '',
            'sites' => $sites,
            'count' => is_int($blocked['count'] ?? null) ? $blocked['count'] : null,
        ];
    }
}
