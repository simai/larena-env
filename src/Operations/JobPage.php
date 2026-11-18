<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * One page of the server's jobs (job:list, simai-env 1.60.0): newest first, whether there are more and the job the next
 * page continues after («Ещё», `before`), and the counts over every job the person may see: how many run now (the
 * menu's mark of background actions) and how many failed since a moment.
 */
final readonly class JobPage
{
    /** @param array<string, PanelJobStatus> $jobs by id */
    public function __construct(
        public array $jobs,
        public bool $more = false,
        public ?string $nextBefore = null,
        public ?int $running = null,
        public ?int $failedSince = null,
    ) {
    }
}
