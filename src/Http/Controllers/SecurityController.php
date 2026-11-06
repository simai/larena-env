<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Security\GeoStatus;
use Larena\Env\Security\SecurityStatus;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Сервер › Безопасность» (simai-env 1.45.0–1.47.0), the system level's: the server's score and grade, its problems
 * first with «Исправить» where the server can put one right itself, the protection level in one choice, the sites'
 * packages with known vulnerabilities, the sites' files checked for malicious code with the quarantine, the countries and
 * access for AI assistants over MCP (1.49.0). Every change
 * is the server's own command, planned and confirmed; a problem's fix is taken from the server's answer by the check's
 * id on both steps, never from the form.
 */
final readonly class SecurityController
{
    /** The commands a check's fix may name and the panel runs from here; the rest are done on their own screens. */
    public const array FIX_COMMANDS = ['software:update', 'self:firewall-ensure', 'security:fix', 'ssl:renew', 'backup:data-schedule',
        'security:scan', 'security:files-scan', 'security:web-protect'];
    /** The changes of this screen besides a check's fix. */
    public const array OPERATIONS = ['scan' => 'security:scan', 'level' => 'security:level', 'files-scan' => 'security:files-scan',
        'quarantine' => 'security:quarantine', 'restore' => 'security:quarantine-restore',
        'geo' => 'security:geo', 'geo-update' => 'security:geo-update',
        'mcp-enable' => 'mcp:enable', 'mcp-disable' => 'mcp:disable', 'mcp-key-add' => 'mcp:key-add', 'mcp-key-remove' => 'mcp:key-remove'];
    /** The requests one address may send a second and at once when the limit is turned on here (the server's defaults). */
    public const array WEB_PROTECT = ['enabled' => 'yes'];

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function index(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $read = $this->overviews->read($actor);
        $capabilities = $read->overview->capabilities ?? PanelCapabilities::none();
        $supported = $capabilities->allows('security:status');
        $status = $supported ? $this->extras->security($actor) : null;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;
        $allows = ['fix' => $canOperate];
        foreach (self::OPERATIONS as $op => $command) {
            $allows[$op] = $canOperate && $capabilities->allows($command);
        }
        $fixes = [];
        foreach ($status?->problems() ?? [] as $check) {
            $fixes[$check->id] = $canOperate && $this->fixable($check->fixCommand, $check->fixArgs, $capabilities);
        }

        $files = $capabilities->allows('security:files') ? $this->extras->fileScan($actor) : null;
        // Country blocking (simai-env 1.48.0) and «Откуда этот адрес?» answered on the same page.
        $geo = $capabilities->allows('security:geo-status') ? $this->extras->geo($actor) : null;
        $lookup = null;
        $ip = trim((string) $request->query('ip', ''));
        if ($ip !== '' && $capabilities->allows('security:geo-lookup')) {
            try {
                PanelCommands::check('security:geo-lookup', ['ip' => $ip], true);
                $lookup = ['ip' => $ip, 'country' => $this->extras->geoLookup($actor, $ip), 'valid' => true];
            } catch (PanelRefused) {
                $lookup = ['ip' => mb_substr($ip, 0, 64), 'country' => null, 'valid' => false];
            }
        }
        $locale = (string) $this->translator->getLocale();
        $countries = [];
        foreach (GeoStatus::COUNTRIES as $code) {
            $countries[$code] = GeoStatus::name($code, $locale);
        }
        asort($countries, SORT_LOCALE_STRING);

        return $this->views->make('larena-env::admin.security', [
            'source' => $read->source,
            'words' => $status === null ? [] : $this->words($status),
            'engines' => array_map(fn (string $engine): string => $this->known('security.engines.'.$engine) ?? $engine, $files->engines ?? []),
            'supported' => $supported,
            'status' => $status,
            'fixes' => $fixes,
            'allows' => $allows,
            'levels' => SecurityStatus::LEVELS,
            'files' => $files,
            'quarantine' => $capabilities->allows('security:quarantine-list') ? $this->extras->quarantine($actor) : null,
            'filesSupported' => $capabilities->allows('security:files'),
            'geo' => $geo,
            'geoSupported' => $capabilities->allows('security:geo-status'),
            'lookup' => $lookup,
            'lookupSupported' => $capabilities->allows('security:geo-lookup'),
            'countries' => $countries,
            'myAddress' => (string) $request->ip(),
            // Access for AI assistants (simai-env 1.49.0): on or off, the keys and the lines to connect a client.
            'mcp' => $capabilities->allows('mcp:status') ? $this->extras->mcp($actor) : null,
            'mcpSupported' => $capabilities->allows('mcp:status'),
            'owners' => $read->overview->owners ?? [],
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /** What the change would do; the confirmation sends the same op and the server's command runs as a job. */
    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $asked = $this->asked($request, $actor);
        if (is_string($asked)) {
            return $this->back($request, $asked);
        }
        [$command, $args, $hidden] = $asked;
        $result = $this->operations->planCommand($actor, $command, $args);
        if ($result->plan === null) {
            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('security.operations.'.((string) $request->input('op') === 'fix' ? 'fix' : (string) $request->input('op'))),
            'eyebrow' => $this->text('security.title'),
            'target' => $this->text('security.target'),
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.security.run', [], false),
            'cancelUrl' => route('larena.env.admin.security', [], false),
            'hidden' => $hidden,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $asked = $this->asked($request, $actor);
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
     * The command, its arguments and the form's hidden fields for the asked change, or why it is not done.
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, string>}|string
     */
    private function asked(Request $request, PanelActor $actor): array|string
    {
        if (!($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)) {
            return 'not_allowed';
        }
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        $op = (string) $request->input('op');
        if ($op === 'fix') {
            $id = (string) $request->input('check');
            $check = $this->extras->security($actor)?->check($id);
            if ($check === null || !$check->isProblem()) {
                return 'invalid_argument';
            }
            if (!$this->fixable($check->fixCommand, $check->fixArgs, $capabilities)) {
                return 'not_allowed';
            }

            return [(string) $check->fixCommand, (array) $check->fixArgs, ['op' => 'fix', 'check' => $id]];
        }
        $command = self::OPERATIONS[$op] ?? null;
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $args = match ($op) {
            'level' => ['set' => (string) $request->input('set')],
            'files-scan' => $request->filled('domain') ? ['domain' => strtolower((string) $request->input('domain'))] : [],
            'quarantine' => ['domain' => strtolower((string) $request->input('domain')), 'path' => (string) $request->input('path')],
            'restore' => ['id' => (string) $request->input('id')],
            'geo' => self::geoArgs($request),
            'mcp-key-add' => self::mcpKeyArgs($request),
            'mcp-key-remove' => ['fingerprint' => (string) $request->input('fingerprint')],
            default => [],
        };
        if ($op === 'geo' && $args === null) {
            return 'geo_countries_needed';
        }
        if ($op === 'mcp-key-add' && $args === null) {
            return 'mcp_owner_needed';
        }
        try {
            PanelCommands::check($command, $args, true);
        } catch (PanelRefused $refused) {
            return $refused->reasonCode;
        }

        return [$command, $args, ['op' => $op] + $args];
    }

    /**
     * Country blocking's arguments from the form: the mode, and for deny or allow the chosen countries (ticked, or typed
     * as codes), the ports and the addresses never dropped; null when a list is asked for without a country.
     *
     * @return array<string, string>|null
     */
    private static function geoArgs(Request $request): ?array
    {
        $mode = in_array($request->input('mode'), GeoStatus::MODES, true) ? (string) $request->input('mode') : 'off';
        if ($mode === 'off') {
            return ['mode' => 'off'];
        }
        $picked = $request->input('countries');
        $codes = array_values(array_filter(GeoStatus::codes(is_array($picked) || is_string($picked) ? $picked : []),
            static fn (string $code): bool => in_array($code, GeoStatus::COUNTRIES, true)));
        if ($codes === []) {
            return null;
        }
        $args = ['mode' => $mode, 'countries' => implode(',', array_slice($codes, 0, 100)),
            'ports' => $request->input('ports') === 'all' ? 'all' : 'web'];
        $keep = preg_replace('/[\s,;]+/', ',', trim((string) $request->input('keep', '')));
        if (is_string($keep) && trim($keep, ',') !== '') {
            $args['keep'] = trim($keep, ',');
        }

        return $args;
    }

    /**
     * An MCP key's arguments: the public key as pasted (one line, spaces kept), for the whole server or for one owner's
     * sites; null when an owner's key names no owner.
     *
     * @return array<string, string>|null
     */
    private static function mcpKeyArgs(Request $request): ?array
    {
        $key = trim((string) preg_replace('/\s+/', ' ', (string) $request->input('pubkey')));
        if ($request->input('role') === 'administrator') {
            return ['pubkey' => $key, 'admin' => 'yes'];
        }
        $owner = strtolower(trim((string) $request->input('owner')));

        return $owner === '' ? null : ['pubkey' => $key, 'owner' => $owner];
    }

    /**
     * Each check in the panel's words by its kind: the title, the line for how it stands, and how to put it right; a
     * kind the panel does not know keeps the server's own words.
     *
     * @return array<string, array{title: string, line: string, how: string}>
     */
    public function words(SecurityStatus $status): array
    {
        $words = [];
        foreach ($status->checks as $check) {
            $base = 'security.checks.'.$check->check.'.';
            $line = $this->known($base.$check->status) ?? ($check->isProblem() ? $this->known($base.'warn') : null);
            $words[$check->id] = [
                'title' => $this->known($base.'title') ?? $check->title ?? $check->check,
                'line' => $line ?? $check->detail ?? '',
                'how' => $this->known($base.'how') ?? $check->how ?? '',
            ];
        }

        return $words;
    }

    /** The panel's line for the key, or null when it has none. */
    private function known(string $key): ?string
    {
        $line = $this->translator->get('larena-env::admin.'.$key);

        return is_string($line) && $line !== 'larena-env::admin.'.$key ? $line : null;
    }

    /** @param array<string, string>|null $args */
    private function fixable(?string $command, ?array $args, PanelCapabilities $capabilities): bool
    {
        if ($command === null || !in_array($command, self::FIX_COMMANDS, true) || !$capabilities->allows($command)) {
            return false;
        }
        try {
            PanelCommands::check($command, $args ?? [], true);
        } catch (PanelRefused) {
            return false;
        }

        return true;
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return NoticeRedirect::to($request, route('larena.env.admin.security', [], false),
            $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]));
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
