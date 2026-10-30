<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use InvalidArgumentException;

/** The gateway on this very server: the one program the panel's account may run as root, without arguments. */
final readonly class SudoGatewayTarget
{
    public const string SUDO = '/usr/bin/sudo';
    public const string GATE = '/usr/local/sbin/simai-panel-gate';

    public function __construct(
        public string $binary = self::SUDO,
        public string $gate = self::GATE,
        public float $timeoutSeconds = 30.0,
    ) {
        foreach ([$binary, $gate] as $path) {
            if (!str_starts_with($path, '/') || preg_match('/[\s\x00-\x1F\x7F]/', $path) === 1) {
                throw new InvalidArgumentException('sudo_gateway_target_invalid');
            }
        }
        if ($timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('sudo_gateway_target_invalid');
        }
    }
}
