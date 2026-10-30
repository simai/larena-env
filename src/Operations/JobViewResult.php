<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * A job's screen: where it stands and what it has printed. The log can be missing while the status is there (the
 * gateway keeps them apart), so each carries its own reason.
 */
final readonly class JobViewResult
{
    public function __construct(
        public string $source,
        public ?PanelJobStatus $status,
        public ?PanelJobLog $log,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
