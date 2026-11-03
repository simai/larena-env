<?php

declare(strict_types=1);

namespace Larena\Env\Firewall;

/**
 * A rule of the server's firewall (ufw, simai-env 1.35.0): its id, the port (none for a rule about an address as a
 * whole), the protocol, where from, and whether it lets in or keeps out, and the rule as ufw writes it. The server
 * protects SSH, 80 and 443 allowed for everyone: no call may remove them.
 */
final readonly class FirewallRule
{
    public function __construct(
        public string $id,
        public ?string $port,
        public string $proto,
        public string $from,
        public string $action,
        public bool $protected,
        public string $rule = '',
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $port = $data['port'] ?? null;
        if (!is_string($id) || ($port !== null && !is_string($port) && !is_int($port))) {
            return null;
        }
        $port = $port === null ? null : (string) $port;

        return new self(
            $id,
            $port,
            is_string($data['proto'] ?? null) ? $data['proto'] : 'any',
            is_string($data['from'] ?? null) ? $data['from'] : 'any',
            ($data['action'] ?? null) === 'deny' ? 'deny' : 'allow',
            // The server says which it protects; SSH's port is protected whatever an older answer says.
            ($data['protected'] ?? false) === true || $port === '22',
            is_string($data['rule'] ?? null) ? $data['rule'] : '',
        );
    }
}
