<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteSummary;

/** The sites as the admin's shared data view, with rows in place: the page holds them already. A row opens the site's own screen. */
final class ServerSitesPage
{
    // What a person checks first (Panel plan 2.1): whether it works, what it runs, its certificate, its backups and whose
    // the site is. The state and the kind are choices: a coloured mark in the cell and a filter of the list
    // (owner-approved prototype, 2026-11-02). The list fits a laptop without scrolling sideways (the panel's UX audit,
    // LAY-04): the disk is on the site's own screen, and the owner shows only on a server where some site has one.
    private const array COLUMNS = ['domain', 'answers', 'profile', 'certificate', 'backups', 'owner'];

    /** The kinds a site can be, as the new-site screen names them. */
    private const array PROFILES = ['generic', 'laravel', 'wordpress', 'bitrix', 'static', 'proxy', 'node', 'redirect'];

    private function __construct()
    {
    }

    /** @param callable(string, array<string, string>=): string $text */
    public static function of(ServerOverview $overview, callable $text): AdminDataviewPage
    {
        return new AdminDataviewPage(
            instanceId: 'env-server-sites',
            title: $text('sites.title'),
            columns: array_map(static fn (string $key): array => ['key' => $key, 'label' => $text('sites.'.$key)] + match ($key) {
                'domain' => ['role' => 'title'],
                'answers' => ['type' => 'choice', 'options' => array_map(static fn (string $state, string $color): array => ['value' => $state, 'label' => $text('sites.state.'.$state), 'color' => $color],
                    array_keys(SiteState::COLORS), SiteState::COLORS)],
                'profile' => ['type' => 'choice', 'options' => array_map(static fn (string $profile): array => ['value' => $profile, 'label' => $text('new_site.profiles.'.$profile)], self::PROFILES)],
                default => [],
            }, self::columns($overview)),
            total: count($overview->sites),
            pageId: 'page.admin.env-server',
            rows: array_map(static fn (SiteSummary $site): array => self::row($site, $text), $overview->sites),
            perPage: 100,
            rowLinks: ['view' => '/admin/server/sites/__ID__'],
            countLabel: $text('sites.count'),
            tableViewLabel: $text('sites.table_view'),
        );
    }

    /** @return list<string> */
    private static function columns(ServerOverview $overview): array
    {
        foreach ($overview->sites as $site) {
            if ($site->owner !== null) {
                return self::COLUMNS;
            }
        }

        return array_values(array_diff(self::COLUMNS, ['owner']));
    }

    /**
     * @param callable(string, array<string, string>=): string $text
     * @return array<string, string>
     */
    private static function row(SiteSummary $site, callable $text): array
    {
        return [
            'id' => $site->domain,
            'domain' => $site->domain,
            'owner' => $site->owner ?? '—',
            'profile' => $site->profile,
            'certificate' => $site->certificateDaysLeft !== null
                ? $text($site->certificateDaysLeft <= 14 ? 'sites.certificate_ends' : 'sites.certificate_left', ['days' => (string) $site->certificateDaysLeft])
                : ($site->ssl === 'none' ? $text('sites.certificate_none') : $site->ssl),
            'backups' => $site->lastBackup !== null
                ? \Larena\Env\Server\Moment::short($site->lastBackup).($site->backupSchedule ? ' · '.$text('sites.backups_scheduled') : '')
                : $text($site->backupSchedule ? 'sites.backups_scheduled' : 'sites.backups_unscheduled'),
            'answers' => SiteState::of($site->answersChecked, $site->answers),
        ];
    }
}
