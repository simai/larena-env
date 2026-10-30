<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/** The job a confirmed action started, or the reason code why it did not start. */
final readonly class JobStartResult
{
    public function __construct(
        public string $source,
        public ?string $jobId,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
