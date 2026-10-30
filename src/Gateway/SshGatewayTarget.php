<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use InvalidArgumentException;

/**
 * Where the gateway answers over SSH. The credential is a path to a key on disk and never a value: the key stays in its
 * file, ssh reads it. The host's key must already be in the given known-hosts file, so a changed server is refused.
 */
final readonly class SshGatewayTarget
{
    private const string HOST_PATTERN = '/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/D';
    private const string USER_PATTERN = '/\A[a-z_][a-z0-9_-]{0,31}\z/D';

    public function __construct(
        public string $host,
        public string $user,
        public int $port,
        public string $keyPath,
        public string $knownHostsPath,
        public string $binary = '/usr/bin/ssh',
        public float $timeoutSeconds = 30.0,
    ) {
        if (preg_match(self::HOST_PATTERN, $host) !== 1 || preg_match(self::USER_PATTERN, $user) !== 1 || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('ssh_gateway_target_invalid');
        }
        foreach ([$keyPath, $knownHostsPath, $binary] as $path) {
            if (!str_starts_with($path, '/') || preg_match('/[\s\x00-\x1F\x7F]/', $path) === 1) {
                throw new InvalidArgumentException('ssh_gateway_target_invalid');
            }
        }
        if ($timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('ssh_gateway_target_invalid');
        }
    }
}
