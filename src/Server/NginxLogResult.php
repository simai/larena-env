<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** The last lines of a log, or the reason code why they cannot be shown. */
final readonly class NginxLogResult
{
    public function __construct(
        public string $source,
        public ?NginxLogTail $log,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
