<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/** A zone the token sees, with what the token may do in it as the provider reports it. */
final readonly class DnsZone
{
    /** @param list<string> $permissions */
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public array $permissions = [],
    ) {
    }

    /** Whether the token may change records here; a provider that does not say is taken at its word when it refuses. */
    public function editable(): bool
    {
        return $this->permissions === [] || in_array('#dns_records:edit', $this->permissions, true);
    }

    /** Whether this zone holds a name: the name itself or one under it. */
    public function covers(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === $this->name || str_ends_with($host, '.'.$this->name);
    }
}
