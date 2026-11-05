<?php

declare(strict_types=1);

namespace Larena\Env\Software;

use Larena\Env\Update\UpdatePlan;

/**
 * What «Программы и обновления» shows, worked out before the view (the panel's UX audit, after FastPanel's list of
 * programs): the programs as one table grouped by what they do, PHP as a row of it, and the updates of the panel,
 * simai-env and the system said in one short line, opened by themselves only when something waits for the
 * administrator.
 */
final readonly class SoftwareScreen
{
    /** The table's groups, in order; `php` is the row that opens the PHP screen. */
    public const array GROUPS = [
        'web' => ['nginx', 'apache'],
        'databases' => ['mysql', 'postgresql'],
        'cache' => ['redis'],
        'languages' => ['php', 'node'],
        'certificates' => ['certbot'],
    ];

    private function __construct()
    {
    }

    /**
     * The groups with their components, in the table's order; a group the server says nothing of is left out.
     *
     * @return list<array{key: string, php: bool, components: list<SoftwareComponent>}>
     */
    public static function groups(ServerSoftware $software, bool $withPhp): array
    {
        $groups = [];
        $placed = [];
        foreach (self::GROUPS as $key => $names) {
            $components = [];
            foreach ($names as $name) {
                $component = $software->component($name);
                if ($component !== null) {
                    $components[] = $component;
                    $placed[] = $name;
                }
            }
            $php = $withPhp && in_array('php', $names, true);
            if ($components !== [] || $php) {
                $groups[] = ['key' => $key, 'php' => $php, 'components' => $components];
            }
        }
        // A component the groups do not name still has its row, at the end of the first group.
        $rest = array_values(array_filter($software->components, static fn (SoftwareComponent $component): bool => !in_array($component->name, $placed, true)));
        if ($rest !== [] && $groups !== []) {
            $groups[0]['components'] = [...$groups[0]['components'], ...$rest];
        }

        return $groups;
    }

    /**
     * PHP's row: its versions, how many of their PHP-FPM do not run, and whether a fix waits for one of them.
     *
     * @return array{versions: string, count: int, stopped: int, fixes: bool}|null
     */
    public static function php(?PhpVersions $php): ?array
    {
        if ($php === null) {
            return null;
        }
        $stopped = 0;
        $fixes = false;
        $names = [];
        foreach ($php->versions as $version) {
            $names[] = $version->version;
            $stopped += $version->fpmActive ? 0 : 1;
            $fixes = $fixes || $version->updates !== [];
        }

        return ['versions' => implode(', ', $names), 'count' => count($names), 'stopped' => $stopped, 'fixes' => $fixes];
    }

    /**
     * Each update's state: unknown (not read), blocked, available or current.
     *
     * @param array<string, UpdatePlan|null> $plans
     * @return array<string, string>
     */
    public static function updateStates(array $plans): array
    {
        return array_map(static fn (?UpdatePlan $update): string => $update === null ? 'unknown'
            : ($update->blocked !== null ? 'blocked' : ($update->updateAvailable ? 'available' : 'current')), $plans);
    }

    /**
     * Whether the updates open by themselves: a security fix, a reboot or an update of the panel or simai-env waits.
     *
     * @param array<string, string> $states
     */
    public static function updatesWaiting(?SystemUpdates $system, array $states): bool
    {
        return ($system !== null && ($system->security > 0 || $system->rebootRequired)) || in_array('available', $states, true);
    }
}
