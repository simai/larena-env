<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteSummary;

/** The sites as the admin's shared data view, with rows in place: the page holds them already. A row opens the site's own screen. */
final class ServerSitesPage
{
    // What a person checks first (Panel plan 2.1): whose the site is, what it runs, its certificate and its backups.
    // Whether it answers and the disk it takes come when the gateway reports them (asked of simai-env).
    private const array COLUMNS = ['domain', 'owner', 'profile', 'php', 'certificate', 'backups'];

    private function __construct()
    {
    }

    /** @param callable(string, array<string, string>=): string $text */
    public static function of(ServerOverview $overview, callable $text): AdminDataviewPage
    {
        return new AdminDataviewPage(
            instanceId: 'env-server-sites',
            title: $text('sites.title'),
            columns: array_map(static fn (string $key): array => ['key' => $key, 'label' => $text('sites.'.$key)] + ($key === 'domain' ? ['role' => 'title'] : []), self::COLUMNS),
            total: count($overview->sites),
            pageId: 'page.admin.env-server',
            rows: array_map(static fn (SiteSummary $site): array => self::row($site, $text), $overview->sites),
            perPage: 100,
            rowLinks: ['view' => '/admin/server/sites/__ID__'],
            countLabel: $text('sites.count'),
            tableViewLabel: $text('sites.table_view'),
        );
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
            'php' => $site->php === 'none' ? '—' : $site->php,
            'certificate' => $site->certificateDaysLeft !== null
                ? $text($site->certificateDaysLeft <= 14 ? 'sites.certificate_ends' : 'sites.certificate_left', ['days' => (string) $site->certificateDaysLeft])
                : ($site->ssl === 'none' ? $text('sites.certificate_none') : $site->ssl),
            'backups' => $text($site->backupSchedule ? 'sites.backups_scheduled' : 'sites.backups_unscheduled'),
        ];
    }
}
