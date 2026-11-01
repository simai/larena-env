<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Files\FileModes;
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
     * The card's tabs (Panel plan 2.3 and 3): its state and problems, domains and certificate, PHP, its database, its
     * backups, releases, log, access; its domain's DNS records (stage 7); its files and, for the system level only, its
     * own nginx directives (stage 6).
     */
    public const array TABS = ['overview', 'domains', 'dns', 'php', 'database', 'backups', 'files', 'releases', 'log', 'access', 'nginx'];
    /** The tabs only the system level has. */
    private const array SYSTEM_TABS = ['nginx'];
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
    ) {
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
        $tabs = $actor->owner === null ? self::TABS : array_values(array_diff(self::TABS, self::SYSTEM_TABS));
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : self::TABS[0];
        $kind = in_array($request->query('log'), self::LOG_KINDS, true) ? (string) $request->query('log') : 'access';
        $lines = in_array((int) $request->query('lines'), self::LOG_LINES, true) ? (int) $request->query('lines') : self::LOG_LINES[0];
        $search = mb_substr(trim((string) $request->query('q')), 0, 128);
        $groups = $kind === 'error' ? SiteLogView::LEVELS : SiteLogView::STATUS_GROUPS;
        $group = in_array($request->query('group'), $groups, true) ? (string) $request->query('group') : '';
        // What this server's gateway allows, and its PHP versions: the card offers only what will not be refused.
        $overview = $site === null ? null : $this->overviews->read($actor)->overview;
        $capabilities = $overview->capabilities ?? PanelCapabilities::none();
        // Each tab asks the gateway only for what it shows.
        // A search goes to the server, which looks through far more lines than are shown, when its release can.
        $match = $search !== '' && preg_match('/\A[ -~]{1,200}\z/D', $search) === 1 && $capabilities->searchesLogs() ? $search : null;
        $log = $site === null || $tab !== 'log' ? null : $this->sites->log($actor, $domain, $kind, $lines, $match);
        $doctor = $site === null || $tab !== 'overview' ? null : $this->sites->doctor($actor, $domain);
        $operations = $site === null ? [] : SiteOperation::offeredFor($site, $capabilities);
        $ask = static fn (string $command, string $onTab): bool => $site !== null && $tab === $onTab && $capabilities->allows($command);
        $sftpAccounts = $ask('access:list', 'access') ? $this->extras->sftpAccounts($actor, $domain) : null;
        $overlay = $ask('site:nginx-overlay-get', 'nginx') && $actor->owner === null ? $this->files?->overlay($actor, $domain) : null;
        // What needs attention on this site (Panel plan 4.4): the server's own alerts where it keeps them, as the sites' list says it.
        $siteWarnings = $overview === null ? null : ($this->warningSource ?? new PanelWarningSource($this->extras))->ofSite($actor, $overview, $domain, PanelWarnings::now());

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
            // The site's files (Panel plan 6.1): a folder, a file open in the editor, or the trash.
            'files' => $site !== null && $tab === 'files' ? $this->filesTab($request, $actor, $domain, $capabilities) : null,
            'overlay' => is_array($overlay) ? $overlay : null,
            'overlayProblem' => is_string($overlay) ? $this->filesReasonText($overlay) : null,
            'overlaySupported' => $capabilities->allows('site:nginx-overlay-get'),
            'overlayAllows' => ['set' => $capabilities->allows('site:nginx-overlay-set'), 'clear' => $capabilities->allows('site:nginx-overlay-clear'),
                'confirm' => $capabilities->allows('change:confirm')],
            'siteDns' => $site !== null && $tab === 'dns' ? $this->dns?->forSite($domain) : null,
            'wholeServer' => $actor->owner === null,
            // An account's own action chooses among the accounts it suits; with none, the action is not offered.
            'sftpChoices' => array_combine(array_map(static fn (SiteOperation $operation): string => $operation->value, $operations),
                array_map(static fn (SiteOperation $operation): array => $operation->accountChoices($sftpAccounts ?? []), $operations)),
            // What needs attention on this site (Panel plan 4.4), as the sites' list says it.
            'warnings' => $siteWarnings['warnings'] ?? null,
            'fromServer' => $siteWarnings['fromServer'] ?? false,
            'warningsBack' => $request->getRequestUri(),
            'canAcknowledge' => $capabilities->allows('self:alerts-ack') && $this->canOperate($request),
            'capabilities' => $capabilities,
            'tabOperations' => array_values(array_filter($operations, static fn (SiteOperation $operation): bool => $operation->tab() === $tab)),
            'source' => $result->source,
            'site' => $site,
            'refused' => $result->refused,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'logKind' => $kind,
            'logKinds' => self::LOG_KINDS,
            'logLines' => $lines,
            'logLineChoices' => self::LOG_LINES,
            'logSearch' => $search,
            'logGroup' => $group,
            'logGroups' => $groups,
            'log' => $log?->log === null ? null : SiteLogView::of($log->log, $search, $group),
            'logReasonText' => $log === null || $log->reason === null ? null : $this->reasonText($log->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /**
     * What the «Файлы» tab shows: nothing but a sentence on a server without the file commands; otherwise the folder,
     * a file open in the editor, or the trash, and the changes this server allows.
     *
     * @return array<string, mixed>
     */
    private function filesTab(Request $request, PanelActor $actor, string $domain, PanelCapabilities $capabilities): array
    {
        if (!$capabilities->allows('fs:list') || $this->files === null) {
            return ['supported' => false];
        }
        $path = FilePath::clean($request->query('path')) ?? '.';
        $view = $request->query('view') === null ? null : FilePath::clean($request->query('view'));
        $trash = $request->query('trash') === '1' && $capabilities->allows('fs:trash-list');
        $allows = [];
        foreach (SiteFilesController::FILE_OPERATIONS as $op => $command) {
            $allows[$op] = $capabilities->allows($command);
        }
        $allows['download'] = $capabilities->allows('fs:download');
        $allows['read'] = $capabilities->allows('fs:read');
        $allows['trash'] = $capabilities->allows('fs:trash-list');

        $text = $view !== null && $view !== '.' && $allows['read'] ? $this->files->text($actor, $domain, $view) : null;
        $trashed = $trash ? $this->files->trash($actor, $domain) : null;
        $folder = $trash || ($view !== null && $view !== '.') ? null : $this->files->folder($actor, $domain, $path);
        $problem = null;
        foreach ([$folder, $text, $trashed] as $read) {
            if (is_string($read)) {
                $problem = $this->filesReasonText($read);
            }
        }

        return [
            'supported' => true,
            // The tab's own address, to which a folder's path or a file's is added.
            'base' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab=files',
            'problem' => $problem,
            'path' => $path,
            'crumbs' => FilePath::crumbs($path),
            'parent' => $path === '.' ? null : FilePath::parent($path),
            'view' => $view !== null && $view !== '.' && $allows['read'] ? $view : null,
            'text' => is_array($text) ? $text : null,
            'trash' => is_array($trashed) ? $trashed : null,
            'trashOpen' => $trash,
            'folder' => is_array($folder) ? $folder : null,
            'allows' => $allows,
            'fileModes' => FileModes::FILE,
            'dirModes' => FileModes::DIR,
        ];
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
            return $this->back($request, $domain, $result->reason ?? 'answer_shape_invalid', $operation->tab());
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
            return $this->back($request, $domain, $started->reason ?? 'answer_shape_invalid', $operation->tab());
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
        foreach ($operation->fieldsOn($this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none()) as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $input[$field] = $value;
            }
        }

        return $input;
    }


    private function back(Request $request, string $domain, string $reason, string $tab = 'overview'): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab='.$tab, $this->reasonText($reason));
    }

    /** @return list<\Larena\Env\Warnings\PanelWarning> */
    /** The action asked for, when it is one this server's gateway has: a form left from an older page is refused in words. */
    private function offered(PanelActor $actor, string $code): ?SiteOperation
    {
        $operation = SiteOperation::tryFrom($code);
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();

        return $operation !== null && $capabilities->allows($operation->command()) ? $operation : null;
    }

    /** A file's reason in words: those of the file commands first (path_outside, exists, ...), then the gateway's. */
    private function filesReasonText(string $reason): string
    {
        $key = 'larena-env::admin.files.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : $this->reasonText($reason);
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
