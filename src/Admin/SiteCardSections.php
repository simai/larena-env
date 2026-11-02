<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\Http\Controllers\SiteAdminController;
use Larena\Env\Warnings\PanelWarning;

/**
 * The card's sections as the side navigation draws them: in groups, only the ones the person has, each marked when
 * something on it needs attention (the worst of its warnings).
 */
final class SiteCardSections
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $tabs the sections the person has
     * @param list<PanelWarning>|null $warnings the site's warnings
     *
     * @return list<array{group: string, items: list<array{name: string, attention: ?string}>}>
     */
    public static function of(array $tabs, ?array $warnings): array
    {
        $attention = [];
        foreach ($warnings ?? [] as $warning) {
            $tab = $warning->tab();
            $attention[$tab] = ($attention[$tab] ?? null) === PanelWarning::ERROR ? PanelWarning::ERROR : $warning->severity;
        }
        $groups = [];
        foreach (SiteAdminController::GROUPS as $group => $members) {
            $items = [];
            foreach ($members as $name) {
                if (in_array($name, $tabs, true)) {
                    $items[] = ['name' => $name, 'attention' => $attention[$name] ?? null];
                }
            }
            if ($items !== []) {
                $groups[] = ['group' => $group, 'items' => $items];
            }
        }

        return $groups;
    }
}
