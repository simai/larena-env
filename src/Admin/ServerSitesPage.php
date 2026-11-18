<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Env\Server\Moment;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteSummary;

/**
 * The sites as the admin's shared data view, with rows in place: the page holds them already. A row opens the site's own
 * screen.
 *
 * A list of resources (owner decision C31; lists study PX-53…PX-58, dec-f2a4eb46b35b): a dot says whether all is well,
 * the domain over «kind · PHP», then only what matters in words -- a site that does not answer, its backup by its own
 * time («Копия 2 ч назад», the exact time in the tooltip), its certificate (grey where an address needs none) -- and in
 * the row «Открыть сайт», «Сделать копию» and «⋯». Sorted by name; the sites with problems are a filter of the dot, not
 * the top of the list. On a phone the same list, without a label in every row.
 */
final class ServerSitesPage
{
    /** The kinds a site can be, as the new-site screen names them. */
    private const array PROFILES = ['generic', 'laravel', 'wordpress', 'bitrix', 'static', 'proxy', 'node', 'redirect'];

    /** The site's sections its row menu opens beside the site itself: every site has them. */
    private const array SECTIONS = ['backups', 'domains', 'log'];

    /** The dot's statuses, worst last. */
    private const array DOTS = ['ok', 'stopped', 'warning', 'error'];

    private function __construct()
    {
    }

    /**
     * @param callable(string, array<string, string>=): string $text
     * @param array<string, ?string> $failures the sites whose last backup failed (SiteBackupSummary::failures)
     * @param bool $canOperate whether the person may act on the sites: «Сделать копию» is offered only then
     */
    public static function of(ServerOverview $overview, callable $text, ?\DateTimeImmutable $now = null, array $failures = [], bool $canOperate = false): AdminDataviewPage
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sites = $overview->sites;
        usort($sites, static fn (SiteSummary $a, SiteSummary $b): int => strnatcasecmp($a->domain, $b->domain));
        $rows = array_map(static fn (SiteSummary $site): array => self::row($site, SiteBackupSummary::row($site, $now, $failures, $text), $now, $text, $canOperate), $sites);

        return new AdminDataviewPage(
            instanceId: 'env-server-sites',
            title: $text('sites.title'),
            columns: [
                ['key' => 'state', 'label' => $text('sites.list.state'), 'list' => 'status', 'type' => 'choice',
                    'options' => array_map(static fn (string $dot): array => ['value' => $dot, 'label' => $text('sites.list.dot.'.$dot), 'status' => $dot], self::DOTS)],
                ['key' => 'domain', 'label' => $text('sites.domain'), 'role' => 'title', 'list' => 'title'],
                ['key' => 'profile', 'label' => $text('sites.profile'), 'list' => 'supporting', 'type' => 'choice',
                    'options' => array_map(static fn (string $profile): array => ['value' => $profile, 'label' => $text('sites.kinds.'.$profile)], self::PROFILES)],
                ['key' => 'php', 'label' => $text('sites.php'), 'list' => 'supporting'],
                ['key' => 'answers', 'label' => $text('sites.answers'), 'list' => 'fact'],
                ['key' => 'backups', 'label' => $text('sites.backups'), 'list' => 'fact'],
                ['key' => 'certificate', 'label' => $text('sites.certificate'), 'list' => 'fact'],
            ],
            total: count($rows),
            pageId: 'page.admin.env-server',
            rows: $rows,
            perPage: 100,
            rowLinks: ['view' => '/admin/server/sites/__ID__'],
            // The row's «⋯» (its menu column): the site, its backups, its names and HTTPS, its log.
            settings: true,
            countLabel: $text('sites.count'),
            tableViewLabel: $text('sites.table_view'),
            layout: 'list',
            quickActions: $canOperate ? ['visit', 'backup'] : ['visit'],
            rowActions: ['visit' => ['label' => $text('sites.list.visit'), 'icon' => 'open_in_new']]
                // The backup is planned on the site's own backups, in the side panel (larena/admin AdminPlannedAction).
                + ($canOperate ? ['backup' => ['label' => $text('sites.list.backup'), 'icon' => 'backup', 'href' => '/admin/server/sites/__ID__?tab=backups&action=backup']] : [])
                + array_combine(self::SECTIONS, array_map(static fn (string $tab): array => ['label' => $text('site.tabs.'.$tab), 'icon' => SiteObjectMenu::ICONS[$tab],
                    'href' => '/admin/server/sites/__ID__?tab='.$tab], self::SECTIONS)),
            emptyText: $text('sites.list.empty'),
            emptyDescription: $text('sites.list.empty_description'),
        );
    }

    /**
     * @param array{state: string, last: ?string, failed: ?string} $backup SiteBackupSummary::row
     * @param callable(string, array<string, string>=): string $text
     * @return array<string, mixed>
     */
    private static function row(SiteSummary $site, array $backup, \DateTimeImmutable $now, callable $text, bool $canOperate): array
    {
        $answers = SiteState::of($site->answersChecked, $site->answers);
        $certificate = self::certificate($site, $text);
        $backups = self::backup($backup, $now, $text);
        // The dot is the worst of what the row says; its word is the first thing to deal with.
        $reasons = [];
        // A paused site answers 503 on purpose (site:suspend): said «На паузе», never as a site that fails.
        if (!$site->suspended && ($answers === 'down' || $answers === 'error')) {
            $reasons[] = ['error', $text('sites.state.'.$answers)];
        }
        foreach ([$backups, $certificate] as $fact) {
            if (in_array($fact['status'] ?? null, ['error', 'warning'], true)) {
                $reasons[] = [$fact['status'], $fact['label']];
            }
        }
        usort($reasons, static fn (array $a, array $b): int => array_search($b[0], self::DOTS, true) <=> array_search($a[0], self::DOTS, true));
        [$dot, $word] = $reasons[0] ?? ($site->suspended ? ['stopped', $text('sites.state.paused')]
            : ($answers === 'unchecked' ? ['stopped', $text('sites.state.unchecked')] : ['ok', $text('sites.state.works')]));
        $keeps = $backup['state'] !== SiteBackupSummary::NOT_NEEDED;

        return [
            'id' => $site->domain,
            'state' => ['value' => $dot, 'label' => $word],
            'domain' => $site->domain,
            'profile' => $site->profile,
            // «WordPress · PHP 8.3»; a plain PHP site is «PHP · 8.3», not «PHP · PHP 8.3».
            'php' => preg_match('/^\d+(\.\d+)*$/', $site->php) === 1 ? ($site->profile === 'generic' ? $site->php : $text('sites.list.php', ['version' => $site->php])) : '',
            // Said only when the site does not answer: «Работает» in every row said nothing (PX-54).
            'answers' => $site->suspended ? ['label' => $text('sites.state.paused'), 'text' => $text('sites.state.paused'), 'status' => 'warning']
                : ($answers === 'down' || $answers === 'error' ? ['label' => $text('sites.state.'.$answers), 'text' => $text('sites.state.'.$answers), 'status' => 'error'] : ''),
            'backups' => $backups,
            'certificate' => $certificate,
            // The site itself, over HTTPS where it has a certificate.
            '_links' => ['visit' => ($site->ssl === 'none' ? 'http://' : 'https://').$site->domain.'/'],
            '_actions' => [...($canOperate && $keeps ? ['view', 'visit', 'backup'] : ['view', 'visit']), ...self::SECTIONS],
        ];
    }

    /**
     * The backup by its own time (PX-55): «Копия 2 ч назад», the exact time in the tooltip; a failure and no backup at all
     * stand out, an old one is a warning, a redirect keeps nothing.
     *
     * @param array{state: string, last: ?string, failed: ?string} $backup
     * @param callable(string, array<string, string>=): string $text
     * @return array{label: string, text: string, status?: string, tip?: string}
     */
    private static function backup(array $backup, \DateTimeImmutable $now, callable $text): array
    {
        $fact = static fn (string $words, ?string $status = null, ?string $tip = null): array => ['label' => $words, 'text' => $words]
            + ($status !== null ? ['status' => $status] : []) + ($tip !== null ? ['tip' => $tip] : []);

        return match ($backup['state']) {
            SiteBackupSummary::FAILED => $fact($text('sites.list.backup_failed'), 'error',
                $backup['failed'] !== null ? $text('sites.list.backup_failed_tip', ['time' => Moment::human($backup['failed'])]) : null),
            SiteBackupSummary::NONE => $fact($text('sites.list.backup_none'), 'warning'),
            SiteBackupSummary::STALE => $fact($text('sites.list.backup_stale', ['ago' => (string) Moment::ago($backup['last'], $now, $text)]), 'warning',
                $text('sites.list.backup_tip', ['time' => Moment::human($backup['last'])])),
            SiteBackupSummary::FRESH => $fact($text('sites.list.backup_fresh', ['ago' => (string) Moment::ago($backup['last'], $now, $text)]), null,
                $text('sites.list.backup_tip', ['time' => Moment::human($backup['last'])])),
            default => $fact($text('sites.list.backup_not_needed')),
        };
    }

    /**
     * The certificate (PX-56): its days while it lasts, a warning within 14 days, an error within 3 or without one on a
     * public name; an address that only opens on this computer needs none and says so in grey.
     *
     * @param callable(string, array<string, string>=): string $text
     * @return array{label: string, text: string, status?: string, tip?: string}
     */
    private static function certificate(SiteSummary $site, callable $text): array
    {
        $fact = static fn (string $words, ?string $status = null, ?string $tip = null): array => ['label' => $words, 'text' => $words]
            + ($status !== null ? ['status' => $status] : []) + ($tip !== null ? ['tip' => $tip] : []);
        if ($site->certificateDaysLeft !== null) {
            $days = (string) $site->certificateDaysLeft;

            return match (true) {
                $site->certificateDaysLeft <= 3 => $fact($text('sites.list.certificate_ends', ['days' => $days]), 'error'),
                $site->certificateDaysLeft <= 14 => $fact($text('sites.list.certificate_ends', ['days' => $days]), 'warning'),
                $site->certificateRenewFailed => $fact($text('sites.list.renew_failed'), 'warning'),
                default => $fact($text('sites.list.certificate_left', ['days' => $days])),
            };
        }
        if (RecommendedActions::local($site->domain)) {
            $zone = '.'.(string) array_slice(explode('.', $site->domain), -1)[0];

            return $fact($text('sites.list.certificate_local', ['zone' => $zone]), null, $text('sites.list.certificate_tip', ['zone' => $zone]));
        }

        return $site->ssl === 'none' ? $fact($text('sites.list.no_https'), 'error') : $fact($text('sites.list.certificate_on'));
    }
}
