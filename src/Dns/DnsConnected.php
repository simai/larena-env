<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/** What the connection screen shows: never the token, only its last four characters. */
final readonly class DnsConnected
{
    public function __construct(
        public string $provider,
        public string $tokenTail,
        public string $tokenStatus,
        public ?string $tokenExpiresOn,
        public ?string $serverIpv4,
        public ?string $serverIpv6,
        public string $verifiedAt,
    ) {
    }

    public function hasAddress(): bool
    {
        return $this->serverIpv4 !== null || $this->serverIpv6 !== null;
    }
}
