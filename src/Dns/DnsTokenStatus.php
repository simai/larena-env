<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/** What the provider says of a token: whether it is active and until when. */
final readonly class DnsTokenStatus
{
    public function __construct(
        public bool $active,
        public string $status,
        public ?string $expiresOn = null,
    ) {
    }
}
