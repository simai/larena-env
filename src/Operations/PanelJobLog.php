<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use UnexpectedValueException;

/** What a job has printed so far, and the cursor the next read starts from. */
final readonly class PanelJobLog
{
    /** @param list<string> $lines */
    public function __construct(
        public string $jobId,
        public ?JobState $state,
        public array $lines,
        public int $next,
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
        $next = $data['next'] ?? null;
        if (!is_string($jobId) || !is_int($next) || $next < 0) {
            throw new UnexpectedValueException('job_log_field_invalid');
        }
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (!is_string($line)) {
                throw new UnexpectedValueException('job_log_field_invalid');
            }
            $lines[] = $line;
        }

        return new self($jobId, is_string($data['state'] ?? null) ? JobState::tryFrom($data['state']) : null, $lines, $next);
    }
}
