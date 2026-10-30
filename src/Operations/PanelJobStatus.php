<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use UnexpectedValueException;

/** One started action: what it is, who started it and where it stands. */
final readonly class PanelJobStatus
{
    /** @param array<string, string> $args */
    public function __construct(
        public string $jobId,
        public JobState $state,
        public string $command,
        public array $args,
        public ?int $exitCode,
        public ?string $createdAt,
        public ?string $actor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $jobId = $data['job_id'] ?? null;
        $state = is_string($data['state'] ?? null) ? JobState::tryFrom($data['state']) : null;
        $command = $data['command'] ?? null;
        if (!is_string($jobId) || $state === null || !is_string($command)) {
            throw new UnexpectedValueException('job_field_invalid');
        }
        $args = [];
        foreach (is_array($data['args'] ?? null) ? $data['args'] : [] as $name => $value) {
            if (is_string($value)) {
                $args[(string) $name] = $value;
            }
        }
        $actor = is_array($data['actor'] ?? null) ? ($data['actor']['user'] ?? null) : null;

        return new self(
            jobId: $jobId,
            state: $state,
            command: $command,
            args: $args,
            exitCode: is_int($data['exit_code'] ?? null) ? $data['exit_code'] : null,
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
            actor: is_string($actor) ? $actor : null,
        );
    }
}
