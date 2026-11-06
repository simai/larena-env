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
 */
final readonly class BackupStorageController
{
    public const array OPERATIONS = ['add' => 'backup:storage-add', 'test' => 'backup:storage-test', 'remove' => 'backup:storage-remove',
        'fetch' => 'backup:storage-fetch'];
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
        $result = $this->operations->planCommand($actor, $command, $args);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }
        if (isset($args['access-key'], $args['secret-key'])) {
            $hidden['keys_ref'] = $this->keepKeys($request, $args['access-key'], $args['secret-key']);
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('storages.operations.'.(string) $request->input('op')),
            'eyebrow' => $this->text('storages.title'),
            'target' => $args['name'],
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
     * A new storage from the form: the service's endpoint and region unless typed, the folder, the days copies are kept,
     * whether new backups go to it, and the keys as typed.
     *
     * @return array<string, string>
     */
    private static function addArgs(Request $request, string $name): array
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
