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
 * until when it is kept, and the way back: «Вернуть сайт» makes the site again (site:add) and then puts its backup back
 * (backup:data-restore; its files wait in a folder of their own beside the site until ENV puts them in place), «Вернуть
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
            'held' => $capabilities->allows('backup:data-list') ? $this->extras->heldBackups($actor) : null,
            'returns' => $canOperate && $capabilities->allows('backup:data-restore'),
            // Every site's last backup at a glance, the panel's own left out (critics 2026-11-07: /backups showed only storages).
            // A failure named as in the sites list and on the site's tab (critics of 0.4.1: here it read «Копий нет»).
            'siteBackups' => \Larena\Env\Admin\SiteBackupSummary::of($read->overview?->withoutSites([$request->getHost()])->sites ?? [],
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                \Larena\Env\Admin\SiteBackupSummary::failures($capabilities->allows('self:alerts') ? $this->extras->alerts($actor) : null),
                fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace)),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $asked = $this->asked($request);
        if (is_string($asked)) {
            return $this->back($request, $asked);
        }
        [$command, $args, $hidden] = $asked;
        $op = (string) $request->input('op');
        $result = $this->operations->planCommand($actor, $command, $args);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
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
            'then' => in_array($op, self::RETURNS, true) && is_string($step) ? [$this->text('storages.held.then.'.$op.'.'.$step)] : [],
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
            return $this->back($request, $asked);
        }
        [$command, $args] = $asked;
        $started = $this->operations->startCommand($actor, $command, $args);

        return $started->jobId === null
            ? $this->back($request, $started->reason ?? 'answer_shape_invalid')
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
        if ($confirming) {
            $step = (string) $request->input('step');
            $database = (string) $request->input('db') === 'yes';
        } else {
            $actor = ResolvePanelActor::of($request);
            $held = null;
            foreach ($this->extras->heldBackups($actor, $domain) ?? [] as $candidate) {
                if ($candidate->domain === $domain && $candidate->backup->name === $backup) {
                    $held = $candidate;
                }
            }
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
        }
        $command = self::STEPS[$step] ?? null;
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $args = match ($step) {
            'add' => ['domain' => $domain] + ($database ? ['create-db' => 'yes'] : []),
            'create' => ['domain' => $domain],
            default => ['domain' => $domain, 'backup' => $backup] + ($op === 'return-db' ? ['files' => 'no'] : []),
        };
        try {
            PanelCommands::check($command, $args, true);
        } catch (PanelRefused $refused) {
            return $refused->reasonCode;
        }

        return [$command, $args, ['op' => $op, 'step' => $step, 'domain' => $domain, 'backup' => $backup, 'db' => $database ? 'yes' : 'no']];
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

    private function back(Request $request, string $reason): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return NoticeRedirect::to($request, route('larena.env.admin.storages', [], false),
            $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]));
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
