<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Files\FilePath;
use Larena\Env\Files\SiteFiles;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
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

    public static function section(mixed $asked): string
    {
        $asked = is_string($asked) ? $asked : '';

        return self::MERGED[$asked] ?? $asked;
    }

    /** Whether the person may change the server's sites (env.sites.operate), as the routes that change them require. */
    private function canOperate(Request $request): bool
    {
        return $this->authorizer?->authorize($request, 'env.sites.operate')->isAllowed() ?? false;
    }

    public function show(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
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
        $kind = in_array($request->query('log'), self::LOG_KINDS, true) ? (string) $request->query('log') : 'access';
        $lines = in_array((int) $request->query('lines'), self::LOG_LINES, true) ? (int) $request->query('lines') : self::LOG_LINES[0];
        $search = mb_substr(trim((string) $request->query('q')), 0, 128);
        $groups = $kind === 'error' ? SiteLogView::LEVELS : SiteLogView::STATUS_GROUPS;
        $group = in_array($request->query('group'), $groups, true) ? (string) $request->query('group') : '';
        // Each tab asks the gateway only for what it shows.
        // A search goes to the server, which looks through far more lines than are shown, when its release can.
        $match = $search !== '' && preg_match('/\A[ -~]{1,200}\z/D', $search) === 1 && $capabilities->searchesLogs() ? $search : null;
        // «Обновить» reads the server again past the half minute the panel keeps a log's answer (critics 2026-11-07).
        $log = $site === null || $tab !== 'log' ? null : $this->sites->log($actor, $domain, $kind, $lines, $match, $request->query('fresh') === '1');
        $doctor = $site === null || $tab !== 'overview' ? null : $this->sites->doctor($actor, $domain);
        $operations = $site === null ? [] : SiteOperation::offeredFor($site, $capabilities);
        // A site's owner is not offered what the system level alone may do (Apache, simai-env 1.42.0).
        $operations = $actor->owner === null ? $operations : array_values(array_filter($operations,
            static fn (SiteOperation $operation): bool => !in_array($operation, SiteOperation::SYSTEM_ONLY, true)));
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
            'phpVersions' => $tab === 'php' ? ($overview->phpVersions ?? []) : [],
            'phpSettings' => $ask('site:php-ini-list', 'php') ? $this->extras->phpSettings($actor, $domain) : null,
            'phpExtensions' => $ask('php:extensions', 'php') && $site->php !== 'none' ? $this->extras->phpExtensions($actor, $site->php) : null,
            'databases' => $ask('db:list', 'database') ? $this->extras->databases($actor, $domain) : null,
            'sftpAccounts' => $sftpAccounts,
            // The domain's records through the panel's DNS provider, and whether it points here (Panel plan, stage 7).
            // The site's files (simai-env 1.39.0–1.40.0): a folder or a file open in the editor.
            'files' => $site !== null && $tab === 'files' ? $this->filesTab($request, $actor, $domain, $capabilities) : null,
            // The site's own nginx directives (simai-env 1.41.0), the system level's.
            'nginx' => is_array($nginx) ? $nginx : null,
            'nginxProblem' => is_string($nginx) ? $this->filesReasonText($nginx) : null,
            'nginxSupported' => $capabilities->allows('site:nginx-custom-show'),
            'nginxAllows' => ['save' => $capabilities->allows('site:nginx-custom'),
                'keep' => $capabilities->allows('change:confirm')],
            'siteDns' => $site !== null && $tab === 'domains' ? $this->dns?->forSite($domain) : null,
            // The site's own scheduled jobs (Panel plan 5.4), and the runs of one of them when it is opened.
            'cron' => $site !== null && $tab === 'cron' ? $this->cronTab($request, $actor, $domain, $capabilities) : null,
            'wholeServer' => $actor->owner === null,
            // An account's own action chooses among the accounts it suits; with none, the action is not offered.
            'sftpChoices' => array_combine(array_map(static fn (SiteOperation $operation): string => $operation->value, $operations),
                array_map(static fn (SiteOperation $operation): array => $operation->accountChoices($sftpAccounts ?? []), $operations)),
            // What needs attention on this site (Panel plan 4.4), as the sites' list says it.
            'warnings' => $siteWarnings['warnings'] ?? null,
            // The site's backups in one line, as the sites list and the backups page say them (critics of 0.4.1).
            'backupState' => $site !== null && $tab === 'backups' ? $this->backupState($actor, $site, $overview, $capabilities) : null,
            'canAcknowledge' => $capabilities->allows('self:alerts-ack') && $this->canOperate($request),
            'capabilities' => $capabilities,
            // Whether a certificate may be checked through DNS: the server keeps a Cloudflare token (simai-env 1.36.0).
            'dnsCertificates' => $ask('ssl:dns-status', 'domains') && $this->extras->dnsCertificates($actor)?->configured === true,
            'tabOperations' => array_values(array_filter($operations, static fn (SiteOperation $operation): bool => $operation->tab() === $tab
                && !in_array($operation, SiteOperation::NAMES, true))),
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
            'logKinds' => self::LOG_KINDS,
            'logLines' => $lines,
            'logLineChoices' => self::LOG_LINES,
            'logSearch' => $search,
            'logGroup' => $group,
            'logGroups' => $groups,
            'log' => $log?->log === null ? null : SiteLogView::of($log->log, $search, $group),
            'logReadAt' => $log?->readAt === null ? null : gmdate('H:i:s', $log->readAt),
            'logReasonText' => $log === null || $log->reason === null ? null : $this->reasonText($log->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
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
     * What the «Файлы» tab shows: nothing but a sentence on a server without the file commands; otherwise the folder or
     * a file open in the editor, and the changes this server allows (simai-env 1.39.0; upload and archives 1.40.0).
     *
     * @return array<string, mixed>
     */
    private function filesTab(Request $request, PanelActor $actor, string $domain, PanelCapabilities $capabilities): array
    {
        if (!$capabilities->allows('file:list') || $this->files === null) {
            return ['supported' => false];
        }
        $path = FilePath::clean($request->query('path')) ?? '.';
        $view = $request->query('view') === null ? null : FilePath::clean($request->query('view'));
        $allows = [];
        foreach (SiteFilesController::FILE_OPERATIONS as $op => $command) {
            $allows[$op] = $capabilities->allows($command);
        }
        $allows['download'] = $capabilities->allows('file:download');
        $allows['read'] = $capabilities->allows('file:read');
        $opened = $view !== null && $view !== '.' && $allows['read'];

        $text = $opened ? $this->files->text($actor, $domain, $view) : null;
        $folder = $opened ? null : $this->files->folder($actor, $domain, $path);
        $problem = null;
        foreach ([$folder, $text] as $read) {
            if (is_string($read)) {
                $problem = $this->filesReasonText($read);
            }
        }

        return [
            'supported' => true,
            // The tab's own address, to which a folder's path or a file's is added.
            'base' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab=files',
            'problem' => $problem,
            'path' => $opened ? FilePath::parent($view) : $path,
            'crumbs' => FilePath::crumbs($opened ? FilePath::parent($view) : $path),
            'parent' => $path === '.' ? null : FilePath::parent($path),
            'view' => $opened ? $view : null,
            'text' => is_array($text) ? $text : null,
            'folder' => is_array($folder) ? $folder : null,
            'allows' => $allows,
            'formats' => SiteFilesController::ARCHIVE_FORMATS,
        ];
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
        $result = $this->operations->plan($actor, $operation, $domain, $input);
        if ($result->plan === null) {
            return $this->back($request, $domain, $result->reason ?? 'answer_shape_invalid', $operation->tab(), $operation);
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.operations.'.$operation->value),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.site.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$operation->tab(),
            'hidden' => ['operation' => $operation->value] + $input,
            'replaces' => $this->replaces($actor, $operation, $domain, $input['backup'] ?? null),
        ]);
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

        return $input;
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
