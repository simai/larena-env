<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Update\UpdatePlan;

/**
 * «Сервер › Обновление» (Panel plan 4.7, simai-env 1.32.0): what an update of simai-env or of the panel would change,
 * and the update itself, by plan and confirmation, the system level's. The job runs on while the gate and the panel
 * are replaced, so the job's screen is told it follows an update and waits through a restart instead of failing.
 */
final readonly class UpdateController
{
    /** The session key under which a started update's job keeps its target, for the job's screen. */
    public const string JOB = 'larena_env_update_job';

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
        $supported = $capabilities?->allows('self:update-plan') === true;
        $plans = [];
        foreach (UpdatePlan::TARGETS as $target) {
            $plans[$target] = $supported ? $this->extras->updatePlan($actor, $target) : null;
        }

        return $this->views->make('larena-env::admin.update', [
            'source' => $read->source,
            'supported' => $supported,
            'plans' => $plans,
            'canUpdate' => $capabilities?->allows('self:update') === true
                && ($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
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
            'hidden' => ['target' => $target],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $target = self::target($request);
        if ($target === null) {
            return $this->notice($request, 'invalid_argument');
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
