<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** A site's names, or the reason code why there are none. `refused` tells "the server said no" from "could not find out". */
final readonly class SiteNamesResult
{
    public function __construct(
        public ?SiteNames $names,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
