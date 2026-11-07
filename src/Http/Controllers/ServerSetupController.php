<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Security\SecurityStatus;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Setup\ServerSetup;
use Larena\Env\Warnings\PanelWarnings;
use Throwable;

/**
 * The server's first setup on the Framework's wizard (sf-wizard 1.0, place pages; owner 2026-11-06): each step is done
 * inside the wizard. A step that changes the server takes its fields, shows the server's plan (review), runs it and
 * shows how it went, with «Повторить» after an error; whatever the server already shows done is passed by itself, and
 * any step can be put off — what is left stays among the overview's recommended actions.
 *
 * Between the plan and its confirmation the step's arguments wait encrypted in the session (keys and tokens included,
 * never in the page), for PENDING_SECONDS.
 */
final readonly class ServerSetupController
{
    /** The name the wizard's footer buttons send their word under (sf-wizard action-name). */
    public const string ACTION = 'wizard-action';

    public const string FINISH = 'finish';

    /** The steps that change the server, with the command each runs. */
    public const array COMMANDS = [
        'address' => 'panel:domain',
        'alerts' => 'self:alerts-channel',
        'protection' => 'security:level',
        'backups' => 'backup:storage-add',
        'updates' => 'self:update',
    ];

    /** The alerts' step sends a test once its channel is set. */
    public const string ALERTS_TEST = 'self:alerts-test';

    private const string SESSION = 'larena_env_setup';
    private const string ERROR = 'larena_env_setup_error';
    private const int PENDING_SECONDS = 900;
    /** How long a confirmation waits for a quick job before the page shows it running. */
    private const int WAIT_MILLISECONDS = 8000;

    public function __construct(
        private ServerSetup $setup,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private PanelOperations $operations,
        private StringEncrypter $encrypter,
        private Translator $translator,
        private Factory $views,
        private Router $router,
        private ?AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function show(Request $request, ?string $step = null): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $capabilities = $this->capabilities($actor);
        $states = $this->states($actor);
        $named = $step;
        $step = $this->step($step, $states);
        // A step the wizard has not goes to the step it opens on, under its own address (critics 2026-11-07).
        if ($named !== null && $named !== $step) {
            return new RedirectResponse(route('larena.env.admin.overview.setup', ['step' => $step], false));
        }
        $flow = $this->flow($request);
        $message = null;
        $phase = $this->phase($request, $step, $states, $capabilities);
        $plan = null;

        // A started change: how it stands now.
        $job = $flow[$step]['job'] ?? null;
        if (is_string($job)) {
            $view = $this->operations->view($actor, $job);
            $status = $view->status;
            if ($status === null) {
                [$phase, $message] = ['error', $this->reason($view->reason ?? 'answer_shape_invalid')];
            } elseif (!$status->state->isOver()) {
                $phase = 'running';
            } elseif ($status->state === JobState::Succeeded) {
                $this->setup->mark($step, ServerSetup::DONE, PanelWarnings::now());
                $states[$step] = ServerSetup::DONE;
                $message = $this->text('setup.done.'.($status->command === self::ALERTS_TEST ? 'test' : $step));
                $this->forget($request, $step);
                $phase = 'result';
            } else {
                [$phase, $message] = ['error', $this->text('setup.failed')];
            }
        } elseif ($phase === 'review') {
            $pending = $this->pending($request, $step);
            $result = $pending === null ? null : $this->operations->planCommand($actor, $pending[0], $pending[1]);
            $plan = $result?->plan;
            if ($plan === null) {
                $this->forget($request, $step);
                [$phase, $message] = ['form', $this->reason($result === null ? 'setup_plan_expired' : ($result->reason ?? 'answer_shape_invalid'))];
            }
        }
        $error = $request->hasSession() ? $request->session()->get(self::ERROR) : null;
        if ($message === null && is_string($error) && $error !== '') {
            $message = $error;
        }

        $page = $this->views->make('larena-env::admin.setup', [
            'step' => $step,
            'phase' => $phase,
            'message' => $message,
            'states' => $states,
            'wizard' => self::wizard($step, $phase, $states, $message, fn (string $key): string => $this->text($key)),
            'overviewUrl' => route('larena.env.admin.overview', [], false),
            'plan' => $plan,
            'job' => is_string($job) ? $job : null,
            'data' => $this->data($actor, $step, $capabilities),
            'capabilities' => $capabilities,
            'loginUrl' => $this->router->has('larena.auth.admin.security.index') ? route('larena.auth.admin.security.index', [], false) : null,
        ]);

        // A change still under way: the browser asks again in a few seconds (no script in the page).
        return $phase === 'running' ? new Response($page->render(), 200, ['Refresh' => '3']) : $page;
    }

    /**
     * Each step's type, state, primary button and message for sf-wizard-step: the current one follows its phase (its
     * fields, the server's plan, the run and how it went), the others their marks.
     *
     * @param array<string, string> $states
     * @param callable(string): string $text
     *
     * @return array<string, array{type: string, state: string, action: string, message: ?string}>
     */
    public static function wizard(string $step, string $phase, array $states, ?string $message, callable $text): array
    {
        $mark = static fn (string $state): string => in_array($state, [ServerSetup::DONE, ServerSetup::SKIPPED], true) ? $state : '';
        $steps = [];
        foreach ($states as $key => $state) {
            $steps[$key] = ['type' => 'info', 'state' => $mark($state), 'action' => '', 'message' => null];
        }
        if (isset($steps[$step])) {
            $steps[$step] = match ($phase) {
                'form' => ['type' => 'form', 'state' => $mark($states[$step]), 'action' => $text('setup.plan')],
                'review' => ['type' => 'review', 'state' => '', 'action' => $text('plan.confirm')],
                'running' => ['type' => 'action', 'state' => 'running', 'action' => $text('plan.confirm')],
                'error' => ['type' => 'action', 'state' => 'error', 'action' => $text('plan.confirm')],
                'result' => ['type' => 'action', 'state' => ServerSetup::DONE, 'action' => $text('plan.confirm')],
                default => ['type' => 'info', 'state' => $mark($states[$step]), 'action' => ''],
            } + ['message' => $message];
        }

        return $steps;
    }

    public function submit(Request $request, string $step): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $states = $this->states($actor);
        if ($step === self::FINISH || !array_key_exists($step, ServerSetup::STEPS)) {
            return new RedirectResponse(route('larena.env.admin.overview', [], false));
        }
        $action = (string) $request->input(self::ACTION, 'next');
        $phase = (string) $request->input('phase', '');
        $flow = $this->flow($request);

        if ($action === 'skip') {
            $this->forget($request, $step);
            $this->setup->mark($step, ServerSetup::SKIPPED, PanelWarnings::now());

            return $this->next($step, $states);
        }
        if ($action === 'edit') {
            $this->forget($request, $step);

            return $this->here($request, $step, ['change' => '1']);
        }
        $command = self::COMMANDS[$step] ?? null;
        if (!($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)) {
            return $this->here($request, $step, [], 'not_allowed');
        }
        // A plan confirmed, or a failed run tried again: the change starts with the arguments the plan was made with.
        if (in_array($action, ['apply', 'run', 'retry'], true) && is_array($flow[$step] ?? null)) {
            $pending = $this->pending($request, $step, true);
            if ($pending === null) {
                $this->forget($request, $step);

                return $this->here($request, $step, ['change' => '1'], 'setup_plan_expired');
            }

            return $this->start($request, $actor, $step, $pending[0], $pending[1]);
        }
        // The alerts' test: no plan, it only sends a message.
        $intent = (string) $request->input('intent', '');
        if ($step === 'alerts' && $intent === 'test') {
            if (!$this->capabilities($actor)->allows(self::ALERTS_TEST)) {
                return $this->here($request, $step, [], 'not_allowed');
            }
            $this->keep($request, $step, self::ALERTS_TEST, []);

            return $this->start($request, $actor, $step, self::ALERTS_TEST, []);
        }
        if ($step === 'updates' && $intent === 'update') {
            $phase = 'form';
        }
        if ($command === null || $phase !== 'form') {
            // A step that only shows (the sign-in's, or one the server already has): read, so it is done.
            if ($states[$step] !== ServerSetup::DONE) {
                $this->setup->mark($step, ServerSetup::DONE, PanelWarnings::now());
            }

            return $this->next($step, $states);
        }

        // The step's fields: the server's plan for them, shown in the step before anything changes.
        if (!$this->capabilities($actor)->allows($command)) {
            return $this->here($request, $step, ['change' => '1'], 'not_allowed');
        }
        $args = $this->args($request, $step);
        if (is_string($args)) {
            return $this->here($request, $step, ['change' => '1'], $args);
        }
        try {
            PanelCommands::check($command, $args, true);
        } catch (PanelRefused $refused) {
            return $this->here($request, $step, ['change' => '1'], $refused->reasonCode);
        }
        $this->keep($request, $step, $command, $args);

        return $this->here($request, $step);
    }

    /** @return array<string, string> */
    public function states(PanelActor $actor): array
    {
        return ServerSetup::states($this->setup->marks(), $this->doneOnServer($actor));
    }

    /** @return array<string, ?string> each step's place in the wizard */
    public function links(): array
    {
        $links = [];
        foreach (array_keys(ServerSetup::STEPS) as $step) {
            $links[$step] = route('larena.env.admin.overview.setup', ['step' => $step], false);
        }

        return $links;
    }

    /**
     * The step's arguments from its fields, or why they do not make one.
     *
     * @return array<string, string>|string
     */
    private function args(Request $request, string $step): array|string
    {
        return match ($step) {
            'address' => ($domain = strtolower(trim((string) $request->input('domain')))) === ''
                ? 'panel_domain_needed'
                : array_filter(['domain' => $domain, 'email' => trim((string) $request->input('email'))], static fn (string $value): bool => $value !== ''),
            'alerts' => self::alertArgs($request),
            'protection' => in_array($request->input('set'), SecurityStatus::LEVELS, true) ? ['set' => (string) $request->input('set')] : 'invalid_argument',
            'backups' => self::storageArgs($request),
            'updates' => ['target' => 'env'],
            default => 'invalid_argument',
        };
    }

    /** @return array<string, string>|string */
    private static function alertArgs(Request $request): array|string
    {
        $kind = (string) $request->input('kind');
        $target = trim((string) $request->input('target'));
        if (!in_array($kind, ['webhook', 'telegram'], true) || $target === '') {
            return 'setup_alerts_needed';
        }
        $args = ['kind' => $kind, 'target' => $target];
        $token = trim((string) $request->input('token'));
        if ($kind === 'telegram' && $token !== '') {
            $args['token'] = $token;
        }

        return $args;
    }

    /** @return array<string, string>|string */
    private static function storageArgs(Request $request): array|string
    {
        $name = strtolower(trim((string) $request->input('name')));
        $args = BackupStorageController::addArgs($request, $name !== '' ? $name : (string) $request->input('provider', 'storage'));

        return isset($args['access-key'], $args['secret-key']) ? $args : 'storage_keys_missing';
    }

    /** Starts the change and waits a few seconds for a quick one, so its outcome shows at once. */
    private function start(Request $request, PanelActor $actor, string $step, string $command, array $args): RedirectResponse
    {
        $started = $this->operations->startCommand($actor, $command, $args);
        if ($started->jobId === null) {
            return $this->here($request, $step, [], $started->reason ?? 'answer_shape_invalid');
        }
        $flow = $this->flow($request);
        $flow[$step]['job'] = $started->jobId;
        $request->session()->put(self::SESSION, $flow);
        $deadline = microtime(true) + self::WAIT_MILLISECONDS / 1000;
        while (microtime(true) < $deadline) {
            $status = $this->operations->view($actor, $started->jobId)->status;
            if ($status === null || $status->state->isOver()) {
                break;
            }
            usleep(400_000);
        }

        return $this->here($request, $step);
    }

    /** What the step shows: its fields (form), the plan (review), the run (running, error, result) or what is there (info). */
    private function phase(Request $request, string $step, array $states, PanelCapabilities $capabilities): string
    {
        $command = self::COMMANDS[$step] ?? null;
        if ($command === null) {
            return 'info';
        }
        if ($this->pending($request, $step) !== null) {
            return 'review';
        }
        if (!$capabilities->allows($command)) {
            return 'info';
        }
        if ($step === 'updates') {
            return 'info';
        }
        if ($states[$step] === ServerSetup::DONE && $request->query('change') !== '1') {
            return 'info';
        }

        return 'form';
    }

    /**
     * What a step's body shows of the server now.
     *
     * @return array<string, mixed>
     */
    private function data(PanelActor $actor, string $step, PanelCapabilities $capabilities): array
    {
        return match ($step) {
            'address' => ['address' => $capabilities->allows('panel:address') ? $this->extras->panelAddress($actor) : null],
            'alerts' => ['channel' => $capabilities->allows('self:alerts') ? $this->extras->alerts($actor)?->channel : null,
                'canTest' => $capabilities->allows(self::ALERTS_TEST)],
            'protection' => ['level' => $capabilities->allows('security:status') ? $this->extras->security($actor)?->level : null],
            'backups' => ['storages' => $capabilities->allows('backup:storage-list') ? ($this->extras->backupStorages($actor) ?? []) : []],
            'updates' => ['plans' => $capabilities->allows('self:update-plan') ? ['env' => $this->extras->updatePlan($actor, 'env'), 'panel' => $this->extras->updatePlan($actor, 'panel')] : null,
                'canUpdate' => $capabilities->allows('self:update')],
            default => [],
        };
    }

    /** @param array<string, string> $states */
    private function step(?string $step, array $states): string
    {
        if (is_string($step) && (array_key_exists($step, ServerSetup::STEPS) || $step === self::FINISH)) {
            return $step;
        }

        return ServerSetup::next($states) ?? self::FINISH;
    }

    /** @param array<string, string> $states the step after this one, or the result */
    private function next(string $step, array $states): RedirectResponse
    {
        $keys = array_keys(ServerSetup::STEPS);
        $after = $keys[array_search($step, $keys, true) + 1] ?? self::FINISH;

        return new RedirectResponse(route('larena.env.admin.overview.setup', ['step' => $after], false));
    }

    /** @param array<string, string> $query */
    private function here(Request $request, string $step, array $query = [], ?string $reason = null): RedirectResponse
    {
        if ($reason !== null && $request->hasSession()) {
            $request->session()->flash(self::ERROR, $this->reason($reason));
        }

        return new RedirectResponse(route('larena.env.admin.overview.setup', ['step' => $step] + $query, false));
    }

    /** @param array<string, string> $args */
    private function keep(Request $request, string $step, string $command, array $args): void
    {
        $flow = $this->flow($request);
        $flow[$step] = ['at' => time(), 'command' => $command, 'args' => $this->encrypter->encryptString((string) json_encode($args))];
        $request->session()->put(self::SESSION, $flow);
    }

    /**
     * The step's command and arguments waiting for confirmation; with $started, also those of a run that failed, to try
     * it again.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    private function pending(Request $request, string $step, bool $started = false): ?array
    {
        $entry = $this->flow($request)[$step] ?? null;
        if (!is_array($entry) || !is_int($entry['at'] ?? null) || $entry['at'] <= time() - self::PENDING_SECONDS
            || !is_string($entry['command'] ?? null) || !is_string($entry['args'] ?? null) || (!$started && isset($entry['job']))) {
            return null;
        }
        try {
            $args = json_decode($this->encrypter->decryptString($entry['args']), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($args) ? [$entry['command'], array_map('strval', $args)] : null;
    }

    private function forget(Request $request, string $step): void
    {
        $flow = $this->flow($request);
        unset($flow[$step]);
        $request->session()->put(self::SESSION, $flow);
    }

    /** @return array<string, array<string, mixed>> */
    private function flow(Request $request): array
    {
        $flow = $request->hasSession() ? $request->session()->get(self::SESSION, []) : [];

        return is_array($flow) ? $flow : [];
    }

    private function capabilities(PanelActor $actor): PanelCapabilities
    {
        return $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
    }

    /** @return array<string, bool> what the server already shows done: a domain with a certificate, a channel, a level, a storage */
    private function doneOnServer(PanelActor $actor): array
    {
        $capabilities = $this->capabilities($actor);
        $alerts = $capabilities->allows('self:alerts') ? $this->extras->alerts($actor) : null;

        return [
            'address' => $capabilities->allows('panel:address') && $this->extras->panelAddress($actor)?->domainSsl === true,
            'alerts' => in_array($alerts?->channel['kind'] ?? null, ['webhook', 'telegram'], true),
            'protection' => $capabilities->allows('security:status') && in_array($this->extras->security($actor)?->level, ['enhanced', 'strict'], true),
            'backups' => $capabilities->allows('backup:storage-list') && ($this->extras->backupStorages($actor) ?? []) !== [],
        ];
    }

    private function reason(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
