<?php

declare(strict_types=1);

namespace Larena\Env\Navigation;

use Illuminate\Contracts\Cache\Repository;
use Larena\Env\Console\PanelCheckCommand;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Server\ServerOverviewReader;
use Throwable;

/**
 * Whether the server lists the commands a menu row needs, so that a row shows only where its screen can do something
 * (the services, the server's software and PHP, the firewall, who reaches the panel). The menu is built on every page, so the answer is read from the
 * server's description once and kept for a few minutes in the application's cache; a server that does not answer
 * lists nothing, and the rows wait until it does. The panel's own check (larena:env-check, every five minutes) reads the
 * same description and keeps the answer here for longer than its five minutes, so a page does not ask the server for it a
 * second time (speed review 2026-11-16: «describe twice»).
 */
final class ListedCommands
{
    /** The commands the menu asks about. */
    public const array COMMANDS = ['service:list', 'software:list', 'php:list', 'firewall:status', 'security:status', 'backup:storage-list', 'panel:access'];
    private const string KEY = 'larena-env.listed-commands';
    /** Longer than the five minutes between two checks, which keep it fresh. */
    private const int SECONDS = 600;
    private const int UNANSWERED_SECONDS = 60;

    /** @var array<string, bool>|null */
    private ?array $listed = null;

    public function __construct(private readonly ServerOverviewReader $overviews, private readonly ?Repository $cache = null)
    {
    }

    public function allows(string $command): bool
    {
        return ($this->listed ??= $this->read())[$command] ?? false;
    }

    /** @return array<string, bool> */
    private function read(): array
    {
        try {
            $kept = $this->cache?->get(self::KEY);
            if (is_array($kept)) {
                return array_map(static fn (mixed $listed): bool => $listed === true, $kept);
            }
        } catch (Throwable) {
            // A cache that cannot be read is asked again below.
        }
        return self::keep($this->cache, $this->overviews->read(new PanelActor(PanelCheckCommand::ACTOR))->overview?->capabilities);
    }

    /**
     * Keeps what the server lists, from a description already read (the panel's check reads it anyway).
     *
     * @return array<string, bool>
     */
    public static function keep(?Repository $cache, ?PanelCapabilities $capabilities): array
    {
        $listed = [];
        foreach (self::COMMANDS as $command) {
            $listed[$command] = $capabilities?->allows($command) === true;
        }
        try {
            $cache?->put(self::KEY, $listed, $capabilities === null ? self::UNANSWERED_SECONDS : self::SECONDS);
        } catch (Throwable) {
            // Not kept: the next page asks the server again.
        }

        return $listed;
    }
}
