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
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Update\UpdatePlan;

/**
 * «Программы и обновления», its head (Panel plan 4.7, simai-env 1.32.0): what an update of simai-env or of the panel would change,
 * and the update itself, by plan and confirmation, the system level's. The job runs on while the gate and the panel
 * are replaced, so the job's screen is told it follows an update and waits through a restart instead of failing.
 */
final readonly class UpdateController
{
    /** The session key under which a started update's job keeps its target, for the job's screen. */
    public const string JOB = 'larena_env_update_job';

    public function __construct(
        private PanelOperations $operations,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
        private ?\Larena\Env\Server\ServerOverviewReader $overviews = null,
        private PlanReceipts $receipts = new PlanReceipts(),
    ) {
    }

    /**
     * «Обновления» among the panel's settings (owner 2026-11-18): simai-env and the panel, each with its versions always
     * («Установлена X · последняя Y · проверено …»), what is new and what restarts, «Проверить» and «Обновить» through
     * «Действие с планом». With `?target=env|panel`, as the alert bar and the overview link it («Обновить», owner
     * 2026-11-07), the server's plan of that update opens at once, to confirm. A plan changes nothing.
     */
    public function index(Request $request): mixed
    {
        if ($request->query('target') !== null && self::target($request) !== null
            && ($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)) {
            return $this->plan($request);
        }
        $actor = ResolvePanelActor::of($request);
        $overview = $this->overviews?->read($actor)->overview;

        return $this->views->make('larena-env::admin.update', $this->state($request, $actor, $overview?->capabilities) + [
            'versions' => self::versions($overview),
            'notice' => $request->hasSession() ? $request->session()->get('larena_env_notice') : null,
            'checkUrl' => route('larena.env.admin.update.check', [], false),
        ]);
    }

    /** «Проверить»: the server is asked again what each update would bring (self:update-plan), past the kept answers. */
    public function check(Request $request): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $read = false;
        foreach (UpdatePlan::TARGETS as $target) {
            $read = $this->extras->updatePlan($actor, $target, true) !== null || $read;
        }

        return NoticeRedirect::to($request, route('larena.env.admin.update', [], false),
            (string) $this->translator->get('larena-env::admin.'.($read ? 'update.checked_notice' : 'update.unreadable')));
    }

    /**
     * The versions that run, from the server's description (simai-env 1.60.0): simai-env's, and the panel's with the one
     * simai-env pins; a panel built here says its build (panel-build.json).
     *
     * @return array<string, array{installed: ?string, pinned: ?string}>
     */
    public static function versions(?\Larena\Env\Server\ServerOverview $overview, ?string $build = null): array
    {
        $build ??= self::panelBuild();

        return [
            'env' => ['installed' => $overview?->envVersion, 'pinned' => null],
            'panel' => ['installed' => $overview?->panel['version'] ?? $build, 'pinned' => $overview?->panel['pinned'] ?? null],
        ];
    }

    /**
     * What «Программы и обновления» shows of simai-env's and the panel's updates.
     *
     * @return array{supported: bool, plans: array<string, ?UpdatePlan>, canUpdate: bool, panelBuild: ?string}
     */
    public function state(Request $request, PanelActor $actor, ?PanelCapabilities $capabilities): array
    {
        $supported = $capabilities?->allows('self:update-plan') === true;
        $build = self::panelBuild();
        $plans = [];
        foreach (UpdatePlan::TARGETS as $target) {
            $plan = $supported ? $this->extras->updatePlan($actor, $target) : null;
            $plans[$target] = $target === 'panel' ? $plan?->beside($build) : $plan;
        }

        return [
            'supported' => $supported,
            'plans' => $plans,
            'canUpdate' => $capabilities?->allows('self:update') === true
                && ($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false),
            // The panel's own build (panel-build.json beside the application): what the server cannot name when the
            // panel was installed from an archive of its own, rather than «не установлена» for the panel in use.
            'panelBuild' => $build,
        ];
    }

    /** The version of the running panel's build, when it was built by scripts/build-panel-archive.sh. */
    public static function panelBuild(?string $file = null): ?string
    {
        $file ??= function_exists('base_path') ? base_path('panel-build.json') : '';
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $build = json_decode((string) file_get_contents($file), true);
        $version = is_array($build) ? ($build['version'] ?? null) : null;

        return is_string($version) && preg_match('/\A[0-9A-Za-z.+-]{1,64}\z/D', $version) === 1 ? $version : null;
    }

    public function plan(Request $request): mixed
    {
        $target = self::target($request);
        if ($target === null) {
            return $this->notice($request, 'invalid_argument');
        }
        $result = $this->operations->planCommand(ResolvePanelActor::of($request), 'self:update', ['target' => $target]);
        if ($result->plan === null) {
            return $this->notice($request, $result->reason ?? 'answer_shape_invalid');
        }
        $update = $result->plan->update;
        // The server names a reason it cannot run now: the plan is not offered.
        if ($update !== null && $update->blocked !== null) {
            return $this->notice($request, 'update_blocked_'.$update->blocked);
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => $this->text('update.plan_heading.'.$target),
            'eyebrow' => $this->text('update.title'),
            'target' => $this->text('update.targets.'.$target),
            'source' => $result->source,
            'plan' => $result->plan,
            'update' => $update,
            'runUrl' => route('larena.env.admin.update.run', [], false),
            'cancelUrl' => route('larena.env.admin.update', [], false),
            'hidden' => ['target' => $target, PlanReceipts::FIELD => $this->receipts->issue($request, 'self:update', ['target' => $target])],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $target = self::target($request);
        if ($target === null) {
            return $this->notice($request, 'invalid_argument');
        }
        // Only the update the person saw planned: its receipt, this target, once.
        if (!$this->receipts->confirms($request, 'self:update', ['target' => $target])) {
            return $this->notice($request, PlanReceipts::REFUSED);
        }
        $started = $this->operations->startCommand(ResolvePanelActor::of($request), 'self:update', ['target' => $target]);
        if ($started->jobId === null) {
            return $this->notice($request, $started->reason ?? 'answer_shape_invalid');
        }
        $request->session()->put(self::JOB.'.'.$started->jobId, $target);

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /** @return 'env'|'panel'|null */
    private static function target(Request $request): ?string
    {
        $target = $request->input('target');

        return in_array($target, UpdatePlan::TARGETS, true) ? $target : null;
    }

    private function notice(Request $request, string $reason): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return NoticeRedirect::to($request, route('larena.env.admin.update', [], false),
            $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]));
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
