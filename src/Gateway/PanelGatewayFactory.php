<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Closure;
use InvalidArgumentException;
use Larena\Core\Process\ProcessExecutor;
use Larena\Env\Contracts\PanelGateway;

/**
 * Picks the driver from the configuration and fails closed: recorded answers only where the environment allows them, so
 * a production panel can never show demo sites as its server; an incomplete SSH target is no gateway at all.
 */
final class PanelGatewayFactory
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $settings the `larena-env.gateway` configuration
     * @param Closure(): ProcessExecutor $executor
     */
    public static function make(array $settings, bool $recordedAllowed, Closure $executor): PanelGateway
    {
        $driver = $settings['driver'] ?? null;
        if ($driver === 'recorded' && $recordedAllowed && is_string($settings['recorded']['path'] ?? null)) {
            return new RecordedAnswersGateway($settings['recorded']['path']);
        }
        $ssh = $settings['ssh'] ?? null;
        if ($driver === 'ssh' && is_array($ssh)) {
            try {
                return new SshGateway(new SshGatewayTarget(
                    host: (string) ($ssh['host'] ?? ''),
                    user: (string) ($ssh['user'] ?? ''),
                    port: (int) ($ssh['port'] ?? 22),
                    keyPath: (string) ($ssh['key_path'] ?? ''),
                    knownHostsPath: (string) ($ssh['known_hosts_path'] ?? ''),
                    binary: (string) ($ssh['binary'] ?? '/usr/bin/ssh'),
                    timeoutSeconds: (float) ($ssh['timeout_seconds'] ?? 30),
                ), $executor());
            } catch (InvalidArgumentException) {
                return new UnconfiguredGateway();
            }
        }

        return new UnconfiguredGateway();
    }
}
