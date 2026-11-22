<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Backup\BackupStorage;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Throwable;

/**
 * «Сервер › Хранилища копий» (simai-env 1.44.0), the administrator's: the S3 storages the sites' backups are copied to
 * away from the server, a storage to add (a service chosen from a list fills its endpoint and region), its test and
 * removal, and the copies kept in one with «Забрать на сервер», after which the site's backups restore it. Every change is
 * planned and confirmed; the keys go to the server only, through the session encrypted between the plan and its
 * confirmation, never into the page.
 *
 * Above them the backups the server holds (simai-env 1.64.0), those of removed sites too, with why each was made and
 * until when it is kept, and the way back: «Вернуть сайт» makes the site again (site:add, the same domain, its kind where
 * the server names it, a database where the backup holds one) and then puts its backup back, the files straight into the
 * new, empty site (backup:data-restore files-live, simai-env 1.65.0; refused root_not_empty, said in words), «Вернуть
 * базу» makes the database again (site:db-create) and then puts the backup's database back (files no). Each step is
 * its own plan and confirmation; the page decides the next step from what the server has now.
 */
final readonly class BackupStorageController
{
    public const array OPERATIONS = ['add' => 'backup:storage-add', 'test' => 'backup:storage-test', 'remove' => 'backup:storage-remove',
        'fetch' => 'backup:storage-fetch'];
    /** The way back of a held backup: its two operations, each step a command of its own. */
    public const array RETURNS = ['return-site', 'return-db'];
    private const array STEPS = ['add' => 'site:add', 'create' => 'site:db-create', 'restore' => 'backup:data-restore'];
    /** Kinds a backup cannot make again by itself: a redirect needs its target, a proxy its port. */
    private const array NOT_RETURNED = ['redirect', 'proxy'];
    /** The kinds every server makes, with their database, where the server does not list its own (self:profiles). */
    private const array FALLBACK_KINDS = ['static' => 'no', 'generic' => 'optional', 'laravel' => 'optional', 'wordpress' => 'required'];
    /** The server's reasons that say nothing of the step: a value it did not take, an answer the panel could not read. */
    private const array BARE_REASONS = ['invalid_argument', 'answer_shape_invalid', 'plan_failed', 'command_failed'];
    private const string KEYS = 'larena_env_storage_keys';
    private const int KEYS_SECONDS = 900;

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private StringEncrypter $encrypter,
        private ?AccessOperationAuthorizer $authorizer = null,
        private PlanReceipts $receipts = new PlanReceipts(),
        // The site a held backup goes back to: whether it has its database again.
        private ?\Larena\Env\Server\SiteDetailsReader $sites = null,
    ) {
    }

    public function index(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $read = $this->overviews->read($actor);
        $capabilities = $read->overview->capabilities ?? PanelCapabilities::none();
        $supported = $capabilities->allows('backup:storage-list');
        $storages = $supported ? $this->extras->backupStorages($actor) : null;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;
        $allows = [];
        foreach (self::OPERATIONS as $op => $command) {
            $allows[$op] = $canOperate && $capabilities->allows($command);
        }
        $open = null;
        $copies = null;
        $asked = $request->query('storage');
        foreach ($storages ?? [] as $storage) {
            if ($storage->name === $asked) {
                $open = $storage;
            }
        }
        if ($open !== null && $capabilities->allows('backup:storage-copies')) {
            $copies = $this->extras->backupCopies($actor, $open->name);
        }

        return $this->views->make('larena-env::admin.backup-storages', [
            'source' => $read->source,
            'supported' => $supported,
            'storages' => $storages,
            'open' => $open,
            'copies' => $copies,
            'allows' => $allows,
            'providers' => array_keys(BackupStorage::PROVIDERS),
            'heldSupported' => $capabilities->allows('backup:data-list'),
            // The panel's own copies left out, as in the sites' list below (its own update keeps them).
            'held' => $capabilities->allows('backup:data-list') ? $this->withoutPanel($this->extras->heldBackups($actor), $request->getHost()) : null,
            'returns' => $canOperate && $capabilities->allows('backup:data-restore'),
            // «Вернуть сайт» puts the files back into the site itself: only where the server takes files-live (1.65.0).
            'returnsSite' => $canOperate && $capabilities->takes('backup:data-restore', 'files-live'),
            // Every site's last backup at a glance, the panel's own left out (critics 2026-11-07: /backups showed only storages).
            // A failure named as in the sites list and on the site's tab (critics of 0.4.1: here it read «Копий нет»).
            'siteBackups' => \Larena\Env\Admin\SiteBackupSummary::of($read->overview?->withoutSites([$request->getHost()])->sites ?? [],
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                \Larena\Env\Admin\SiteBackupSummary::failures($capabilities->allows('self:alerts') ? $this->extras->alerts($actor) : null),
                fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace)),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /**
     * @param list<\Larena\Env\Backup\HeldBackup>|null $held
     * @return list<\Larena\Env\Backup\HeldBackup>|null
     */
    private function withoutPanel(?array $held, string $host): ?array
    {
        return $held === null ? null : array_values(array_filter($held, static fn (\Larena\Env\Backup\HeldBackup $one): bool => strcasecmp($one->domain, $host) !== 0));
    }

    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        // «Вернуть сайт» of a removed site asks its kind and its database first (a backup may not name its kind: simai-env
        // 1.65.0's backup:data-list does not, while its gateway takes no site:add without one).
        $choose = $this->choice($request);
        if ($choose !== null) {
            return $choose;
        }
        $asked = $this->asked($request);
        if (is_string($asked)) {
            return $this->back($request, $asked, $this->wayContext($request));
        }
        [$command, $args, $hidden] = $asked;
        $op = (string) $request->input('op');
        $result = $this->operations->planCommand($actor, $command, $args);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid', $this->wayContext($request, $hidden));
        }
        if (isset($args['access-key'], $args['secret-key'])) {
            $hidden['keys_ref'] = $this->keepKeys($request, $args['access-key'], $args['secret-key']);
        }
        // The receipt holds what the confirmation sends back, the keys by their reference: checked before the keys are taken.
        $hidden[PlanReceipts::FIELD] = $this->receipts->issue($request, $command, $hidden);

        $step = $hidden['step'] ?? null;

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('storages.operations.'.$op),
            'eyebrow' => $this->text('storages.title'),
            'target' => $args['name'] ?? $args['domain'] ?? null,
            // What comes after this step of the way back, in words.
            // The database is named only where the backup holds one.
            'then' => in_array($op, self::RETURNS, true) && is_string($step)
                ? [$this->text('storages.held.then.'.$op.'.'.$step.($op === 'return-site' && $step === 'restore' && ($hidden['db'] ?? 'no') !== 'yes' ? '_files' : ''))] : [],
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.storages.run', [], false),
            'cancelUrl' => route('larena.env.admin.storages', [], false),
            'hidden' => $hidden,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $command = self::OPERATIONS[$op] ?? (in_array($op, self::RETURNS, true) ? (self::STEPS[(string) $request->input('step')] ?? '') : '');
        if (!$this->receipts->confirms($request, $command, $request->except(['_token', PlanReceipts::FIELD]))) {
            // Keys kept for a confirmation that is refused are let go: the plan is made again with them typed.
            $this->takeKeys($request, (string) $request->input('keys_ref'));

            return $this->back($request, PlanReceipts::REFUSED);
        }
        $asked = $this->asked($request, true);
        if (is_string($asked)) {
            return $this->back($request, $asked, $this->wayContext($request));
        }
        [$command, $args, $hidden] = $asked;
        $started = $this->operations->startCommand($actor, $command, $args);

        return $started->jobId === null
            ? $this->back($request, $started->reason ?? 'answer_shape_invalid', $this->wayContext($request, $hidden))
            : new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The command, its arguments and the confirmation's hidden fields (never the keys), or why it is not done. On the
     * confirmation a new storage's keys come back from the session by their reference.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>}|string
     */
    private function asked(Request $request, bool $confirming = false): array|string
    {
        if (!($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)) {
            return 'not_allowed';
        }
        $capabilities = $this->overviews->read(ResolvePanelActor::of($request))->overview->capabilities ?? PanelCapabilities::none();
        $op = (string) $request->input('op');
        if (in_array($op, self::RETURNS, true)) {
            if ($op === 'return-site' && !$capabilities->takes('backup:data-restore', 'files-live')) {
                return 'not_allowed';
            }

            return $this->way($request, $op, $capabilities, $confirming);
        }
        $command = self::OPERATIONS[$op] ?? null;
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $name = strtolower(trim((string) $request->input('name')));
        $args = match ($op) {
            'add' => self::addArgs($request, $name),
            'fetch' => ['name' => $name, 'domain' => strtolower(trim((string) $request->input('domain'))), 'backup' => (string) $request->input('backup')],
            default => ['name' => $name],
        };
        $hidden = ['op' => $op] + $args;
        if ($op === 'add') {
            if ($confirming) {
                $keys = $this->takeKeys($request, (string) $request->input('keys_ref'));
                if ($keys === null) {
                    return 'storage_keys_missing';
                }
                [$args['access-key'], $args['secret-key']] = $keys;
            }
            if (!isset($args['access-key'], $args['secret-key'])) {
                return 'storage_keys_missing';
            }
            unset($hidden['access-key'], $hidden['secret-key']);
        }
        try {
            PanelCommands::check($command, $args, true);
        } catch (PanelRefused $refused) {
            return $refused->reasonCode;
        }

        return [$command, $args, $hidden];
    }

    /**
     * The next step back of a held backup: a removed site made again, then its backup put back; a dropped database made
     * again, then the backup's database put back. On the plan the step follows from what the server has now; on the
     * confirmation it is the one the receipt holds.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>}|string
     */
    private function way(Request $request, string $op, PanelCapabilities $capabilities, bool $confirming): array|string
    {
        $domain = strtolower(trim((string) $request->input('domain')));
        $backup = (string) $request->input('backup');
        $profile = null;
        $createDb = false;
        if ($confirming) {
            $step = (string) $request->input('step');
            $database = (string) $request->input('db') === 'yes';
            $profile = is_string($request->input('profile')) && $request->input('profile') !== '' ? (string) $request->input('profile') : null;
            $createDb = (string) $request->input('create-db') === 'yes';
        } else {
            $actor = ResolvePanelActor::of($request);
            $held = $this->held($actor, $domain, $backup);
            if ($held === null) {
                return 'backup_unknown';
            }
            $database = $held->holdsDatabase();
            if ($op === 'return-db' && !$database) {
                return 'backup_unknown';
            }
            $site = $held->siteExists ? $this->sites?->read($actor, $domain)->site : null;
            if ($held->siteExists && $site === null) {
                return 'site_unreadable';
            }
            $step = match (true) {
                $site === null => $op === 'return-site' ? 'add' : 'site_missing',
                $op === 'return-db' && $site->databaseEngine === null => 'create',
                default => 'restore',
            };
            if (!isset(self::STEPS[$step])) {
                return $step;
            }
            if ($step === 'add') {
                // The kind and the database as the person chose them (choice()), the kind one the server makes.
                $kinds = $this->kinds($actor, $capabilities);
                $profile = is_string($request->input('profile')) ? (string) $request->input('profile') : '';
                if (!isset($kinds[$profile])) {
                    return 'return_kind_missing';
                }
                $createDb = match ($kinds[$profile]) {
                    'no' => false,
                    'required' => true,
                    default => (string) $request->input('create-db') === 'yes',
                };
            }
        }
        $command = self::STEPS[$step] ?? null;
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $args = match ($step) {
            // The same domain, the kind chosen (the backup's where the server names it), a database where asked.
            'add' => ['domain' => $domain] + ($profile !== null ? ['profile' => $profile] : []) + ($createDb ? ['create-db' => 'yes'] : []),
            'create' => ['domain' => $domain],
            // The site's files straight into the new, empty site (simai-env 1.65.0), its database with them where the
            // backup holds one: said to the server, so the run says what came back.
            default => ['domain' => $domain, 'backup' => $backup] + ($op === 'return-db' ? ['files' => 'no'] : ['files-live' => 'yes', 'db' => $database ? 'yes' : 'no']),
        };
        try {
            PanelCommands::check($command, $args, true);
        } catch (PanelRefused $refused) {
            return $refused->reasonCode;
        }

        return [$command, $args, ['op' => $op, 'step' => $step, 'domain' => $domain, 'backup' => $backup, 'db' => $database ? 'yes' : 'no']
            + ($step === 'add' && isset($args['profile']) ? ['profile' => $args['profile'], 'create-db' => $createDb ? 'yes' : 'no'] : [])];
    }

    /** The held backup by its domain and name, as the server lists it now; null when it holds none such. */
    private function held(\Larena\Env\Gateway\PanelActor $actor, string $domain, string $backup): ?\Larena\Env\Backup\HeldBackup
    {
        $held = null;
        foreach ($this->extras->heldBackups($actor, $domain) ?? [] as $candidate) {
            if ($candidate->domain === $domain && $candidate->backup->name === $backup) {
                $held = $candidate;
            }
        }

        return $held;
    }

    /**
     * The kinds «Вернуть сайт» may make the site again as, by id with their database (no, optional, required): the
     * server's own list (self:profiles) where it gives one, else the kinds every server makes; a kind that needs more
     * than its domain (a redirect's target, an application's port) is not offered, as the backup cannot say it.
     *
     * @return array<string, string>
     */
    private function kinds(\Larena\Env\Gateway\PanelActor $actor, PanelCapabilities $capabilities): array
    {
        $kinds = [];
        $listed = $capabilities->allows('self:profiles') ? $this->extras->profiles($actor) : null;
        foreach ($listed ?? [] as $profile) {
            if (!in_array($profile->name, NewSiteController::KNOWN_PROFILES, true) || in_array($profile->name, self::NOT_RETURNED, true)) {
                continue;
            }
            foreach ($profile->args as $name => $arg) {
                if ($arg['required'] && !in_array($name, ['domain', 'profile'], true)) {
                    continue 2;
                }
            }
            $kinds[$profile->name] = in_array($profile->db, ['no', 'optional', 'required'], true) ? $profile->db : 'optional';
        }
        if ($listed === null || $listed === []) {
            foreach (self::FALLBACK_KINDS as $name => $db) {
                $kinds[$name] = $db;
            }
        }
        uksort($kinds, static fn (string $a, string $b): int => array_search($a, NewSiteController::KNOWN_PROFILES, true) <=> array_search($b, NewSiteController::KNOWN_PROFILES, true));

        return $kinds;
    }

    /**
     * The question before the first step of «Вернуть сайт»: the kind of the site (the backup's own chosen where it names
     * one) and whether a database is made (as the backup holds one); null when there is nothing to ask — another
     * operation, the confirmation, a site that exists again, or the answer already given.
     */
    private function choice(Request $request): mixed
    {
        if ((string) $request->input('op') !== 'return-site' || $request->has('profile')
            || !($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)) {
            return null;
        }
        $actor = ResolvePanelActor::of($request);
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        if (!$capabilities->takes('backup:data-restore', 'files-live')) {
            return null;
        }
        $domain = strtolower(trim((string) $request->input('domain')));
        $backup = (string) $request->input('backup');
        $held = $this->held($actor, $domain, $backup);
        if ($held === null || $held->siteExists) {
            return null;
        }
        $kinds = $this->kinds($actor, $capabilities);
        $labels = [];
        foreach (array_keys($kinds) as $name) {
            $labels[$name] = $this->text('new_site.profiles.'.$name);
        }

        return $this->views->make('larena-env::admin.held-return', [
            'heading' => $this->text('storages.operations.return-site'),
            'eyebrow' => $this->text('storages.title'),
            'domain' => $domain,
            'backup' => $backup,
            'kinds' => $kinds,
            'labels' => $labels,
            // The backup's own kind preselected where the server names it and makes it.
            'chosen' => $held->profile !== null && isset($kinds[$held->profile]) ? $held->profile : null,
            'known' => $held->profile,
            'database' => $held->holdsDatabase(),
            'planUrl' => route('larena.env.admin.storages.plan', [], false),
            'cancelUrl' => route('larena.env.admin.storages', [], false),
        ]);
    }

    /**
     * Which step of the way back a refusal stops, for its sentence: [op, step, domain], or null for any other operation.
     *
     * @param array<string, string> $hidden
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function wayContext(Request $request, array $hidden = []): ?array
    {
        $op = (string) $request->input('op');
        if (!in_array($op, self::RETURNS, true)) {
            return null;
        }
        $step = (string) ($hidden['step'] ?? $request->input('step', ''));

        return [$op, in_array($step, array_keys(self::STEPS), true) ? $step : ($op === 'return-site' ? 'add' : 'restore'),
            strtolower(trim((string) $request->input('domain')))];
    }

    /**
     * A new storage from the form: the service's endpoint and region unless typed, the folder, the days copies are kept,
     * whether new backups go to it, and the keys as typed.
     *
     * @return array<string, string>
     */
    public static function addArgs(Request $request, string $name): array
    {
        $provider = BackupStorage::PROVIDERS[(string) $request->input('provider')] ?? BackupStorage::PROVIDERS['other'];
        $args = ['name' => $name, 'bucket' => strtolower(trim((string) $request->input('bucket')))];
        $endpoint = rtrim(trim((string) $request->input('endpoint')), '/');
        $endpoint = $endpoint !== '' ? $endpoint : $provider['endpoint'];
        if ($endpoint !== null) {
            $args['endpoint'] = $endpoint;
        }
        $region = strtolower(trim((string) $request->input('region')));
        $region = $region !== '' ? $region : $provider['region'];
        if ($region !== null) {
            $args['region'] = $region;
        }
        $prefix = trim((string) $request->input('prefix'), " /");
        if ($prefix !== '') {
            $args['prefix'] = $prefix;
        }
        $args['keep'] = (string) max(1, min(90, (int) $request->input('keep', 30)));
        $args['default'] = $request->boolean('default') ? 'yes' : 'no';
        foreach (['access-key' => 'access_key', 'secret-key' => 'secret_key'] as $arg => $field) {
            $value = trim((string) $request->input($field));
            if ($value !== '') {
                $args[$arg] = $value;
            }
        }

        return $args;
    }

    private function keepKeys(Request $request, string $access, string $secret): string
    {
        $ref = bin2hex(random_bytes(16));
        $kept = array_filter((array) $request->session()->get(self::KEYS, []),
            static fn (mixed $entry): bool => is_array($entry) && is_int($entry['at'] ?? null) && $entry['at'] > time() - self::KEYS_SECONDS);
        $kept[$ref] = ['at' => time(), 'keys' => $this->encrypter->encryptString((string) json_encode([$access, $secret]))];
        $request->session()->put(self::KEYS, $kept);

        return $ref;
    }

    /** @return array{0: string, 1: string}|null */
    private function takeKeys(Request $request, string $ref): ?array
    {
        $kept = (array) $request->session()->get(self::KEYS, []);
        $entry = $kept[$ref] ?? null;
        unset($kept[$ref]);
        $request->session()->put(self::KEYS, $kept);
        if (!is_array($entry) || !is_int($entry['at'] ?? null) || $entry['at'] <= time() - self::KEYS_SECONDS || !is_string($entry['keys'] ?? null)) {
            return null;
        }
        try {
            $keys = json_decode($this->encrypter->decryptString($entry['keys']), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($keys) && is_string($keys[0] ?? null) && is_string($keys[1] ?? null) ? [$keys[0], $keys[1]] : null;
    }

    /** @param array{0: string, 1: string, 2: string}|null $way the step of the way back the refusal stops */
    private function back(Request $request, string $reason, ?array $way = null): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);
        $said = $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
        // A step of the way back refused for a reason the panel has no sentence of its own for (a value the server did not
        // take, an answer it could not read): which step, over which site, and what to do, then the server's reason.
        if ($way !== null && (in_array($reason, self::BARE_REASONS, true) || $line === $key)) {
            [$op, $step, $domain] = $way;
            $said = $this->text('storages.held.refused.'.$op.'.'.$step, ['domain' => $domain]).' '.$this->text('storages.held.refused.why', ['reason' => rtrim($said, '.')]);
        }

        return NoticeRedirect::to($request, route('larena.env.admin.storages', [], false), $said);
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key, $replace);
    }
}
