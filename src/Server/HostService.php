<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * A service of the server (service:list, simai-services/1, simai-env 1.33.0): whether it runs, whether it starts with
 * the server, since when, and whether it rereads its settings without a restart (nginx and PHP-FPM). Since simai-env
 * 1.62.0 also whether sites cannot do without it (nginx, PHP-FPM, the database servers: `critical`) and whether it may be
 * stopped for a while (Redis, cron: `can_stop`); an older server says neither, and nothing is offered to stop.
 */
final readonly class HostService
{
    public function __construct(
        public string $name,
        public string $active,
        public ?string $enabled,
        public ?string $since,
        public bool $canReload,
        public bool $critical = false,
        public bool $canStop = false,
    ) {
    }

    /** Whether «Остановить» may be offered: the server says so, and never for a service sites cannot do without. */
    public function stoppable(): bool
    {
        return $this->canStop && !$this->critical && $this->isActive();
    }

    /** Whether «Запустить» may be offered: a service that does not run (stopped, failed or not used). */
    public function startable(): bool
    {
        return !$this->isActive();
    }

    public function isActive(): bool
    {
        return $this->active === 'active';
    }

    /**
     * Whether the service is simply not used (block 4; the panel's UX audit, DATA-02): off, not failed, and not started
     * with the server, as PostgreSQL or Redis on a fresh server. It is said grey, never as a stopped service in red.
     */
    public function isUnused(): bool
    {
        return !$this->isActive() && $this->active !== 'failed' && $this->enabled !== null && $this->enabled !== 'enabled';
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
            is_string($data['since'] ?? null) && $data['since'] !== '' ? $data['since'] : null, ($data['can_reload'] ?? false) === true,
            ($data['critical'] ?? false) === true, ($data['can_stop'] ?? false) === true);
    }

    /** A service as a person reads it: «Веб-сервер nginx», «PHP 8.3», else its name. */
    public static function label(string $name): string
    {
        $key = preg_match('/\Aphp([5-9]\.[0-9])-fpm\z/D', $name, $php) === 1 ? 'php' : strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $name));
        if (!function_exists('__')) {
            return $name;
        }
        $line = 'larena-env::admin.services.names.'.$key;
        $text = __($line, ['version' => $php[1] ?? '']);

        return $text !== $line ? $text : $name;
    }
}
