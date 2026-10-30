<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** One site's data, or the reason code why there is none. `refused` tells "the server said no" from "could not find out". */
final readonly class SiteDetailsResult
{
    public function __construct(
        public string $source,
        public ?SiteDetails $site,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
