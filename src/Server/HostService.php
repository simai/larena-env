<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * A service of the server (service:list, simai-services/1, simai-env 1.33.0): whether it runs, whether it starts with
 * the server, since when, and whether it rereads its settings without a restart (nginx and PHP-FPM).
 */
final readonly class HostService
{
    public function __construct(
        public string $name,
        public string $active,
        public ?string $enabled,
        public ?string $since,
        public bool $canReload,
    ) {
    }

    public function isActive(): bool
    {
        return $this->active === 'active';
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['name'] ?? null;
        $active = $data['active'] ?? null;
        if (!is_string($name) || $name === '' || !is_string($active)) {
            return null;
        }

        return new self($name, $active, is_string($data['enabled'] ?? null) ? $data['enabled'] : null,
            is_string($data['since'] ?? null) && $data['since'] !== '' ? $data['since'] : null, ($data['can_reload'] ?? false) === true);
    }
}
