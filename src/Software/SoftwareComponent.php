<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/**
 * A component of the server's software (software:list, simai-software/1, simai-env 1.38.0): nginx, the database
 * server, PostgreSQL, Redis, Node.js or certbot, whether it is there and in which version, the version the system
 * would update it to, its service and whether that runs, and whether software:install can add it.
 */
final readonly class SoftwareComponent
{
    public const array NAMES = ['nginx', 'mysql', 'postgresql', 'redis', 'node', 'certbot'];

    /** The components software:install adds (redis takes no version). */
    public const array INSTALLABLE = ['node', 'redis', 'postgresql'];

    /** @param list<string> $sites the sites with the node profile, for Node.js */
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
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['name'] ?? null;
        if (!in_array($name, self::NAMES, true)) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $installed = ($data['installed'] ?? false) === true;
        $sites = is_array($data['sites'] ?? null) ? array_values(array_filter($data['sites'], static fn (mixed $site): bool => is_string($site) && $site !== '')) : [];

        return new self(
            name: $name,
            package: $text('package'),
            installed: $installed,
            version: $text('version'),
            candidate: $text('candidate'),
            update: $installed && ($data['update'] ?? false) === true,
            service: $text('service'),
            active: is_bool($data['active'] ?? null) ? $data['active'] : null,
            // Only what software:install takes and the server does not have.
            installable: !$installed && ($data['installable'] ?? false) === true && in_array($name, self::INSTALLABLE, true),
            sites: $sites,
        );
    }
}
