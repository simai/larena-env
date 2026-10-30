<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** The overview, or the reason code why there is none. `refused` tells "the server said no" from "could not find out". */
final readonly class ServerOverviewResult
{
    public function __construct(
        public string $source,
        public ?ServerOverview $overview,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
