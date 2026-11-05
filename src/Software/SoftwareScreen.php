<?php

declare(strict_types=1);

namespace Larena\Env\Software;

use Larena\Env\Update\UpdatePlan;

/**
 * What «Программы и обновления» shows, worked out before the view (the panel's UX audit, after FastPanel's list of
 * programs): the programs as one table grouped by what they do, PHP as a row of it, and the updates of the panel,
 * simai-env and the system said in one short line of badges, their details folded under it.
 */
final readonly class SoftwareScreen
{
    /**
     * The table's groups, in order, with the programs a server before the catalogue (simai-env 1.38.0) puts in each;
     * a catalogue row carries its own group. `php` is the row that opens the PHP screen.
     */
    public const array GROUPS = [
        'web' => ['nginx', 'apache'],
        'security' => [],
        'databases' => ['mysql', 'postgresql'],
        'cache' => ['redis'],
        'languages' => ['php', 'node'],
        'tools' => [],
        'certificates' => ['certbot'],
    ];

    /** The filters of the table: every program, the installed ones, the ones to add, the ones with an update. */
    public const array FILTERS = ['all', 'installed', 'available', 'updates'];

    private function __construct()
    {
    }

    /**
     * The groups with their programs, in the table's order, the ones the filter and the search leave; a group left
     * empty is left out. PHP is a row of «languages»: the catalogue's own row, or one added for an older server.
     *
     * @param callable(SoftwareComponent): string $words what the search looks in besides the name (the row's words)
     * @return list<array{key: string, php: bool, components: list<SoftwareComponent>}>
     */
    public static function groups(ServerSoftware $software, bool $withPhp, string $filter = 'all', string $query = '', ?callable $words = null): array
    {
        $legacy = [];
        foreach (self::GROUPS as $key => $names) {
            foreach ($names as $name) {
                $legacy[$name] = $key;
            }
        }
        $query = mb_strtolower(trim($query));
        $byGroup = array_fill_keys(array_keys(self::GROUPS), []);
        foreach ($software->components as $component) {
            // PHP has its own row, which opens its screen.
            if ($component->managedElsewhere() || $component->name === 'php') {
                continue;
            }
            if (!self::passes($component, $filter) || ($query !== '' && !str_contains(mb_strtolower($component->name.' '.$component->title.' '.($words !== null ? $words($component) : '')), $query))) {
                continue;
            }
            $group = $component->group ?? $legacy[$component->name] ?? 'tools';
            $byGroup[$group][] = $component;
        }
        // PHP is installed and has no update of its own here: it shows under «all» and «installed», and in a search for it.
        $php = $withPhp && in_array($filter, ['all', 'installed'], true) && ($query === '' || str_contains('php', $query));
        $groups = [];
        foreach ($byGroup as $key => $components) {
            $withPhpRow = $php && $key === 'languages';
            if ($components !== [] || $withPhpRow) {
                $groups[] = ['key' => $key, 'php' => $withPhpRow, 'components' => $components];
            }
        }

        return $groups;
    }

    /** How many programs each filter leaves, for its label. @return array<string, int> */
    public static function counts(ServerSoftware $software): array
    {
        $counts = array_fill_keys(self::FILTERS, 0);
        foreach ($software->components as $component) {
            foreach (self::FILTERS as $filter) {
                $counts[$filter] += self::passes($component, $filter) ? 1 : 0;
            }
        }

        return $counts;
    }

    /**
     * Whether a row has a menu: something the administrator may do to it, or why the server would not remove it.
     *
     * @param array<string, bool> $allows
     */
    public static function hasMenu(SoftwareComponent $component, array $allows): bool
    {
        return (($allows['install'] ?? false) && $component->installable)
            || (($allows['upgrade'] ?? false) && $component->canUpdate)
            || (($allows['update'] ?? false) && $component->update)
            || (($allows['remove'] ?? false) && $component->canRemove)
            || ($component->installed && in_array($component->removeBlocked['code'] ?? null, ['used_by_sites', 'has_certificates'], true));
    }

    private static function passes(SoftwareComponent $component, string $filter): bool
    {
        return match ($filter) {
            'installed' => $component->installed,
            'available' => !$component->installed && $component->installable,
            'updates' => $component->update,
            default => true,
        };
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
     * The updates of the panel and simai-env worth a card: one waiting, refused or not read; one already current is
     * said by its badge alone.
     *
     * @param array<string, UpdatePlan|null> $plans
     * @return array<string, UpdatePlan|null>
     */
    public static function plansToShow(array $plans): array
    {
        return array_filter($plans, static fn (?UpdatePlan $update): bool => $update === null || $update->blocked !== null || $update->updateAvailable);
    }

    /**
     * A package's version as people say it: «1.24.0» for «1.24.0-2ubuntu7.19», «7.0.15» for «5:7.0.15-1ubuntu0.24.04.5»
     * (the full one stays in the cell's title).
     */
    public static function shortVersion(?string $version): string
    {
        if ($version === null || $version === '') {
            return '—';
        }
        $short = preg_replace('/\A[0-9]+:/', '', $version) ?? $version;
        $short = preg_split('/[-+~]/', $short)[0] ?? $short;

        return $short !== '' ? $short : $version;
    }
}
