<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Files\FileEntry;
use Larena\Env\Files\FilePath;
use Larena\Env\Admin\SiteFilesPage;
use Larena\Env\Files\SiteFiles;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\Moment;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Server\SiteLogView;
use Larena\Env\Admin\SiteBackupSummary;
use Larena\Env\Warnings\PanelWarningSource;
use Larena\Env\Warnings\PanelWarnings;

/**
 * One site: what it runs on, its certificate, its backups and the last lines of its log, and the actions that belong
 * to them. An action is never done from this screen: it is planned, shown in words, and only a confirmation of that
 * plan starts it as a job the next screen follows.
 */
final readonly class SiteAdminController
{
    /**
     * The card's sections (Panel plan 2.3 and 3), in the order the side navigation shows them: its state and problems,
     * domains with the certificate and the domain's DNS records (stage 7), files, databases, backups, its own scheduled
     * jobs (5.4), SFTP access, the log, releases (only where the site is deployed by releases), and under «Дополнительно»
     * PHP with the web server and, for the system level only, its own nginx directives (stage 6). After the panel's UX
     * audit (owner decision 2026-11-05) DNS lives in «Домены и HTTPS» and nginx in «PHP и веб-сервер»: an old link to
     * `?tab=dns` or `?tab=nginx` opens the section that holds it.
     */
    public const array TABS = ['overview', 'domains', 'php', 'files', 'database', 'backups', 'cron', 'access', 'log', 'releases'];
    /** The parts that are shown inside another section, and the section that holds each. */
    public const array MERGED = ['dns' => 'domains', 'nginx' => 'php'];
    /** The card's sections as the side navigation shows them: one list, no group of one (the panel's UX audit). */
    public const array GROUPS = [
        'site' => ['overview', 'domains', 'php', 'files', 'database', 'backups', 'cron', 'access', 'log', 'releases'],
    ];
    /** The tabs shown only where the server lists the command they read. */
    private const array LISTED_TABS = ['cron' => 'cron:list'];
    /** A site that only sends visitors elsewhere has no code, files or database: only these sections (critics 2026-11-07). */
    private const array FORWARDING = ['redirect', 'alias'];
    private const array FORWARDING_TABS = ['overview', 'domains', 'log'];
    private const array LOG_KINDS = ['access', 'error'];
    /** How many of the last lines the log reads; the gateway gives at most 1000. */
    public const array LOG_LINES = [200, 500, 1000];
    /** «Скачать журнал»: the server's last lines, up to 10000 since simai-env 1.60.0 (an older one gives its 1000). */
    public const int DOWNLOAD_LINES = 10000;

    public function __construct(
        private SiteDetailsReader $sites,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
        private ?\Larena\Env\Dns\DnsService $dns = null,
        private ?SiteFiles $files = null,
        private ?PanelWarningSource $warningSource = null,
        private ?\Larena\Access\Runtime\AccessOperationAuthorizer $authorizer = null,
        private ?\Larena\Env\Server\SiteNamesReader $names = null,
        private ?\Larena\Env\Server\SiteRouteReader $routes = null,
        private ?\Larena\Env\Server\SiteExtrasReader $profiles = null,
        private ?\Larena\Env\Contracts\PanelGateway $gateway = null,
        private ?\Larena\Admin\Runtime\AdminDataviewPagePresenter $dataviews = null,
        private ?\Larena\Env\People\SecretFileAccess $secrets = null,
    ) {
    }

    /** The section a `?tab=` names: a part shown inside another section opens that section. */
    /**
     * The sections this site shows: those the server can read, and releases only where the site is deployed by them (an
     * empty section is not shown).
     *
     * @return list<string>
     */
    public static function sectionsOf(?\Larena\Env\Server\SiteDetails $site, PanelCapabilities $capabilities): array
    {
        return array_values(array_filter(self::TABS,
            static fn (string $name): bool => (!isset(self::LISTED_TABS[$name]) || $capabilities->allows(self::LISTED_TABS[$name]))
                && ($name !== 'releases' || $site?->release !== null || $site?->previousRelease !== null)
                && (!in_array($site?->profile, self::FORWARDING, true) || in_array($name, self::FORWARDING_TABS, true))));
    }

    /**
     * The reads a site's card makes on opening a section: what the site is, and on its overview its check, where it
     * stands on its creation route and the server's kinds of sites. The list of sites asks them ahead for the sites it
     * shows, so the step from the list into a site finds them kept.
     *
     * @return list<PanelRequest>
     */
    public static function cardReads(PanelActor $actor, string $domain, string $tab, PanelCapabilities $capabilities): array
    {
        $reads = $capabilities->allows('site:describe') ? [PanelRequest::read($actor, 'site:describe', ['domain' => $domain])] : [];
        if ($tab === 'overview') {
            foreach (['site:doctor', 'site:status'] as $command) {
                if ($capabilities->allows($command)) {
                    $reads[] = PanelRequest::read($actor, $command, ['domain' => $domain]);
                }
            }
            if ($capabilities->allows('site:status') && $capabilities->allows('self:profiles')) {
                $reads[] = PanelRequest::read($actor, 'self:profiles');
            }
        }

        return $reads;
    }

    public static function section(mixed $asked): string
    {
        $asked = is_string($asked) ? $asked : '';

        return self::MERGED[$asked] ?? $asked;
    }

    /**
     * «Скачать журнал» (PX-48): the last lines of the site's log as the server wrote them, a text file for a specialist or
     * a letter to support. The same read as the screen's, the server keeps the owner's scope.
     */
    public function logDownload(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $kind = in_array($request->query('log'), self::LOG_KINDS, true) ? (string) $request->query('log') : 'access';
        $log = $this->sites->log($actor, $domain, $kind, self::DOWNLOAD_LINES, entries: false);
        if ($log->log === null) {
            return new RedirectResponse(route('larena.env.admin.site', ['domain' => $domain, 'tab' => 'log', 'log' => $kind === 'error' ? 'error' : 'access'], false));
        }
        $name = preg_replace('/[^A-Za-z0-9.-]/', '_', $domain).'-'.$kind.'-'.gmdate('Ymd-His').'.log';
        $body = implode("\n", $log->log->lines);

        return new \Illuminate\Http\Response($body === '' ? '' : $body."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** An error log's level in words: «Ошибка», «Предупреждение»… */
    private function levelWords(string $level): string
    {
        $key = 'larena-env::admin.log.levels.'.$level;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : $level;
    }

    /** Whether the person may change the server's sites (env.sites.operate), as the routes that change them require. */
    private function canOperate(Request $request): bool
    {
        return $this->authorizer?->authorize($request, 'env.sites.operate')->isAllowed() ?? false;
    }

    /**
     * The sites list's «Исправить» (FASTPANEL look 2026-11-18 §5.2): to where the site's worst problem is fixed, from the
     * overview the list was drawn from (kept a little while, so no new read for it) and the server's alerts of a failed copy.
     */
    public function fix(Request $request, string $domain): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews->read($actor)->overview;
        foreach ($overview->sites ?? [] as $site) {
            if ($site->domain === $domain) {
                $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);
                $failures = \Larena\Env\Admin\SiteBackupSummary::failures($overview->capabilities?->allows('self:alerts') === true ? $this->extras->alerts($actor) : null);

                return new RedirectResponse(\Larena\Env\Admin\ServerSitesPage::fixAddress($site, PanelWarnings::now(), $failures, $text));
            }
        }

        return new RedirectResponse(route('larena.env.admin.site', ['domain' => $domain], false));
    }

    public function show(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        // The card's own reads in one call of the server (simai-env `batch`) before the card asks them one by one: four
        // starts of simai-env, 1.7 s, become one, 0.8 s (speed review 2026-11-16). The capabilities come from the kept
        // overview; a read already kept is not asked again.
        if ($this->gateway instanceof \Larena\Env\Gateway\CachingPanelGateway) {
            $known = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
            $this->gateway->prefetch(self::cardReads($actor, $domain, self::section($request->query('tab')) ?: self::TABS[0], $known));
        }
        $result = $this->sites->read($actor, $domain);
        $site = $result->site;
        // What this server's gateway allows, and its PHP versions: the card offers only what will not be refused.
        $overview = $site === null ? null : $this->overviews->read($actor)->overview;
        $capabilities = $overview->capabilities ?? PanelCapabilities::none();
        $tabs = self::sectionsOf($site, $capabilities);
        $asked = self::section($request->query('tab'));
        // A section this site has not is not shown under another's name: the address goes to the site's first section
        // (critics 2026-11-07).
        if ($site !== null && $asked !== '' && !in_array($asked, $tabs, true)) {
            return new RedirectResponse(route('larena.env.admin.site', ['domain' => $domain], false));
        }
        $tab = in_array($asked, $tabs, true) ? $asked : self::TABS[0];
        // A domain the server has no site on is said as such, not as the server's failure (critics 2026-11-07).
        $missing = $site === null && !$result->refused && $this->unknownSite($actor, $domain);
        // What the log shows (PX-48): the site's errors first, then every visit, then the web server's error log.
        $logView = in_array($request->query('log'), SiteLogView::VIEWS, true) ? (string) $request->query('log') : SiteLogView::VIEWS[0];
        $kind = $logView === 'error' ? 'error' : 'access';
        $lines = in_array((int) $request->query('lines'), self::LOG_LINES, true) ? (int) $request->query('lines')
            : ($logView === SiteLogView::PROBLEMS ? self::LOG_LINES[count(self::LOG_LINES) - 1] : self::LOG_LINES[0]);
        $search = mb_substr(trim((string) $request->query('q')), 0, 128);
        $groups = $kind === 'error' ? SiteLogView::LEVELS : ($logView === SiteLogView::PROBLEMS ? ['4xx', '5xx'] : SiteLogView::STATUS_GROUPS);
        $group = in_array($request->query('group'), $groups, true) ? (string) $request->query('group') : ($logView === SiteLogView::PROBLEMS ? SiteLogView::PROBLEMS : '');
        $logShown = max(SiteLogView::PAGE, min(self::LOG_LINES[count(self::LOG_LINES) - 1], (int) $request->query('shown', (string) SiteLogView::PAGE)));
        $logShown -= $logShown % SiteLogView::PAGE;
        // Each tab asks the gateway only for what it shows.
        // A search goes to the server, which looks through far more lines than are shown, when its release can.
        $match = $search !== '' && preg_match('/\A[ -~]{1,200}\z/D', $search) === 1 && $capabilities->searchesLogs() ? $search : null;
        // «Обновить» reads the server again past the minute the panel keeps a log's answer (critics 2026-11-07).
        // The answers asked are kept by the server among its last 20000 lines (simai-env 1.60.0): the site's errors are
        // found even on a busy site, where its last 1000 lines are all ordinary visits.
        $status = $kind !== 'access' || $group === '' ? null : ($group === SiteLogView::PROBLEMS ? '4xx,5xx' : $group);
        $log = $site === null || $tab !== 'log' ? null : $this->sites->log($actor, $domain, $kind, $lines, $match, $request->query('fresh') === '1', $status);
        $logRead = $log?->log === null ? null : SiteLogView::of($log->log, $search, $group);
        $doctor = $site === null || $tab !== 'overview' ? null : $this->sites->doctor($actor, $domain);
        $operations = $site === null ? [] : SiteOperation::offeredFor($site, $capabilities);
        // A site's owner is not offered what the system level alone may do (Apache, simai-env 1.42.0).
        $operations = $actor->owner === null ? $operations : array_values(array_filter($operations,
            static fn (SiteOperation $operation): bool => !in_array($operation, SiteOperation::SYSTEM_ONLY, true)));
        // «Передать сайт…»: to another server account, never to none; without one to hand it to, it is not offered.
        $transferChoices = $site === null ? [] : SiteOperation::transferChoices($site->owner, $overview->owners ?? [], $overview->blockedOwners ?? []);
        if ($transferChoices === []) {
            $operations = array_values(array_filter($operations, static fn (SiteOperation $operation): bool => $operation !== SiteOperation::SiteTransfer));
        }
        $ask = static fn (string $command, string $onTab): bool => $site !== null && $tab === $onTab && $capabilities->allows($command);
        $sftpAccounts = $ask('access:list', 'access') ? $this->extras->sftpAccounts($actor, $domain) : null;
        $nginx = $ask('site:nginx-custom-show', 'php') && $actor->owner === null ? $this->files?->nginxCustom($actor, $domain) : null;
        // A failed or missing backup said where backups are; the rest of what needs attention is in the admin's alert bar.
        $siteWarnings = $overview === null || $tab !== 'backups' ? null : ($this->warningSource ?? new PanelWarningSource($this->extras))->ofSite($actor, $overview, $domain, PanelWarnings::now());
        $siteNames = $ask('site:names', 'domains') ? $this->names?->read($actor, $domain) : null;

        $this->breadcrumbs($request, $domain, $tab);

        return $this->views->make('larena-env::admin.site', [
            'domain' => $domain,
            'tab' => $tab,
            'tabs' => $tabs,
            'doctor' => $doctor,
            // Only the versions the server allows a site of this kind (self:profiles `php`), each old one marked (PhpSupport).
            'phpVersions' => $tab === 'php' && $site !== null ? \Larena\Env\Software\PhpSupport::forSite($this->profileOf($actor, $site->profile, $capabilities),
                $overview->phpVersions ?? [], $site->php) : [],
            // «Восстановить из архива» (simai-env 1.57.1): the archives and dumps found in the site's root.
            'restoreCopies' => $tab === 'overview' && in_array(SiteOperation::PhpRestore, $operations, true) ? $this->restoreCopies($actor, $domain, $capabilities) : null,
            'phpSettings' => $ask('site:php-ini-list', 'php') ? $this->extras->phpSettings($actor, $domain) : null,
            'phpExtensions' => $ask('php:extensions', 'php') && $site->php !== 'none' ? $this->extras->phpExtensions($actor, $site->php) : null,
            'databases' => $ask('db:list', 'database') ? $this->extras->databases($actor, $domain) : null,
            // The site's database browser (simai-env 1.58.0): open until when, or what stops it, said before anyone asks.
            'dbBrowser' => $site !== null && $tab === 'database' && $capabilities->allows('site:db-browser') ? [
                'state' => $capabilities->allows('site:db-browser-status') ? $this->extras->dbBrowser($actor, $domain) : null,
                'refusal' => \Larena\Env\Server\DbBrowserState::refusal($site),
                'certificate' => $capabilities->allows(SiteOperation::CertificateIssue->command()),
            ] : null,
            'sftpAccounts' => $sftpAccounts,
            // The domain's records through the panel's DNS provider, and whether it points here (Panel plan, stage 7).
            // The site's files (simai-env 1.39.0–1.40.0): a folder or a file open in the editor.
            'files' => $site !== null && $tab === 'files' ? $this->filesTab($request, $actor, $site, $capabilities) : null,
            // The site's own nginx directives (simai-env 1.41.0), the system level's.
            'nginx' => is_array($nginx) ? $nginx : null,
            'nginxProblem' => is_string($nginx) ? $this->filesReasonText($nginx) : null,
            'nginxSupported' => $capabilities->allows('site:nginx-custom-show'),
            'nginxAllows' => ['save' => $capabilities->allows('site:nginx-custom'),
                'keep' => $capabilities->allows('change:confirm')],
            'siteDns' => $site !== null && $tab === 'domains' ? $this->dns?->forSite($domain) : null,
            // Where the domain must lead, said with the server's real address beside a missing certificate (block 4).
            'serverIp' => $site !== null && $tab === 'domains' && $site->certificate === null ? $this->extras->serverIp($actor, $capabilities) : null,
            // The site's own scheduled jobs (Panel plan 5.4), and the runs of one of them when it is opened.
            'cron' => $site !== null && $tab === 'cron' ? $this->cronTab($request, $actor, $domain, $capabilities) : null,
            'wholeServer' => $actor->owner === null,
            'transferChoices' => $transferChoices,
            // An account's own action chooses among the accounts it suits; with none, the action is not offered.
            'sftpChoices' => array_combine(array_map(static fn (SiteOperation $operation): string => $operation->value, $operations),
                array_map(static fn (SiteOperation $operation): array => $operation->accountChoices($sftpAccounts ?? []), $operations)),
            // What needs attention on this site (Panel plan 4.4), as the sites' list says it.
            'warnings' => $siteWarnings['warnings'] ?? null,
            // The site's backups in one line, as the sites list and the backups page say them (critics of 0.4.1).
            'backupState' => $site !== null && $tab === 'backups' ? $this->backupState($actor, $site, $overview, $capabilities) : null,
            'canAcknowledge' => $capabilities->allows('self:alerts-ack') && $this->canOperate($request),
            'capabilities' => $capabilities,
            // The signed-in person's email: a certificate's mail goes there when its field is left empty (block 4).
            'personEmail' => $tab === 'domains' ? self::personEmail($request) : null,
            // Whether a certificate may be checked through DNS: the server keeps a Cloudflare token (simai-env 1.36.0).
            'dnsCertificates' => $ask('ssl:dns-status', 'domains') && $this->extras->dnsCertificates($actor)?->configured === true,
            'tabOperations' => array_values(array_filter($operations, static fn (SiteOperation $operation): bool => $operation->tab() === $tab
                && !in_array($operation, SiteOperation::NAMES, true) && !in_array($operation, SiteOperation::CHECKS, true) && !$operation->isRouteStep())),
            // Where the site stands on its creation route (simai-env 1.56.0), with its next step through the plan; a server
            // without site:status shows the overview as before, without a refusal.
            'siteRoute' => $site !== null && $tab === 'overview' && $capabilities->allows('site:status')
                ? $this->routeBlock($request, $actor, $site, $capabilities) : null,
            // The fix of a profile's rule that does not pass (simai-env 1.55.1), offered on the rule's own row of «Проверка»
            // only where the server lists site:check-fix; an older server shows the block as before.
            'checkFixes' => $capabilities->allows(SiteOperation::CheckFix->command()),
            // The check's rows with the site's own answer first when it fails: the card's head and its check say one thing.
            'checkProblems' => $site === null || $tab !== 'overview' ? [] : \Larena\Env\Admin\SiteChecks::problems($doctor, $capabilities->allows(SiteOperation::CheckFix->command()), $site),
            // The site's names (simai-env 1.53.0): the block is there only where the server lists site:names, so an older
            // server shows the tab as before, without a refusal; adding and removing go through the plan as every action.
            'siteNames' => $siteNames,
            'namesReasonText' => $siteNames?->reason === null ? null : $this->namesReasonText($siteNames->reason),
            'namesAllows' => ['add' => $capabilities->allows('site:name-add'), 'remove' => $capabilities->allows('site:name-remove')],
            'source' => $result->source,
            'site' => $site,
            'refused' => $result->refused,
            'missing' => $missing,
            'reasonText' => $missing ? (string) $this->translator->get('larena-env::admin.site.missing', ['domain' => $domain])
                : ($result->reason === null ? null : $this->reasonText($result->reason)),
            'logKind' => $kind,
            'logView' => $logView,
            'logViews' => SiteLogView::VIEWS,
            'logLines' => $lines,
            'logLineChoices' => self::LOG_LINES,
            'logSearch' => $search,
            'logGroup' => $group === SiteLogView::PROBLEMS ? '' : $group,
            'logGroups' => $groups,
            'log' => $logRead,
            'logRows' => $logRead?->display($logShown, fn (string $level): string => $this->levelWords($level)) ?? [],
            'logMore' => $logRead !== null && count($logRead->rows) > $logShown ? $request->fullUrlWithQuery(['shown' => $logShown + SiteLogView::PAGE]) : null,
            'logDownload' => route('larena.env.admin.site.log.download', ['domain' => $domain, 'log' => $kind], false),
            'logZone' => $overview?->timezone,
            'logReadAt' => $log?->readAt === null ? null : gmdate('H:i:s', $log->readAt),
            'logReasonText' => $log === null || $log->reason === null ? null : $this->reasonText($log->reason),
            'notice' => $request->session()->get('larena_env_notice'),
            // ?action=<code> opens that action's side panel with the page (a next step that leads to an action, PX-15).
            'openAction' => is_string($request->query('action')) ? $request->query('action') : null,
            // «История» of the site at the bottom of its card (FASTPANEL look §5.3). The server's list is never kept and costs a
            // start of simai-env, so the card is drawn without it and the part fills itself a moment later (larena/admin
            // AdminLiveRegion, asked once): the click stays within the 0.5 s of the UX audit.
            'siteHistory' => $site !== null && $tab === 'overview' && $capabilities->allows('job:list') ? $this->historyPart($request, $actor, $domain) : null,
        ]);
    }

    /** The live part of the card that holds the site's history. */
    public const string HISTORY_PART = 'env-site-history';

    /**
     * The site's history now when the part itself is asked (or the admin cannot fill a part later), else a part to fill.
     *
     * @return array{pending: bool, url: string, rows?: list<array<string, mixed>>, all: string}
     */
    private function historyPart(Request $request, PanelActor $actor, string $domain): array
    {
        $url = route('larena.env.admin.site', ['domain' => $domain], false);
        $later = class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class)
            && \Larena\Admin\Runtime\AdminLiveRegion::asked($request) !== self::HISTORY_PART && $request->query('history') !== '1';

        return $later ? ['pending' => true, 'url' => $url, 'all' => '/admin/history?object='.rawurlencode($domain)]
            : ['pending' => false, 'url' => $url] + $this->siteHistory($actor, $domain);
    }

    /**
     * The holder of the history part (larena/admin AdminLiveRegion): its opening when given whether it is still to fill and
     * the card's address, its closing without; nothing where the admin has no live parts.
     */
    public static function historyHolder(?bool $pending = null, string $url = ''): \Illuminate\Support\HtmlString
    {
        if (!class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class)) {
            return new \Illuminate\Support\HtmlString('');
        }

        return new \Illuminate\Support\HtmlString($pending === null ? \Larena\Admin\Runtime\AdminLiveRegion::close(self::HISTORY_PART)
            : \Larena\Admin\Runtime\AdminLiveRegion::open(self::HISTORY_PART, $pending, $url, '', 1000));
    }

    /** How many of a site's runs its card shows; the rest is in «История». */
    public const int HISTORY_ROWS = 5;

    /**
     * The site's latest runs, newest first, from one page of the server's list (job:list has no filter by site, so its
     * last 100 runs are read and this site's kept): what, how it ended, how long ago and who, each opening its own page;
     * «Вся история» is the admin's «История» narrowed to the site (its object is the domain, EnvHistorySource).
     *
     * @return array{rows: list<array{title: string, state: string, status: string, words: string, ago: ?string, time: string, who: string, href: string, times: int}>, all: string}
     */
    private function siteHistory(PanelActor $actor, string $domain): array
    {
        $page = $this->operations->jobPage($actor, null, 100);
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);
        $rows = [];
        foreach ($page->jobs ?? [] as $status) {
            $over = $status->args['domain'] ?? (($status->object['kind'] ?? null) === 'site' ? $status->object['id'] : null);
            if ($over !== $domain) {
                continue;
            }
            $at = $status->startedAt ?? $status->createdAt;
            $row = [
                'title' => \Larena\Env\Admin\EnvHistorySource::title($status, $this->translator),
                'state' => $status->state->value,
                'status' => match ($status->state) {
                    \Larena\Env\Operations\JobState::Succeeded => 'success',
                    \Larena\Env\Operations\JobState::Running => 'info',
                    \Larena\Env\Operations\JobState::Cancelled => 'warning',
                    default => 'error',
                },
                'words' => \Larena\Env\Admin\JobOutcome::stateWords($status),
                'ago' => \Larena\Env\Server\Moment::ago($at, PanelWarnings::now(), $text),
                'time' => \Larena\Env\Server\Moment::human($at),
                'who' => match ($status->actorKind) {
                    'schedule' => $text('runs.by_schedule'),
                    'server' => $text('runs.by_server'),
                    default => '',
                },
                'href' => route('larena.env.admin.job', ['job' => $status->jobId], false),
            ];
            $rows = self::addRun($rows, $row);
            if (count($rows) > self::HISTORY_ROWS) {
                array_pop($rows);
                break;
            }
        }

        return ['rows' => $rows, 'all' => '/admin/history?object='.rawurlencode($domain)];
    }

    /**
     * A run added to the card's history, newest first: one the same as the row above it (the same action, ended the same,
     * by the same hand) is not a row of its own but one more «×N» of that row, which keeps the newest's time and page
     * (panel audit 2026-11-18: three «Очистка корзины» in 9 hours were three equal rows).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    public static function addRun(array $rows, array $row): array
    {
        $last = $rows === [] ? null : $rows[count($rows) - 1];
        if ($last !== null && $last['title'] === $row['title'] && $last['state'] === $row['state'] && $last['who'] === $row['who']) {
            $rows[count($rows) - 1]['times'] = ($last['times'] ?? 1) + 1;

            return $rows;
        }
        $rows[] = $row + ['times' => 1];

        return $rows;
    }

    /**
     * The «Создание сайта» block: the route and its steps from the server's own description of the kind, the step the site
     * stands on, and the next step: a link the browser opens, a section of the card, or an action planned and confirmed.
     *
     * @return array<string, mixed>|null
     */
    private function routeBlock(Request $request, PanelActor $actor, \Larena\Env\Server\SiteDetails $site, PanelCapabilities $capabilities): ?array
    {
        $status = $this->routes?->status($actor, $site->domain);
        if ($status === null || !$status->worthShowing()) {
            return null;
        }
        $profile = null;
        foreach ($capabilities->allows('self:profiles') ? ($this->profiles?->profiles($actor) ?? []) : [] as $candidate) {
            if ($candidate->name === ($status->profile ?? $site->profile)) {
                $profile = $candidate;
            }
        }
        $route = $profile?->route($status->route);
        // A plain PHP site waiting for its files with an archive already in its folder: the next step is «Восстановить из
        // архива», on the kind's restore route (the server's status does not tell this route from a new site's yet).
        $copies = null;
        $restoring = false;
        if (($status->profile ?? $site->profile) === 'generic' && in_array($status->next, ['file:upload', 'site:restore-ready'], true)
            && $capabilities->allows(SiteOperation::PhpRestore->command())) {
            $copies = $this->restoreCopies($actor, $site->domain, $capabilities);
            $restoring = ($copies['files'] ?? []) !== [] || $status->next === 'site:restore-ready';
            if ($restoring && $profile?->route('restore') !== null) {
                $route = $profile->route('restore');
            }
        }
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);
        $known = static function (string $key, string $fallback) use ($text): string {
            $line = $text($key);

            return $line !== 'larena-env::admin.'.$key ? $line : $fallback;
        };
        $steps = [];
        foreach ($route->steps ?? [] as $step) {
            $steps[] = ['id' => $step['id'], 'label' => $known('routes.steps.'.\Larena\Env\Admin\RouteWords::stepKey($step['command'], $step['url_from']), $step['title'])];
        }
        $current = $route?->stepIndex($status->step);
        $operation = $status->next !== null ? (SiteOperation::ROUTE_STEPS[$status->next] ?? null) : null;
        if ($restoring) {
            $operation = SiteOperation::PhpRestore;
            foreach ($route->steps ?? [] as $index => $step) {
                $current = $step['command'] === 'site:restore-ready' ? $index : $current;
            }
        }
        if ($operation !== null && !$capabilities->allows($operation->command())) {
            $operation = null;
        }
        // A deploy is led from Git only, where the kind's own route deploys from a repository (Laravel, simai-env 1.56.2);
        // a static release's archive the panel does not send, so that step stays said as done outside the panel.
        if ($operation === SiteOperation::SiteDeploy
            && array_filter($profile->routes ?? [], static fn (\Larena\Env\Server\SiteRoute $one): bool => $one->deploysFromGit()) === []) {
            $operation = null;
        }
        $kept = $request->hasSession() ? $request->session()->get(NewSiteController::ROUTE_VALUES.'.'.strtolower($site->domain)) : null;

        return [
            'status' => $status,
            'route' => $restoring ? $known('routes.names_of.generic_restore', $route->title ?? 'restore') : $known('routes.names.'.$status->route, $route->title ?? $status->route),
            'steps' => $steps,
            'current' => $status->step === 'done' ? count($steps) : $current,
            'stepText' => $restoring ? $known('routes.at.restore', $status->step) : $known('routes.at.'.$status->step, $status->step),
            'nextText' => $restoring ? $known('routes.steps.site_restore_ready', 'site:restore-ready') : ($status->next === null ? null : $known('routes.steps.'.\Larena\Env\Admin\RouteWords::stepKey($status->next, $status->restoreUrl !== null ? 'restore_url' : 'installer_url'), $status->next)),
            'openUrl' => $status->openUrl(),
            // A step the panel does not lead here (a release archive): said, and done outside the panel.
            'outside' => !$restoring && $status->next !== null && !in_array($status->next, ['open_url', 'file:upload', 'cron:add'], true) && $operation === null,
            // A step done in another section of the card: the files of the site, its scheduled jobs.
            'tab' => $restoring ? null : match ($status->next) {'file:upload' => 'files', 'cron:add' => 'cron', default => null},
            'operation' => $operation,
            'fields' => $operation === null ? [] : array_values(array_filter($operation->fields(), static fn (string $field): bool => $capabilities->takes($operation->command(), $field))),
            // What the person gave when the site was made (a WordPress title, a repository), offered again at its step.
            // WordPress's language follows the panel's (block 4): ru_RU for a person reading Russian, unless they typed one.
            'values' => (is_array($kept) ? array_filter($kept, 'is_string') : []) + ($operation === SiteOperation::WordpressInstall && str_starts_with($this->translator->getLocale(), 'ru') ? ['locale' => 'ru_RU'] : []),
            'copies' => $restoring ? $copies : ($operation === SiteOperation::WordpressRestoreReady && $capabilities->allows('wp:status') ? $this->routes->wordpressCopies($actor, $site->domain) : null),
            'pending' => array_map(static fn (string $item): string => \Larena\Env\Server\SiteDoctor::checkText($item, $item), $status->pending),
        ];
    }

    /** The server's kind of this site (self:profiles), for the PHP versions it allows; none from a server without it. */
    private function profileOf(PanelActor $actor, string $name, PanelCapabilities $capabilities): ?\Larena\Env\Server\SiteProfile
    {
        foreach ($capabilities->allows('self:profiles') ? ($this->profiles?->profiles($actor) ?? []) : [] as $profile) {
            if ($profile->name === $name) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * The files of the site's root a restore can take (site:restore-ready): archives of files and database dumps, by the
     * names the server accepts. Null where the server cannot list the site's files.
     *
     * @return array{files: list<string>, dumps: list<string>}|null
     */
    private function restoreCopies(PanelActor $actor, string $domain, PanelCapabilities $capabilities): ?array
    {
        if (!$capabilities->allows('file:list') || $this->files === null) {
            return null;
        }
        $root = $this->files->folder($actor, $domain, '.');
        if (!is_array($root)) {
            return null;
        }
        $files = [];
        $dumps = [];
        foreach ($root['entries'] as $entry) {
            if ($entry->type !== 'file') {
                continue;
            }
            $name = $entry->name;
            // Only the names the command table sends as a site's file.
            if (preg_match('/\A[A-Za-z0-9._-]{1,255}\z/D', $name) !== 1) {
                continue;
            }
            if (preg_match('/\.sql(?:\.gz|\.zip)?\z/i', $name) === 1) {
                $dumps[] = $name;
            } elseif (preg_match('/\.(?:zip|tar\.gz|tgz|tar)\z/i', $name) === 1) {
                $files[] = $name;
            }
        }

        return ['files' => $files, 'dumps' => $dumps];
    }

    /**
     * A refusal of a name's action in words: the names' own first (a name taken by another site, a name the server does
     * not take or one past the twenty a site may have), then the gateway's.
     */
    public function namesReasonText(string $reason): string
    {
        $key = 'larena-env::admin.names.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : $this->reasonText($reason);
    }

    /**
     * The site's backup state by SiteBackupSummary's one rule: from the server's list of sites, as the sites list reads
     * it, else from the site's own description; a failure from the server's alerts.
     *
     * @return array{domain: string, state: string, last: ?string, failed: ?string, scheduled: bool, words: string, color: string, schedule: string}
     */
    private function backupState(PanelActor $actor, \Larena\Env\Server\SiteDetails $site, ?\Larena\Env\Server\ServerOverview $overview, PanelCapabilities $capabilities): array
    {
        $summary = null;
        foreach ($overview->sites ?? [] as $candidate) {
            if (strcasecmp($candidate->domain, $site->domain) === 0) {
                $summary = $candidate;
            }
        }
        if ($summary === null) {
            $times = array_filter(array_map(static fn (\Larena\Env\Server\SiteBackup $backup): ?string => $backup->createdAt, $site->backups), 'is_string');
            rsort($times);
            $summary = new \Larena\Env\Server\SiteSummary($site->domain, $site->profile, $site->php, $site->ssl, $site->owner, $site->databaseEngine, $site->release, null,
                backupSchedule: $site->backupSchedule !== null, lastBackup: $times[0] ?? null);
        }
        $failures = SiteBackupSummary::failures($capabilities->allows('self:alerts') ? $this->extras->alerts($actor) : null);

        return SiteBackupSummary::row($summary, PanelWarnings::now(), $failures,
            fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace));
    }

    /**
     * What the «Файлы» tab shows: nothing but a sentence on a server without the file commands; otherwise a folder as the
     * admin's list, or a file: its text in the editor, a picture in place, a file with passwords behind a question.
     * Opened plainly it is the folder visitors see (PX-91), with the sentence that says so; the server's own entries of
     * the site's root (releases, the current release, the trash) are folded. Removing goes to the site's trash where the
     * server keeps one (simai-env 1.57.0), and the trash is listed below with «Вернуть».
     *
     * @return array<string, mixed>
     */
    private function filesTab(Request $request, PanelActor $actor, \Larena\Env\Server\SiteDetails $site, PanelCapabilities $capabilities): array
    {
        $domain = $site->domain;
        if (!$capabilities->allows('file:list') || $this->files === null) {
            return ['supported' => false];
        }
        $allows = [];
        foreach (SiteFilesController::FILE_OPERATIONS as $op => $command) {
            $allows[$op] = $capabilities->allows($command);
        }
        $allows['download'] = $capabilities->allows('file:download');
        $allows['read'] = $capabilities->allows('file:read');
        $trashKept = $capabilities->allows('file:trash-list');
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);

        // The site's root is read first: it says where the visitors' folder is (public, or a link to the current release).
        $root = $this->files->folder($actor, $domain, '.');
        $visitors = is_array($root) ? self::visitorsFolder($root['entries'], $site->publicDir) : null;
        $path = $request->query('path') === null ? null : FilePath::clean($request->query('path'));
        $view = $request->query('view') === null ? null : FilePath::clean($request->query('view'));
        $open = $request->query('open') === null ? null : FilePath::clean($request->query('open'));
        $folder = null;
        $problem = null;
        if ($open !== null && $open !== '.') {
            // The row's name: a folder opens as a folder, anything else as a file.
            $folder = $this->files->folder($actor, $domain, $open);
            if (is_array($folder)) {
                $path = $open;
            } elseif ($folder === 'not_a_directory' || self::isFile($this->files->stat($actor, $domain, $open))) {
                // Some servers refuse listing a file with a general failure: what the entry is, is asked on its own.
                [$view, $folder] = [$open, null];
            }
        }
        $path ??= $view !== null ? FilePath::parent($view) : ($visitors ?? '.');
        $opened = $view !== null && $view !== '.';
        $image = $opened && FileEntry::imageType($view) !== null;
        $secret = $opened && FileEntry::secret(basename($view));
        // Files with passwords are a right of their own (q-4997ae4e93): the whole server has it, a person of the user level
        // only when it is switched on for them. Without it the file is named, never read, whatever the address asks.
        $secretAllowed = $this->secrets?->allows($actor) ?? $actor->owner === null;
        $allows['secrets'] = $secretAllowed;
        // A file with passwords is shown only when asked for (PX-92), a picture in place (PX-96), any other file as text.
        $revealed = $secret && $secretAllowed && $request->query('reveal') === '1';
        // «Посмотреть» (simai-env 1.61.0 file:preview): a text's first 1 MiB even of a larger file, cut where it is cut;
        // an older server reads the whole text (file:read, up to 1 MiB).
        $previews = $capabilities->allows('file:preview');
        $textRead = null;
        if ($opened && !$image && (!$secret || $revealed) && ($allows['read'] || $previews)) {
            $textRead = $previews ? $this->files->preview($actor, $domain, $view) : $this->files->text($actor, $domain, $view);
            if (is_array($textRead) && ($textRead['kind'] ?? 'text') !== 'text') {
                $textRead = 'not_previewable';
            }
            // Every open of a file with passwords goes to «История»: who, which file of which site, never its text.
            if ($secret && is_array($textRead)) {
                $this->secrets?->opened($actor, $domain, (string) $view);
            }
        }
        if (!$opened && !is_array($folder)) {
            $folder = $path === '.' && is_array($root) ? $root : (is_string($folder) ? $folder : $this->files->folder($actor, $domain, $path));
        }
        foreach ([$folder, $textRead] as $read) {
            if (is_string($read)) {
                $problem = $this->filesReasonText($read);
            }
        }
        // The site's root: the server's entries are folded, the visitors' folder is named.
        $service = [];
        $entries = is_array($folder) ? $folder['entries'] : [];
        if ($path === '.') {
            foreach ($entries as $index => $entry) {
                if (in_array($entry->name, SiteFilesPage::SERVICE, true) || ($entry->name === $site->publicDir && $entry->type === 'link')) {
                    $service[] = $entry;
                    unset($entries[$index]);
                }
            }
            $entries = array_values($entries);
        }
        $token = $request->hasSession() ? (string) $request->session()->token() : '';
        $base = route('larena.env.admin.site', ['domain' => $domain], false).'?tab=files';
        $trash = $trashKept && !$opened ? $this->files->trash($actor, $domain) : null;
        // The container leaves an optional class unbound as its default: the admin's presenter is asked for where the admin is.
        $presenter = $this->dataviews ?? (class_exists(\Larena\Admin\Runtime\AdminDataviewPagePresenter::class)
            ? \Illuminate\Container\Container::getInstance()->make(\Larena\Admin\Runtime\AdminDataviewPagePresenter::class) : null);
        $present = static fn (\Larena\Admin\Runtime\AdminDataviewPage $page): ?array => $presenter?->present($page, \Larena\Admin\Assets\AdminProductAssetManifest::dataviewActivation());
        // A change asked from a row's «⋯» (rename, pack, unpack) opens its form in the side panel with the page.
        $entryOp = in_array($request->query('op'), SiteFilesPage::ENTRY_OPERATIONS, true) && $allows[(string) $request->query('op')]
            ? FilePath::clean($request->query('entry')) : null;

        return [
            'supported' => true,
            // The tab's own address, to which a folder's path or a file's is added.
            'base' => $base,
            'problem' => $problem,
            'path' => $path,
            // The folder visitors see is named so in the path, its real place on the server said under «Подробнее».
            'crumbs' => FilePath::crumbs($path, $visitors, $text('files.visitors_folder')),
            'serverPath' => $visitors !== null && FilePath::within($path, $visitors) ? $path : null,
            'parent' => $path === '.' ? null : FilePath::parent($path),
            'view' => $opened ? $view : null,
            'text' => is_array($textRead) ? $textRead : null,
            'image' => $image ? route('larena.env.admin.site.files.preview', ['domain' => $domain], false).'?path='.rawurlencode((string) $view) : null,
            'secret' => $secret,
            'revealed' => $revealed,
            // Without the right: the refusal in plain words instead of the question, and no way to open or download it.
            'secretRefused' => $secret && !$secretAllowed ? $this->filesReasonText(\Larena\Env\People\SecretFileAccess::REFUSED) : null,
            'folder' => is_array($folder) ? ['entries' => $entries, 'truncated' => $folder['truncated']] : null,
            'visitors' => $visitors,
            'atVisitors' => $visitors !== null && $path === $visitors,
            'visitorsLink' => $visitors !== null && $site->publicDir !== $visitors ? $site->publicDir : null,
            'service' => $service,
            'trashKept' => $trashKept,
            'trash' => is_array($trash) ? $trash : null,
            'trashView' => is_array($trash) && $trash['entries'] !== [] ? $present(SiteFilesPage::trash($domain, $trash['entries'],
                $capabilities->allows('file:trash-restore'), $capabilities->allows('file:trash-empty'), $text, $token, null, $secretAllowed)) : null,
            'trashEmpty' => $capabilities->allows('file:trash-empty'),
            'trashOpen' => $request->query('trash') === '1',
            'list' => is_array($folder) && !$opened ? $present(SiteFilesPage::folder($domain, $path, $entries, $allows, $text, $token, $trashKept)) : null,
            'entryOp' => $entryOp !== null && $entryOp !== '.' ? ['op' => (string) $request->query('op'), 'path' => $entryOp, 'name' => basename($entryOp)] : null,
            'allows' => $allows,
            'formats' => SiteFilesController::ARCHIVE_FORMATS,
        ];
    }

    private static function isFile(FileEntry|string $entry): bool
    {
        return $entry instanceof FileEntry && $entry->type === 'file';
    }

    /**
     * The folder visitors see, from the site's root as the server lists it: `public` itself, or where its link leads
     * (public → current → releases/…), followed only inside the site and only a few steps; null when there is none.
     *
     * @param list<FileEntry> $root
     */
    public static function visitorsFolder(array $root, string $publicDir = 'public'): ?string
    {
        $named = [];
        foreach ($root as $entry) {
            $named[$entry->name] = $entry;
        }
        $at = $publicDir;
        for ($step = 0; $step < 4; $step++) {
            $first = explode('/', $at)[0];
            $entry = $named[$first] ?? null;
            if ($entry === null) {
                return null;
            }
            if ($entry->isDir()) {
                return $at;
            }
            $target = $entry->type === 'link' && is_string($entry->target) && $entry->target !== '' && !str_starts_with($entry->target, '/') ? FilePath::clean($entry->target) : null;
            if ($target === null || $target === '.') {
                return null;
            }
            $at = $target;
        }

        return null;
    }

    /**
     * What the «Планировщик» tab shows: the site's jobs, each schedule in words, the last run, and the runs of one job
     * when it is opened; the changes this server allows and the schedules offered instead of five fields.
     *
     * @return array<string, mixed>
     */
    private function cronTab(Request $request, PanelActor $actor, string $domain, PanelCapabilities $capabilities): array
    {
        $jobs = $this->extras->cronJobs($actor, $domain);
        $open = $request->query('runs');
        $opened = null;
        foreach ($jobs ?? [] as $job) {
            if ($job->id === $open) {
                $opened = $job;
            }
        }
        $canOperate = $this->canOperate($request);
        $allows = [];
        foreach (SiteCronController::OPERATIONS as $op => $command) {
            $allows[$op] = $canOperate && $capabilities->allows($command);
        }

        return [
            'jobs' => $jobs,
            'opened' => $opened,
            'runs' => $opened !== null && $capabilities->allows('cron:job-runs') ? $this->extras->cronRuns($actor, $opened->id) : null,
            'allows' => $allows + ['runs' => $capabilities->allows('cron:job-runs')],
            'presets' => \Larena\Env\Cron\CronSchedule::PRESETS,
            // Each schedule in words; one the panel has no words for is shown as written.
            'when' => array_combine(array_map(static fn (\Larena\Env\Cron\CronJob $job): string => $job->id, $jobs ?? []),
                array_map(fn (\Larena\Env\Cron\CronJob $job): string => $this->scheduleText($job->schedule), $jobs ?? [])),
        ];
    }

    private function scheduleText(string $schedule): string
    {
        $words = \Larena\Env\Cron\CronSchedule::words($schedule);
        if ($words === null) {
            return (string) $this->translator->get('larena-env::admin.cron.as_written', ['schedule' => $schedule]);
        }
        [$key, $replace] = $words;
        if (isset($replace['weekday'])) {
            $replace['weekday'] = (string) $this->translator->get('larena-env::admin.cron.weekdays.'.$replace['weekday']);
        }

        return (string) $this->translator->get('larena-env::admin.cron.presets.'.$key, $replace);
    }

    /** What the action would do, with a confirmation that starts it and nothing else. */
    public function plan(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $operation = $this->offered($actor, (string) $request->input('operation'));
        if ($operation === null) {
            return $this->back($request, $domain, 'not_allowed');
        }
        $input = $this->input($request, $actor, $operation);
        if ($operation === SiteOperation::BitrixRestoreReady && isset($input['source'], $input['url'])) {
            return $this->back($request, $domain, 'source_and_url', $operation->tab(), $operation);
        }
        $result = $this->operations->plan($actor, $operation, $domain, $input);
        if ($result->plan === null) {
            $refused = $this->refusedPlan($operation, $domain, $result);

            return $refused ?? $this->back($request, $domain, $result->reason ?? 'answer_shape_invalid', $operation->tab(), $operation);
        }

        return $this->views->make('larena-env::admin.plan', [
            // «Передать demo.test аккаунту demo»: the question names the site and the account.
            'heading' => $operation === SiteOperation::SiteTransfer && isset($input['owner'])
                ? (string) $this->translator->get('larena-env::admin.transfer.heading', ['domain' => $domain, 'account' => $input['owner']])
                : (string) $this->translator->get('larena-env::admin.operations.'.$operation->value),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.site.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$operation->tab(),
            'hidden' => ['operation' => $operation->value] + $input,
            'replaces' => $this->replaces($actor, $operation, $domain, $input['backup'] ?? null),
            'check' => $result->plan->check,
            'resources' => $result->plan->resources,
            'then' => $operation === SiteOperation::WordpressInstall ? [(string) $this->translator->get('larena-env::admin.routes.password_once')] : [],
            'warnings' => $this->knownWarnings($actor, $operation, $domain, $input),
        ]);
    }

    /**
     * The window of an action the server will not plan, in words (simai-env 1.60.0 `checks`, 1.61.0 named refusals):
     * what stops it — the panel's sentence for a reason it knows (the database browser's no_https, no_driver…), then the
     * server's own blockers, or its detail — above a button that stays off; what is worth knowing below; and where HTTPS
     * is what is missing, «Выпустить сертификат». Null when the server said nothing a person can act on.
     */
    private function refusedPlan(SiteOperation $operation, string $domain, \Larena\Env\Operations\PlanResult $result): mixed
    {
        $reason = $result->reason ?? 'answer_shape_invalid';
        $reasons = [];
        if ($operation === SiteOperation::DatabaseBrowse && in_array($reason, \Larena\Env\Server\DbBrowserState::REFUSALS, true)) {
            $reasons[] = (string) $this->translator->get('larena-env::admin.db_browser.refusals.'.$reason);
        }
        // A site's handing over refused (same_owner, owner_missing, owner_blocked, panel_site): the panel's sentence.
        $transferRefusal = $operation === SiteOperation::SiteTransfer ? \Larena\Env\Admin\AccountMoves::refusal($operation->command(), $reason,
            fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace)) : null;
        if ($transferRefusal !== null) {
            $reasons[] = $transferRefusal;
        }
        $blockers = $result->checks->blockers ?? [];
        $restoreCodes = [];
        // A restore from the site's archive (site:restore-ready): the server's English lines, each said as the panel's
        // sentence; the lines themselves stand under «Подробнее».
        if ($operation === SiteOperation::PhpRestore && $blockers !== []) {
            $restore = \Larena\Env\Admin\RestoreWords::refusals($blockers,
                fn (string $key, array $replace): string => (string) $this->translator->get('larena-env::admin.restore.'.$key, $replace));
            $reasons = $restore['said'];
            $restoreCodes = $restore['codes'];
        }
        // The panel's own sentence says it once; the server's words of the same refusal (with its console command) stand
        // under «Подробнее», not as a second warning (FASTPANEL comparison 2026-11-18).
        $server = '';
        if ($reasons !== []) {
            $server = implode(' ', array_values(array_unique(array_filter(array_map('trim', array_merge($blockers, [$result->detail ?? ''])),
                static fn (string $text): bool => $text !== ''))));
            $blockers = [];
        }
        // A refusal the panel has its own sentence for, and nothing more from the server: said as before, on the tab.
        $key = 'larena-env::admin.reasons.'.$reason;
        $known = (string) $this->translator->get($key) !== $key;
        if ($reasons === [] && $blockers === [] && $known) {
            return null;
        }
        if ($reasons === [] && $known && $blockers !== []) {
            $reasons[] = (string) $this->translator->get($key);
        }
        foreach ($blockers as $blocker) {
            if (!in_array($blocker, $reasons, true)) {
                $reasons[] = $blocker;
            }
        }
        if ($reasons === [] && $result->detail !== null && $result->detail !== '') {
            $reasons[] = $result->detail;
        }
        if ($reasons === [] && $operation === SiteOperation::DatabaseBrowse && $reason === 'plan_failed') {
            // An older server says only plan_failed: what the browser needs, in general.
            $reasons[] = (string) $this->translator->get('larena-env::admin.db_browser.refusals.other');
        }
        if ($reasons === []) {
            return null;
        }
        $site = route('larena.env.admin.site', ['domain' => $domain], false);
        $more = '';
        // An archive or a dump that is not there, or not of its kind: the way to the site's files, where it is uploaded.
        if (array_intersect($restoreCodes, ['missing', 'link', 'not_file', 'files_kind', 'dump_kind', 'files_is_dump']) !== []) {
            $more = '<a href="'.e($site.'?tab=files&path=.').'" class="larena-button" data-env-plan-restore-files>'
                .e((string) $this->translator->get('larena-env::admin.restore.to_files')).'</a>';
        }
        if ($reason === 'no_https') {
            $more = '<a href="'.e($site.'?tab=domains&action='.SiteOperation::CertificateIssue->value).'" class="larena-button larena-button-primary" data-env-plan-certificate>'
                .e((string) $this->translator->get('larena-env::admin.db_browser.certificate')).'</a>';
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.operations.'.$operation->value),
            'eyebrow' => $domain,
            'runUrl' => route('larena.env.admin.site.run', ['domain' => $domain], false),
            'cancelUrl' => $site.'?tab='.$operation->tab(),
            'refused' => $reasons,
            'refusedNotes' => $result->checks->notes ?? [],
            'refusedReason' => $reason,
            'refusedKey' => 'refused-'.$operation->value,
            'refusedMore' => $more,
            'refusedServer' => $server,
        ]);
    }

    /**
     * What the panel already knows would stop the action, said in its plan (PX-14): a certificate checked through the site
     * is not issued while the domain points to another server, so the confirmation waits until it is put right.
     *
     * @param array<string, string> $input
     * @return list<array{text: string, blocking: bool}>
     */
    private function knownWarnings(PanelActor $actor, SiteOperation $operation, string $domain, array $input): array
    {
        if ($operation !== SiteOperation::CertificateIssue || ($input['challenge'] ?? 'http') === 'dns' || $this->names === null) {
            return [];
        }
        foreach ($this->names->read($actor, $domain)->names->names ?? [] as $name) {
            if ($name->kind === \Larena\Env\Server\SiteName::PRIMARY && $name->pointsHere === false) {
                return [['text' => (string) $this->translator->get('larena-env::admin.plan.warnings.domain_elsewhere', ['domain' => $domain]), 'blocking' => true]];
            }
        }

        return [];
    }

    /**
     * What a restore replaces, in words, before it is confirmed (Panel plan 4.2): the site's database by the backup's,
     * after the server saves the current one, or nothing at all for files unpacked for review.
     */
    private function replaces(PanelActor $actor, SiteOperation $operation, string $domain, ?string $name): ?string
    {
        $key = match ($operation) {
            SiteOperation::BackupRestoreDatabase => 'replaces_database',
            SiteOperation::BackupExtractFiles => 'replaces_files',
            default => null,
        };
        if ($key === null) {
            return null;
        }
        $backup = null;
        foreach ($this->sites->read($actor, $domain)->site->backups ?? [] as $candidate) {
            if ($candidate->name === $name) {
                $backup = $candidate;
            }
        }

        return (string) $this->translator->get('larena-env::admin.plan.'.$key, [
            'domain' => $domain,
            'time' => $backup->createdAt !== null ? Moment::human($backup->createdAt) : (string) $name,
            'size' => \Larena\Env\Server\Bytes::human($backup?->sizeBytes),
        ]);
    }

    /** The confirmation itself: the gateway starts the action and the screen follows the job it answers. */
    public function run(Request $request, string $domain): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $operation = $this->offered($actor, (string) $request->input('operation'));
        if ($operation === null) {
            return $this->back($request, $domain, 'not_allowed');
        }
        $started = $this->operations->start($actor, $operation, $domain, $this->input($request, $actor, $operation));
        if ($started->jobId === null) {
            return $this->back($request, $domain, $started->reason ?? 'answer_shape_invalid', $operation->tab(), $operation);
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The action's own fields as the form sent them, left as typed: the command table refuses what the gateway would.
     *
     * @return array<string, string>
     */
    private function input(Request $request, PanelActor $actor, SiteOperation $operation): array
    {
        $input = [];
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        $dns = $operation === SiteOperation::CertificateIssue && $capabilities->allows('ssl:dns-status')
            && $this->extras->dnsCertificates($actor)?->configured === true;
        foreach ($operation->fieldsOn($capabilities) as $field) {
            if (!$dns && in_array($field, SiteOperation::DNS_FIELDS, true)) {
                continue;
            }
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $input[$field] = $value;
            }
        }
        // A certificate's email is optional on the form (block 4): left empty, the signed-in person's own is sent.
        if ($operation === SiteOperation::CertificateIssue && !isset($input['email']) && self::personEmail($request) !== null) {
            $input['email'] = (string) self::personEmail($request);
        }

        return $input;
    }

    /** The signed-in person's email, where their account has a valid one. */
    public static function personEmail(Request $request): ?string
    {
        $user = $request->user();
        $email = is_object($user) ? ($user->email ?? null) : null;
        // A panel account signs in through larena/auth, not Laravel's user: its email is on its identity, read the way the
        // admin reads the person's name (PersistentAdminIdentityLabelResolver); no admin identity, no email.
        $subject = (string) $request->attributes->get('larena_access_actor', '');
        if (!is_string($email) && preg_match('/\Auser:admin_identity:([1-9][0-9]*)\z/D', $subject, $matches) === 1) {
            try {
                $email = \Illuminate\Container\Container::getInstance()->make('db')->connection()
                    ->table('larena_admin_identities')->where('id', (int) $matches[1])->value('email');
            } catch (\Throwable) {
                $email = null;
            }
        }

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }


    /**
     * The breadcrumbs reach the open site and its section, each step a link back (the panel's UX audit, HEAD-02):
     * «Сайты › demo.test › Файлы». The admin draws them where it is installed.
     */
    private function breadcrumbs(Request $request, string $domain, string $tab): void
    {
        if (!defined(\Larena\Admin\View\AdminShellViewComposer::class.'::BREADCRUMB_TAIL')) {
            return;
        }
        $site = ['label' => $domain, 'href' => route('larena.env.admin.site', ['domain' => $domain], false)];
        $request->attributes->set(\Larena\Admin\View\AdminShellViewComposer::BREADCRUMB_TAIL, [
            'parent_href' => route('larena.env.admin.index', [], false),
            'items' => $tab === self::TABS[0] ? [$site]
                : [$site, ['label' => (string) $this->translator->get('larena-env::admin.site.tabs.'.$tab)]],
        ]);
    }

    private function back(Request $request, string $domain, string $reason, string $tab = 'overview', ?SiteOperation $operation = null): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab,
            in_array($operation, SiteOperation::NAMES, true) ? $this->namesReasonText($reason) : $this->reasonText($reason));
    }

    /** @return list<\Larena\Env\Warnings\PanelWarning> */
    /** The action asked for, when it is one this server's gateway has: a form left from an older page is refused in words. */
    private function offered(PanelActor $actor, string $code): ?SiteOperation
    {
        $operation = SiteOperation::tryFrom($code);
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();

        return $operation !== null && $capabilities->allows($operation->command())
            && ($actor->owner === null || !in_array($operation, SiteOperation::SYSTEM_ONLY, true)) ? $operation : null;
    }

    /** A file's reason in words: those of the file commands first (path_outside, exists, ...), then the gateway's. */
    private function filesReasonText(string $reason): string
    {
        $key = 'larena-env::admin.files.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : $this->reasonText($reason);
    }

    /** Whether the server lists its sites and this domain is not among them (the panel's own site is left out too). */
    private function unknownSite(PanelActor $actor, string $domain): bool
    {
        $sites = $this->overviews->read($actor)->overview?->sites;

        return $sites !== null && !in_array($domain, array_map(static fn (\Larena\Env\Server\SiteSummary $one): string => $one->domain, $sites), true);
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
