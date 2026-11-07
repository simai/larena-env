<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteSummary;

/** The sites as the admin's shared data view, with rows in place: the page holds them already. A row opens the site's own screen. */
final class ServerSitesPage
{
    // What a person checks first (Panel plan 2.1): whether it works, what it runs, its certificate and its backups. The
    // state and the kind are choices: a coloured mark in the cell and a filter of the list (owner-approved prototype,
    // 2026-11-02). The list fits a laptop without scrolling sideways (the panel's UX audit, LAY-04): whose the site is
    // and its disk are on the site's own screen, the kinds are short and the backups are their state, as
    // SiteBackupSummary says it everywhere.
    private const array COLUMNS = ['domain', 'answers', 'profile', 'certificate', 'backups'];

    /** The kinds a site can be, as the new-site screen names them. */
    private const array PROFILES = ['generic', 'laravel', 'wordpress', 'bitrix', 'static', 'proxy', 'node', 'redirect'];

    private function __construct()
    {
    }

    /**
     * @param callable(string, array<string, string>=): string $text
     * @param array<string, ?string> $failures the sites whose last backup failed (SiteBackupSummary::failures)
     */
    public static function of(ServerOverview $overview, callable $text, ?\DateTimeImmutable $now = null, array $failures = []): AdminDataviewPage
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $backups = array_map(static fn (SiteSummary $site): array => SiteBackupSummary::row($site, $now, $failures, $text), $overview->sites);
        $rows = array_map(static fn (SiteSummary $site, array $backup): array => self::row($site, $backup['words'], $text), $overview->sites, $backups);
        // The certificate and the backups as coloured marks, their words kept (critics 2026-11-07): each value the rows
        // hold is an option with the colour of what it means. The backups' words and colour are the ones the site's own
        // tab and the backups page say (critics of 0.4.1).
        /** @var array<string, array<string, string>> $marks */
        $marks = ['certificate' => [], 'backups' => []];
        foreach ($overview->sites as $index => $site) {
            $marks['certificate'][$rows[$index]['certificate']] = self::certificateColor($site);
            $marks['backups'][$backups[$index]['words']] = $backups[$index]['color'];
        }

        return new AdminDataviewPage(
            instanceId: 'env-server-sites',
            title: $text('sites.title'),
            columns: array_map(static fn (string $key): array => ['key' => $key, 'label' => $text('sites.'.$key)] + match ($key) {
                'domain' => ['role' => 'title'],
                'answers' => ['type' => 'choice', 'options' => array_map(static fn (string $state, string $color): array => ['value' => $state, 'label' => $text('sites.state.'.$state), 'color' => $color],
                    array_keys(SiteState::COLORS), SiteState::COLORS)],
                'profile' => ['type' => 'choice', 'options' => array_map(static fn (string $profile): array => ['value' => $profile, 'label' => $text('sites.kinds.'.$profile)], self::PROFILES)],
                default => isset($marks[$key]) ? ['type' => 'choice', 'options' => array_map(static fn (string $value, string $color): array => ['value' => $value, 'label' => $value, 'color' => $color],
                    array_map('strval', array_keys($marks[$key])), array_values($marks[$key]))] : [],
            }, self::COLUMNS),
            total: count($overview->sites),
            pageId: 'page.admin.env-server',
            rows: $rows,
            perPage: 100,
            rowLinks: ['view' => '/admin/server/sites/__ID__'],
            countLabel: $text('sites.count'),
            tableViewLabel: $text('sites.table_view'),
        );
    }

    /** Red without a certificate (a public name) or when it ends within 3 days, amber within 14, green otherwise. */
    private static function certificateColor(SiteSummary $site): string
    {
        if ($site->certificateDaysLeft !== null) {
            return $site->certificateDaysLeft <= 3 ? 'danger' : ($site->certificateDaysLeft <= 14 ? 'warning' : 'success');
        }
        if (RecommendedActions::local($site->domain)) {
            return 'neutral';
        }

        return $site->ssl === 'none' ? 'danger' : 'warning';
    }

    /**
     * @param callable(string, array<string, string>=): string $text
     * @return array<string, string>
     */
    private static function row(SiteSummary $site, string $backups, callable $text): array
    {
        return [
            'id' => $site->domain,
            'domain' => $site->domain,
            'profile' => $site->profile,
            'certificate' => $site->certificateDaysLeft !== null
                ? $text($site->certificateDaysLeft <= 14 ? 'sites.certificate_ends' : 'sites.certificate_left', ['days' => (string) $site->certificateDaysLeft])
                : ($site->ssl === 'none' ? $text(RecommendedActions::local($site->domain) ? 'sites.certificate_local' : 'sites.certificate_none') : $site->ssl),
            'backups' => $backups,
            'answers' => SiteState::of($site->answersChecked, $site->answers),
        ];
    }
}
