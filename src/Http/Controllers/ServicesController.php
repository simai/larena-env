<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\HostService;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\ServiceLogView;
use Larena\Env\Server\ServiceState;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Сервер › Службы» (Panel plan 5.1): each service of the server and whether it runs, a restart or a reload of its
 * settings by plan and confirmation, and the last lines of its journal. The system level's alone. It is what an
 * emergency needs from a phone, so the screen is a list of short rows with large buttons. The server checks nginx's
 * and PHP-FPM's settings before it restarts or reloads them (nginx -t, php-fpm -t); a failed check fails the job and
 * leaves the service running as it was. Only the services the server has are listed (service:list, simai-env 1.33.0),
 * and a reload is offered only where the server says the service can reread its settings. Since simai-env 1.62.0 a service
 * sites can do without for a while (Redis, cron) can be stopped and started again: «Остановить» only where the server says
 * `can_stop` and never for a critical one (nginx, PHP-FPM, the databases), whose stop the server refuses `critical` and
 * the panel says in words; the plan lists the sites that lose something while it is stopped.
 */
final readonly class ServicesController
{
    /** What a service's row offers, and the gateway command of each. */
    public const array OPERATIONS = ['restart' => 'service:restart', 'reload' => 'service:reload', 'stop' => 'service:stop', 'start' => 'service:start'];
    /** How many of the journal's last lines are read. */
    private const int LOG_LINES = 500;

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
        $capabilities = $read->overview?->capabilities;
        $services = $this->services($actor, $read->overview);
        $names = array_map(static fn (HostService $service): string => $service->name, $services);
        $logOf = $request->query('log');
        $show = is_string($request->query('show')) ? $request->query('show') : '';
        $log = null;
        if (is_string($logOf) && in_array($logOf, $names, true) && $capabilities?->allows('service:log') === true) {
            // «Журнал службы» (PX-48): errors first — the server keeps them (simai-env 1.60.0 priority) among its lines;
            // none, and the screen opens on every line.
            if ($show !== 'all') {
                $log = $this->extras->serviceLog($actor, $logOf, self::LOG_LINES, ServiceLogView::PROBLEMS_PRIORITY);
                if ($log !== null && $log['filtered'] && $log['entries'] === [] && $show === '') {
                    $log = null;
                    $show = 'all';
                }
            }
            $log ??= $this->extras->serviceLog($actor, $logOf, self::LOG_LINES);
        }
        // 50 lines and «Ещё 50», the line as written on a press.
        $logView = $log === null ? null : ServiceLogView::of($log['lines'], $log['entries'], $show,
            fn (string $tone): string => (string) $this->translator->get('larena-env::admin.services.tones.'.$tone), $log['filtered']);
        $shown = max(ServiceLogView::PAGE, min(self::LOG_LINES, (int) $request->query('shown', (string) ServiceLogView::PAGE)));
        $shown -= $shown % ServiceLogView::PAGE;
        $canOperate = $this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false;

        return $this->views->make('larena-env::admin.services', [
            'source' => $read->source,
            // A stopped service first: it is what a person opens the screen for; then the running ones, and last those
            // not used on this server (block 4, DATA-02).
            'services' => [...array_values(array_filter($services, static fn (HostService $service): bool => !$service->isActive() && !$service->isUnused())),
                ...array_values(array_filter($services, static fn (HostService $service): bool => $service->isActive())),
                ...array_values(array_filter($services, static fn (HostService $service): bool => $service->isUnused()))],
            'reasonText' => $read->reason === null ? null : $this->reasonText($read->reason),
            // Each action only where the server lists it and the person may change the server.
            'allows' => [
                'restart' => $canOperate && $capabilities?->allows('service:restart') === true,
                'reload' => $canOperate && $capabilities?->allows('service:reload') === true,
                'stop' => $canOperate && $capabilities?->allows('service:stop') === true,
                'start' => $canOperate && $capabilities?->allows('service:start') === true,
                'log' => $capabilities?->allows('service:log') === true,
            ],
            'logOf' => is_string($logOf) && in_array($logOf, $names, true) ? $logOf : null,
            'log' => $logView,
            'logRows' => $logView === null ? [] : array_slice($logView->rows, 0, $shown),
            'logZone' => $read->overview?->timezone,
            'logMore' => $logView !== null && count($logView->rows) > $shown ? $request->fullUrlWithQuery(['shown' => $shown + ServiceLogView::PAGE]) : null,
            'logOther' => $logView === null ? null : $request->fullUrlWithQuery(['show' => $logView->view === 'problems' ? 'all' : 'problems', 'shown' => null]),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    /**
     * The services the server reports (service:list); a server without it, the states its description carries, with
     * nothing to offer for them.
     *
     * @return list<HostService>
     */
    private function services(PanelActor $actor, ?ServerOverview $overview): array
    {
        if ($overview?->capabilities?->allows('service:list') === true) {
            return $this->extras->services($actor) ?? [];
        }

        return array_map(static fn (ServiceState $service): HostService => new HostService($service->name, $service->state, null, null, false),
            $overview->services ?? []);
    }

    /** What the restart or the reload would do, with a confirmation that does it and nothing else. */
    public function plan(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        [$op, $name] = $this->asked($request, $actor, true);
        if ($op === null || $name === null) {
            return $this->back($request, 'not_allowed');
        }
        $result = $this->operations->planCommand($actor, self::OPERATIONS[$op], ['name' => $name]);
        if ($result->plan === null) {
            // A stop the server will not plan (a critical service, simai-env 1.62.0) is said in words in the same window,
            // its button off, rather than as a line on the list.
            if ($op === 'stop' && $result->said()) {
                $reason = $result->reason ?? 'plan_failed';
                $key = 'larena-env::admin.services.refusals.'.$reason;
                $known = (string) $this->translator->get($key, ['name' => HostService::label($name)]);

                return $this->views->make('larena-env::admin.plan', [
                    'heading' => (string) $this->translator->get('larena-env::admin.services.operations.stop', ['name' => $name]),
                    'eyebrow' => (string) $this->translator->get('larena-env::admin.services.title'),
                    'runUrl' => route('larena.env.admin.services.run', [], false),
                    'cancelUrl' => route('larena.env.admin.services', [], false),
                    'refused' => [$known !== $key ? $known : ($result->checks->blockers[0] ?? $result->detail ?? $this->reasonText($reason))],
                    'refusedNotes' => [],
                    'refusedReason' => $reason,
                    'refusedKey' => 'refused-service-stop',
                    'refusedMore' => '',
                ]);
            }

            return $this->back($request, $result->reason ?? 'answer_shape_invalid');
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.services.operations.'.$op, ['name' => $name]),
            'eyebrow' => (string) $this->translator->get('larena-env::admin.services.title'),
            'target' => $name,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.services.run', [], false),
            'cancelUrl' => route('larena.env.admin.services', [], false),
            'hidden' => ['op' => $op, 'name' => $name],
            // What a stop takes away: the sites and what each loses, and the server's own checks where cron goes.
            'serviceStop' => $op === 'stop' ? ['name' => $name, 'sites' => $result->plan->affected, 'server' => $result->plan->pausesServer] : null,
            'then' => [(string) $this->translator->get('larena-env::admin.services.'.match ($op) {'stop' => 'stop_then', 'start' => 'start_then', default => 'checked_first'})],
        ]);
    }

    /** The confirmation itself: the server restarts or reloads the service as a job the next screen follows. */
    public function run(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        [$op, $name] = $this->asked($request, $actor);
        if ($op === null || $name === null) {
            return $this->back($request, 'not_allowed');
        }
        $started = $this->operations->startCommand($actor, self::OPERATIONS[$op], ['name' => $name]);
        if ($started->jobId === null) {
            return $this->back($request, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The operation and the service asked for, when the server lists both: a service it does not report, or a form
     * left from an older page, is refused in words.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function asked(Request $request, PanelActor $actor, bool $planning = false): array
    {
        $op = $request->input('op');
        $name = $request->input('name');
        $overview = $this->overviews->read($actor)->overview;
        if (!is_string($op) || !isset(self::OPERATIONS[$op]) || $overview?->capabilities?->allows(self::OPERATIONS[$op]) !== true) {
            return [null, null];
        }
        foreach ($this->services($actor, $overview) as $service) {
            if ($service->name === $name && match ($op) {
                'reload' => $service->canReload,
                // A critical service's stop is asked of the server only to be refused in its words (`critical`), never run.
                'stop' => $service->stoppable() || ($planning && $service->critical),
                'start' => $service->startable(),
                default => true,
            }) {
                return [$op, $service->name];
            }
        }

        return [$op, null];
    }

    private function back(Request $request, string $reason): RedirectResponse
    {
        return NoticeRedirect::to($request, route('larena.env.admin.services', [], false), $this->reasonText($reason));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
