<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteSummary;

/** The sites as the admin's shared data view, with rows in place: the page holds them already. Read-only, no row actions. */
final class ServerSitesPage
{
    private const array COLUMNS = ['domain', 'profile', 'php', 'ssl', 'owner', 'database', 'release'];

    private function __construct()
    {
    }

    /** @param callable(string): string $text */
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
            countLabel: $text('sites.count'),
            tableViewLabel: $text('sites.table_view'),
        );
    }

    /**
     * @param callable(string): string $text
     * @return array<string, string>
     */
    private static function row(SiteSummary $site, callable $text): array
    {
        return [
            'id' => $site->domain,
            'domain' => $site->domain,
            'profile' => $site->profile,
            'php' => $site->php === 'none' ? '—' : $site->php,
            'ssl' => $site->certificateDaysLeft !== null ? $site->ssl.' · '.$site->certificateDaysLeft.' '.$text('sites.days_left') : ($site->ssl === 'none' ? '—' : $site->ssl),
            'owner' => $site->owner ?? '—',
            'database' => $site->databaseEngine ?? '—',
            'release' => $site->release ?? '—',
        ];
    }
}
