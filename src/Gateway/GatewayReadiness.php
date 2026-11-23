<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * Whether the gateway can be asked at all, from its configuration alone, without asking it (auditor msg-3d61bd36,
 * point 5): the background jobs (larena:env-check, larena:env-warm) end at once, with no request and no write, while it
 * is not. Ready: recorded answers where the environment allows them and their folder is there; the sudo gate's program
 * on this server; an SSH target with its key and known hosts readable. Anything else is not ready, with a reason code.
 */
final readonly class GatewayReadiness
{
    public const string RECORDED_NOT_ALLOWED = 'gateway_recorded_not_allowed';
    public const string SUDO_GATE_MISSING = 'gateway_sudo_gate_missing';
    public const string SSH_TARGET_MISSING = 'gateway_ssh_target_missing';

    public function __construct(public ?string $reason = null)
    {
    }

    public function ready(): bool
    {
        return $this->reason === null;
    }

    /**
     * @param array<array-key, mixed> $settings the `larena-env.gateway` configuration, as PanelGatewayFactory reads it
     */
    public static function of(array $settings, bool $recordedAllowed): self
    {
        $driver = $settings['driver'] ?? null;
        $recorded = is_array($settings['recorded'] ?? null) ? $settings['recorded'] : [];
        $sudo = is_array($settings['sudo'] ?? null) ? $settings['sudo'] : [];
        $ssh = is_array($settings['ssh'] ?? null) ? $settings['ssh'] : [];

        return match ($driver) {
            'recorded' => $recordedAllowed && is_string($recorded['path'] ?? null) && is_dir($recorded['path'])
                ? new self() : new self(self::RECORDED_NOT_ALLOWED),
            'sudo' => self::file($sudo['gate'] ?? SudoGatewayTarget::GATE) && self::file($sudo['binary'] ?? SudoGatewayTarget::SUDO)
                ? new self() : new self(self::SUDO_GATE_MISSING),
            'ssh' => is_string($ssh['host'] ?? null) && $ssh['host'] !== '' && self::readable($ssh['key_path'] ?? null)
                && self::readable($ssh['known_hosts_path'] ?? null) && self::file($ssh['binary'] ?? '/usr/bin/ssh')
                ? new self() : new self(self::SSH_TARGET_MISSING),
            default => new self(PanelReason::NOT_CONFIGURED),
        };
    }

    private static function file(mixed $path): bool
    {
        return is_string($path) && str_starts_with($path, '/') && is_file($path);
    }

    private static function readable(mixed $path): bool
    {
        return is_string($path) && $path !== '' && is_readable($path);
    }
}
