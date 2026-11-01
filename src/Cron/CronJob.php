<?php

declare(strict_types=1);

namespace Larena\Env\Cron;

/**
 * A site's own scheduled job (Panel plan 5.4): its id, its schedule (five cron fields), the one line it runs as the
 * site's user in the site's folder, whether it is on, and its last run.
 */
final readonly class CronJob
{
    public function __construct(
        public string $id,
        public string $schedule,
        public string $command,
        public bool $enabled,
        public ?CronRun $lastRun,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $schedule = $data['schedule'] ?? null;
        $command = $data['command'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $id) !== 1 || !is_string($schedule) || !is_string($command)) {
            return null;
        }

        return new self($id, $schedule, $command, ($data['enabled'] ?? true) === true,
            is_array($data['last_run'] ?? null) ? CronRun::fromData($data['last_run']) : null);
    }
}
