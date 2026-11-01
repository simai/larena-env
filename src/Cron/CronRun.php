<?php

declare(strict_types=1);

namespace Larena\Env\Cron;

/**
 * One run of a site's scheduled job, as the server's wrapper keeps it (Panel plan 5.4): when it started and ended, its
 * exit code and the last lines it printed (the server keeps 50).
 */
final readonly class CronRun
{
    /** @param list<string> $output */
    public function __construct(
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $exitCode,
        public array $output = [],
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $started = $data['started_at'] ?? $data['at'] ?? null;
        if (!is_string($started)) {
            return null;
        }
        $output = [];
        foreach (is_array($data['output'] ?? null) ? $data['output'] : (is_array($data['lines'] ?? null) ? $data['lines'] : []) as $line) {
            if (is_string($line)) {
                $output[] = $line;
            }
        }

        return new self(
            $started,
            is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
            is_int($data['exit_code'] ?? null) ? $data['exit_code'] : null,
            array_slice($output, -50),
        );
    }
}
