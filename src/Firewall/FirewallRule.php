<?php

declare(strict_types=1);

namespace Larena\Env\Firewall;

/**
 * A rule of the server's firewall (ufw): its id, the port or range, the protocol, where from, and whether it lets in
 * or keeps out. SSH and the panel's own port are protected: no call may deny or remove them.
 */
final readonly class FirewallRule
{
    public function __construct(
        public string $id,
        public string $port,
        public string $proto,
        public string $from,
        public string $action,
        public bool $protected,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $port = $data['port'] ?? null;
        if ((!is_string($id) && !is_int($id)) || (!is_string($port) && !is_int($port))) {
            return null;
        }
        $port = (string) $port;

        return new self(
            (string) $id,
            $port,
            is_string($data['proto'] ?? null) ? $data['proto'] : 'any',
            is_string($data['from'] ?? null) ? $data['from'] : 'any',
            ($data['action'] ?? null) === 'deny' ? 'deny' : 'allow',
            // The server says which it protects; SSH's port is protected whatever an older answer says.
            ($data['protected'] ?? false) === true || $port === '22',
        );
    }
}
