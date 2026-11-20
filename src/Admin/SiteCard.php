<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Admin\ObjectCard\ObjectCard;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\SiteDetails;

/**
 * The site's card on the admin's object card (larena-admin::object-card; object card study PX-29…PX-33): what the
 * panel gives the card and nothing more. The head's main actions by the kind of site («Открыть сайт», its own admin,
 * «Сделать копию»), «⋯» with the rest of the overview's actions and «Удалить сайт…» last, «Требует внимания» with one
 * action per problem in plain words, the details the head does not say already, and the site's sections.
 *
 * An action is the admin card's {id, text, href?, modal?, form?, danger?, disabled?, reason?, external?}; on the
 * overview an action of its own opens its side panel (the plan, then the question of part 1), elsewhere it goes to the
 * overview with ?action=, which opens the same panel with the page (PX-15).
 */
final class SiteCard
{
    /** Where a kind of site keeps its own admin, opened from the head (study q-overview: WordPress «Войти в WordPress»). */
    public const array OWN_ADMIN = ['wordpress' => '/wp-admin/', 'bitrix' => '/bitrix/admin/', 'laravel' => null];

    /** The overview's actions in «⋯», in this order; the dangerous ones go last (C116). */
    public const array MORE = [SiteOperation::SitePause, SiteOperation::SiteResume, SiteOperation::CacheClear, SiteOperation::PhpRestore,
        SiteOperation::SiteTransfer, SiteOperation::SiteRemove];

    /** The card's section a check's area is put right in: a problem without the server's own fix opens it (PX-32). */
    public const array AREA_TABS = ['php' => 'php', 'nginx' => 'php', 'db' => 'database', 'ssl' => 'domains', 'cron' => 'cron', 'fs' => 'files',
        'app' => 'files', 'http' => 'log'];

    /**
     * What the site view takes beside its own data, from that data (the controller's, or a screen of tests): the check's
     * problems, the card, the row of the section's actions (none on the overview: they are in «⋯») and whether the
     * site's main name points elsewhere (no certificate can be issued then, PX-33). A key already given is kept.
     *
     * @param array<string, mixed> $data the site view's data
     * @param \Closure(string, array<string, string>=): string $text
     * @return array{checkProblems: list<array<string, mixed>>, card: ?array<string, mixed>, rowOperations: list<SiteOperation>, domainElsewhere: bool}
     */
    public static function forView(array $data, \Closure $text, string $url, ?string $measured = null): array
    {
        $site = $data['site'] ?? null;
        $tab = (string) ($data['tab'] ?? 'overview');
        $operations = is_array($data['tabOperations'] ?? null) ? $data['tabOperations'] : [];
        $problems = $data['checkProblems'] ?? ($site instanceof SiteDetails && $tab === 'overview'
            ? SiteChecks::problems($data['doctor'] ?? null, ($data['checkFixes'] ?? false) === true, $site) : []);
        $elsewhere = false;
        foreach ($data['siteNames']->names->names ?? [] as $name) {
            $elsewhere = $elsewhere || ($name->kind === \Larena\Env\Server\SiteName::PRIMARY && $name->pointsHere === false);
        }

        return [
            'checkProblems' => $problems,
            'card' => $data['card'] ?? ($site instanceof SiteDetails ? self::card($site, (string) $data['domain'], $tab, $data['cardOperations'] ?? $operations,
                is_array($data['tabs'] ?? null) ? $data['tabs'] : [], $data['siteRoute'] ?? null, $problems, $url, $text, $measured) : null),
            'rowOperations' => $tab === 'overview' ? [] : SiteOperation::inRow($operations, is_array($data['sftpChoices'] ?? null) ? $data['sftpChoices'] : []),
            'domainElsewhere' => $elsewhere,
        ];
    }

    /**
     * Everything the site gives the card: the head on every section, «Требует внимания» and the details on the overview,
     * and the sections.
     *
     * @param list<SiteOperation> $operations
     * @param list<string> $tabs
     * @param array<string, mixed>|null $route
     * @param list<array{status: string, tone: string, label: string, title: string, text: string, area: string, key: ?string, detail: ?string, since: ?string, fix: ?string, area_key?: string}> $problems
     * @param \Closure(string, array<string, string>=): string $text
     * @return array{head: array<string, mixed>, attention: array<string, mixed>, details: array<string, mixed>, sections: array<string, mixed>}
     */
    public static function card(SiteDetails $site, string $domain, string $tab, array $operations, array $tabs, ?array $route, array $problems, string $url, \Closure $text, ?string $measured = null): array
    {
        // Drawn by the admin's card as it prints them (larena-admin::object-card.*, thin hosts).
        $head = self::head($site, $domain, $tab, $operations, $tabs, $url, $text);

        return [
            'head' => ObjectCard::headView($head['main'], $head['more']),
            'attention' => ObjectCard::attentionView($tab === 'overview' ? self::problems($site, $route, $problems, $tabs, $url, $text) : []),
            'details' => ObjectCard::listView($tab === 'overview' ? self::details($site, $domain, $measured, $text) : []),
            'sections' => ObjectCard::sectionsView(self::sections($tabs, $tab, $url, $text)),
        ];
    }

    /**
     * The head: «Открыть сайт», the site's own admin where its kind has one, «Сделать копию» where backups can be made
     * (else «Файлы»); «⋯» with the overview's actions, «Сменить версию PHP» and «Удалить сайт…» last.
     *
     * @param list<SiteOperation> $operations what this server offers the site
     * @param list<string> $tabs the site's sections
     * @param \Closure(string, array<string, string>=): string $text the panel's words (larena-env::admin.<key>)
     * @return array{main: list<array<string, mixed>>, more: list<array<string, mixed>>}
     */
    public static function head(SiteDetails $site, string $domain, string $tab, array $operations, array $tabs, string $url, \Closure $text): array
    {
        $scheme = $site->certificate !== null ? 'https' : 'http';
        $main = [['id' => 'open', 'text' => $text('site.open'), 'href' => $scheme.'://'.$domain.'/', 'external' => true]];
        $ownAdmin = self::OWN_ADMIN[$site->profile] ?? null;
        if (is_string($ownAdmin) && !$site->suspended) {
            $main[] = ['id' => 'own-admin', 'text' => $text('site.card.own_admin.'.$site->profile), 'href' => $scheme.'://'.$domain.$ownAdmin, 'external' => true];
        }
        if (in_array(SiteOperation::Backup, $operations, true) && in_array('backups', $tabs, true)) {
            $main[] = ['id' => SiteOperation::Backup->value, 'text' => $text('site.card.backup'), 'href' => $url.'?tab=backups&action='.SiteOperation::Backup->value];
        } elseif (in_array('files', $tabs, true)) {
            $main[] = ['id' => 'files', 'text' => $text('site.tabs.files'), 'href' => $url.'?tab=files'];
        }
        $more = [];
        if (in_array(SiteOperation::PhpVersion, $operations, true) && in_array('php', $tabs, true)) {
            $more[] = ['id' => SiteOperation::PhpVersion->value, 'text' => $text('operations.php-version'), 'href' => $url.'?tab=php&action='.SiteOperation::PhpVersion->value];
        }
        foreach (self::MORE as $operation) {
            if (!in_array($operation, $operations, true)) {
                continue;
            }
            $more[] = ['id' => $operation->value, 'text' => $text($operation === SiteOperation::SiteRemove ? 'site.card.remove' : 'operations.'.$operation->value),
                'danger' => $operation->destroys(), 'icon' => $operation === SiteOperation::SiteRemove ? 'delete' : null]
                + ($tab === 'overview' ? ['modal' => 'env-op-'.$operation->value] : ['href' => $url.'?action='.$operation->value]);
        }

        return ['main' => $main, 'more' => $more];
    }

    /**
     * «Требует внимания»: the site's next step once it is made (HTTPS, PX-31), then each problem of the check in plain
     * words with its one action (PX-32): the server's fix where it offers one, else the section it is put right in.
     *
     * @param list<array{status: string, tone: string, label: string, title: string, text: string, area: string, key: ?string, detail: ?string, since: ?string, fix: ?string, area_key?: string}> $problems
     * @param list<string> $tabs
     * @param \Closure(string, array<string, string>=): string $text
     * @return list<array<string, mixed>>
     */
    public static function problems(SiteDetails $site, ?array $route, array $problems, array $tabs, string $url, \Closure $text): array
    {
        $items = [];
        $repeated = [];
        if ($route !== null && $route['status']->step === 'done' && $site->certificate === null && in_array('domains', $tabs, true)) {
            $items[] = ['id' => 'https-next', 'status' => 'info', 'title' => $text('site.card.https.title'), 'text' => $text('site.card.https.text'),
                'source' => $text('site.tabs.domains'),
                'action' => ['id' => 'https-next', 'text' => $text('routes.https_go'), 'href' => $url.'?tab=domains&action='.SiteOperation::CertificateIssue->value]];
        }
        foreach ($problems as $index => $problem) {
            $area = self::areaOf($problem);
            $tabOfArea = self::AREA_TABS[$area] ?? null;
            $action = null;
            if ($problem['fix'] !== null) {
                $action = ['id' => 'fix-'.$index, 'text' => $text('doctor.fix'), 'form' => 'env-check-fix-'.$index];
            } elseif ($tabOfArea !== null && in_array($tabOfArea, $tabs, true)) {
                $action = ['id' => 'open-'.$index, 'text' => $text('site.card.open_section.'.$tabOfArea), 'href' => $url.'?tab='.$tabOfArea];
            }
            $said = array_values(array_filter([$problem['detail'], $problem['since'] === null ? null : $text('doctor.since', ['time' => $problem['since']])],
                static fn (?string $part): bool => $part !== null && $part !== ''));
            $item = ['id' => 'check-'.$index, 'status' => $problem['tone'] === 'error' ? 'error' : 'warning', 'title' => $problem['text'],
                'text' => $said === [] ? $text('site.card.check_hint.'.($action === null ? 'none' : ($problem['fix'] !== null ? 'fix' : 'section'))) : implode(' · ', $said),
                // Where it is put right, in words; the profile's rules are no place of their own (PX-32: no «правила профиля»).
                'source' => $area === 'rules' ? ($tabOfArea === null ? null : $text('site.tabs.'.$tabOfArea)) : $problem['area'], 'action' => $action];
            // The same finding said again with nothing of its own (eight «INI required»): one row that counts them.
            $same = $said === [] && $problem['fix'] === null ? $item['status'].'|'.$item['title'].'|'.$item['source'] : null;
            if ($same !== null && isset($repeated[$same])) {
                $items[$repeated[$same]]['times'] = ($items[$repeated[$same]]['times'] ?? 1) + 1;
                continue;
            }
            if ($same !== null) {
                $repeated[$same] = count($items);
            }
            $items[] = $item;
        }
        foreach ($items as $at => $item) {
            if (($item['times'] ?? 1) > 1) {
                $items[$at]['title'] = $item['title'].' ×'.$item['times'];
            }
            unset($items[$at]['times']);
        }

        return $items;
    }

    /**
     * The details: what the head does not say already (PX-30: the state and the kind are in the head).
     *
     * @param \Closure(string, array<string, string>=): string $text
     * @return list<array<string, mixed>>
     */
    public static function details(SiteDetails $site, string $domain, ?string $measured, \Closure $text): array
    {
        $scheme = $site->certificate !== null ? 'https' : 'http';
        $items = [['id' => 'address', 'label' => $text('site.card.address'), 'value' => $domain, 'href' => $scheme.'://'.$domain.'/']];
        if ($site->answers !== null && $site->answers !== 200) {
            $items[] = ['id' => 'answers', 'label' => $text('sites.answers'), 'value' => $text('sites.answers_code', ['code' => (string) $site->answers]), 'status' => 'warning'];
        }
        $items[] = ['id' => 'owner', 'label' => $text('sites.owner'), 'value' => $site->owner ?? $text('access.own_account'), 'mono' => $site->owner !== null];
        if ($site->php !== 'none') {
            $items[] = ['id' => 'php', 'label' => $text('sites.php'), 'value' => $site->php]
                + (\Larena\Env\Software\PhpSupport::outdated($site->php) ? ['badge' => ['text' => $text('php_outdated.mark'), 'status' => 'warning']] : []);
        }
        $items[] = ['id' => 'https', 'label' => $text('sites.ssl'), 'value' => $site->certificate === null ? $text('sites.https_none')
            : ($site->certificate->daysLeft === null ? $text('sites.https_on') : $text('sites.https_yes', ['days' => (string) $site->certificate->daysLeft])),
            'status' => $site->certificate === null ? 'warning' : 'success'];
        if ($site->databaseEngine !== null) {
            $engine = $text('new_site.engines.'.$site->databaseEngine);
            $items[] = ['id' => 'database', 'label' => $text('sites.database'), 'value' => $engine === 'new_site.engines.'.$site->databaseEngine
                || str_starts_with($engine, 'larena-env::') ? $site->databaseEngine : $engine];
        }
        if ($site->release !== null) {
            $items[] = ['id' => 'release', 'label' => $text('sites.release'), 'value' => $site->release, 'mono' => true];
        }
        $items[] = ['id' => 'disk', 'label' => $text('sites.disk'), 'value' => \Larena\Env\Server\Bytes::human($site->diskBytes)]
            + ($measured === null ? [] : ['hint' => $text('site.measured', ['ago' => $measured])]);

        return $items;
    }

    /**
     * The site's sections as the card lists them under the overview on a narrow screen.
     *
     * @param list<string> $tabs
     * @param \Closure(string, array<string, string>=): string $text
     * @return list<array{id: string, label: string, href: string, current: bool}>
     */
    public static function sections(array $tabs, string $tab, string $url, \Closure $text): array
    {
        return array_map(static fn (string $name): array => ['id' => $name, 'label' => $text('site.tabs.'.$name),
            'href' => $name === 'overview' ? $url : $url.'?tab='.$name, 'current' => $name === $tab], $tabs);
    }

    /**
     * The site's latest runs as the card's timeline takes them (sf-list-timeline): what, how it ended, when, by whom.
     *
     * @param list<array<string, mixed>> $rows the card's history rows (SiteAdminController::siteHistory)
     * @param \Closure(string, array<string, string>=): string $text
     * @return list<array<string, mixed>>
     */
    public static function recent(array $rows, \Closure $text): array
    {
        $items = [];
        foreach ($rows as $index => $row) {
            $times = (int) ($row['times'] ?? 1);
            $item = ['id' => 'run-'.$index, 'title' => (string) $row['title'].($times > 1 ? ' ×'.$times : ''),
                'text' => implode(' · ', array_values(array_filter([(string) ($row['words'] ?? ''), (string) ($row['who'] ?? '')], static fn (string $part): bool => $part !== ''))),
                'status' => (string) ($row['status'] ?? 'neutral'), 'href' => (string) ($row['href'] ?? '')];
            if (is_string($row['at'] ?? null) && $row['at'] !== '') {
                $item['time'] = $row['at'];
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @param array{key: ?string, area_key?: string} $problem */
    private static function areaOf(array $problem): string
    {
        return $problem['key'] === 'answers' ? 'http' : (string) ($problem['area_key'] ?? '');
    }
}
