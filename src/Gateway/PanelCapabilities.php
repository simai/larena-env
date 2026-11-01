<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * Which gateway commands this server allows, so a screen offers only what will not be refused. The server's own list
 * (`gate.commands` of its host description) is the answer when it gives one; otherwise the commands its simai-env
 * release has (PanelCommands' `since`). A command no release has yet is offered only when the server lists it.
 */
final readonly class PanelCapabilities
{
    /** @param list<string>|null $listed the server's own list of its gateway's commands, null when it gives none */
    public function __construct(private string $envVersion, private ?array $listed = null)
    {
    }

    /** @param array<array-key, mixed> $host a host description (simai-host/1) */
    public static function fromHostDescription(array $host): self
    {
        $version = is_array($host['simai_env'] ?? null) && is_string($host['simai_env']['version'] ?? null) ? $host['simai_env']['version'] : '0';
        $commands = is_array($host['gate'] ?? null) && is_array($host['gate']['commands'] ?? null) ? $host['gate']['commands'] : null;

        return new self($version, $commands === null ? null : array_values(array_filter($commands, 'is_string')));
    }

    /** No server read: only what every supported gateway has. */
    public static function none(): self
    {
        return new self('0', []);
    }

    /**
     * Whether the server searches a log itself (`match` of logs:nginx, simai-env 1.28.0, the release that also began
     * to list its gateway's commands).
     */
    public function searchesLogs(): bool
    {
        return $this->listed !== null || version_compare($this->envVersion, '1.28.0', '>=');
    }

    public function allows(string $command): bool
    {
        if (PanelCommands::kind($command) === null) {
            return false;
        }
        if ($this->listed !== null) {
            return in_array($command, $this->listed, true);
        }
        $since = PanelCommands::since($command);

        return $since !== null && version_compare($this->envVersion, $since, '>=');
    }
}
