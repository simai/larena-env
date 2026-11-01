<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;

/**
 * A started action: where it stands and what it has printed. The screen reads again when it is opened again, so a
 * running job is followed by looking, never by the panel doing anything else on the server.
 */
final readonly class ServerJobController
{
    public function __construct(
        private PanelOperations $operations,
        private Factory $views,
        private Translator $translator,
        private ?\Larena\Env\Server\ServerOverviewReader $overviews = null,
        private ?\Larena\Access\Runtime\AccessOperationAuthorizer $authorizer = null,
    ) {
    }

    public function show(Request $request, string $job): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $after = (int) $request->query('after', '0');
        $result = $this->operations->view($actor, $job, max(0, $after));
        $followUp = $this->followUp($request, $actor, $job, $result->status);
        if ($followUp !== null) {
            return $followUp;
        }

        // An update's job (simai-env 1.32.0) cannot be stopped, and runs on while the gate and the panel are replaced.
        $updating = $request->hasSession() ? $request->session()->get(UpdateController::JOB.'.'.$job) : null;
        if (!is_string($updating) && $result->status?->command === 'self:update') {
            $updating = $result->status->args['target'] ?? 'env';
        }

        // A timed change (the firewall, who reaches the panel): how long is left, and keeping it, where the person may.
        $change = $result->status?->change;
        $canKeep = $change !== null && ($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false)
            && ($this->overviews?->read($actor)->overview?->capabilities?->allows('change:confirm') ?? false);

        return $this->views->make('larena-env::admin.job', [
            'jobId' => $job,
            'change' => $change,
            'canKeep' => $canKeep,
            'now' => new \DateTimeImmutable(),
            'updating' => is_string($updating) ? $updating : null,
            'source' => $result->source,
            'status' => $result->status,
            'log' => $result->log,
            'refused' => $result->refused,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'notice' => $request->session()->get('larena_env_notice'),
        ]);
    }

    public function cancel(Request $request, string $job): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $result = $this->operations->cancel($actor, $job);
        $address = route('larena.env.admin.job', ['job' => $job], false);

        return $result->reason === null
            ? new RedirectResponse($address)
            : NoticeRedirect::to($request, $address, $this->reasonText($result->reason));
    }

    /**
     * A new site made with its daily backup asked for (Panel plan 4.1): once its job has made it, the backup is turned
     * on, and the screen follows that job. A site that was not made forgets the request.
     */
    private function followUp(Request $request, PanelActor $actor, string $job, ?PanelJobStatus $status): ?RedirectResponse
    {
        $key = NewSiteController::AFTER_JOB.'.'.$job;
        $domain = $request->hasSession() ? $request->session()->get($key) : null;
        if (!is_string($domain) || $status === null || $status->state === JobState::Running || $status->state === JobState::Busy) {
            return null;
        }
        $request->session()->forget($key);
        if ($status->state !== JobState::Succeeded) {
            return null;
        }
        $started = $this->operations->startCommand($actor, 'backup:data-schedule', ['domain' => $domain, 'enabled' => 'yes']);
        if ($started->jobId === null) {
            return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab=backups',
                (string) $this->translator->get('larena-env::admin.new_site.daily_backup_failed', ['reason' => $this->reasonText($started->reason ?? 'answer_shape_invalid')]));
        }

        return NoticeRedirect::to($request, route('larena.env.admin.job', ['job' => $started->jobId], false),
            (string) $this->translator->get('larena-env::admin.new_site.daily_backup_started'));
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
