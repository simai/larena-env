<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\EnvOperations;

/**
 * A started action: where it stands and what it has printed. The screen reads again when it is opened again, so a
 * running job is followed by looking, never by the panel doing anything else on the server.
 */
final readonly class ServerJobController
{
    /** The server's refusal for a job it has no record of (simai-env's panel gate). */
    public const string UNKNOWN_JOB = 'unknown_job';
    /** The admin's «История», where a run opens in the side panel (larena/admin). */
    public const string HISTORY_ROUTE = 'larena.admin.history.index';

    public function __construct(
        private EnvOperations $operations,
        private Factory $views,
        private Translator $translator,
        private ?\Larena\Env\Server\ServerOverviewReader $overviews = null,
        private ?\Larena\Access\Runtime\AccessOperationAuthorizer $authorizer = null,
        private ?\Illuminate\Routing\Router $router = null,
        private PlanReceipts $receipts = new PlanReceipts(),
    ) {
    }

    public function show(Request $request, string $job): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $inHistory = $this->inHistory($request, $job);
        if ($inHistory !== null) {
            return $inHistory;
        }
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

        $view = $this->views->make('larena-env::admin.job', [
            'jobId' => $job,
            // The server's operation id of the run, which links it to the server's own log (auditor msg-3d61bd36, point 2).
            'operationId' => $result->operationId,
            'configFailed' => self::configFailed($result->status, $result->log->lines ?? []),
            'change' => $change,
            'canKeep' => $canKeep,
            'now' => new \DateTimeImmutable(),
            'updating' => is_string($updating) ? $updating : null,
            'source' => $result->source,
            'status' => $result->status,
            // Who started it, in words (block 4): «Вы» for this person, never the account's internal reference.
            'startedBy' => self::startedBy($result->status?->actor, $actor),
            'log' => $result->log,
            'refused' => $result->refused,
            // A job the server does not know is said as not found, like a site, not as the server's refusal (critics 2026-11-07).
            'missing' => $result->status === null && $result->reason === self::UNKNOWN_JOB,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'notice' => $request->session()->get('larena_env_notice'),
            // The panel's own zone (the admin's time zone setting), as every panel screen says a time: the job's start and
            // end read as «История» reads them; the server's zone only where the panel's cannot be read.
            'timezone' => $result->status === null ? null : (\Larena\Env\Server\Moment::zone() ?? $this->overviews?->read($actor)->overview?->timezone),
            // «Повторить» for a job that ended without success, where the person may run it (owner 2026-11-18).
            'canAgain' => $result->status !== null && $result->status->state->isOver() && $this->mayAgain($request, $result->status),
        ]);
        // A page that shows a value given once (WordPress's administrator password, a database browser's address, a
        // database's password) is never kept by the browser or a proxy: the person sees it on this answer only.
        if ($result->status?->secret !== null || $result->status?->browser !== null || $result->status?->credentials !== null) {
            return new \Illuminate\Http\Response($view->render(), 200, ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
        }

        return $view;
    }

    /**
     * The service whose restart or reread the server refused because its settings are broken (simai-env 1.33.0 says
     * so in the job's log); the service keeps running as it was.
     *
     * @param list<string> $lines
     */
    public static function configFailed(?\Larena\Env\Operations\PanelJobStatus $status, array $lines): ?string
    {
        if ($status === null || $status->state !== \Larena\Env\Operations\JobState::Failed
            || !in_array($status->command, ['service:restart', 'service:reload'], true) || !is_string($status->args['name'] ?? null)) {
            return null;
        }
        foreach ($lines as $line) {
            if (str_contains($line, 'configuration test failed')) {
                return $status->args['name'];
            }
        }

        return null;
    }

    /**
     * «Повторить» (owner 2026-11-18): the same command with the values the server kept, planned again; the plan and its
     * confirmation stand where the job was shown (the side panel of «История» too). Nothing runs before the confirmation.
     */
    public function again(Request $request, string $job): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $status = $this->operations->view($actor, $job, 0)->status;
        $address = route('larena.env.admin.job', ['job' => $job], false);
        if ($status === null || !\Larena\Env\Admin\JobOutcome::canAgain($status) || !$this->mayAgain($request, $status)) {
            return NoticeRedirect::to($request, $address, (string) $this->translator->get('larena-env::admin.job.again_refused'));
        }
        $result = $this->operations->planAgain($actor, $status->command, $status->args);
        if ($result->plan === null) {
            return NoticeRedirect::to($request, $address, $this->reasonText($result->reason ?? 'answer_shape_invalid'));
        }
        $title = $status->title ?? \Larena\Env\Admin\EnvHistorySource::title($status, $this->translator);

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.job.again_heading', ['title' => $title]),
            'eyebrow' => (string) $this->translator->get('larena-env::admin.eyebrow'),
            'target' => $status->args['domain'] ?? $status->args['target'] ?? $status->args['name'] ?? null,
            'source' => $result->source,
            'plan' => $result->plan,
            'update' => $result->plan->update,
            'runUrl' => route('larena.env.admin.job.again.run', ['job' => $job], false),
            'cancelUrl' => $address,
            'hidden' => [PlanReceipts::FIELD => $this->receipts->issue($request, 'job:again', ['job' => $job, 'command' => $status->command, 'args' => $status->args])],
        ]);
    }

    /** The confirmation of «Повторить»: the same command and values, read again from the server, not from the form. */
    public function againRun(Request $request, string $job): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $status = $this->operations->view($actor, $job, 0)->status;
        $address = route('larena.env.admin.job', ['job' => $job], false);
        if ($status === null || !\Larena\Env\Admin\JobOutcome::canAgain($status) || !$this->mayAgain($request, $status)) {
            return NoticeRedirect::to($request, $address, (string) $this->translator->get('larena-env::admin.job.again_refused'));
        }
        if (!$this->receipts->confirms($request, 'job:again', ['job' => $job, 'command' => $status->command, 'args' => $status->args])) {
            return NoticeRedirect::to($request, $address, $this->reasonText(PlanReceipts::REFUSED));
        }
        $started = $this->operations->startAgain($actor, $status->command, $status->args);
        if ($started->jobId === null) {
            return NoticeRedirect::to($request, $address, $this->reasonText($started->reason ?? 'answer_shape_invalid'));
        }
        if ($status->command === 'self:update' && $request->hasSession()) {
            $request->session()->put(UpdateController::JOB.'.'.$started->jobId, $status->args['target'] ?? 'env');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * Whether the person may run this job's command again: a site's, with the right to operate sites (the server keeps
     * an owner to their own); the server's own, with the system level's right.
     */
    private function mayAgain(Request $request, PanelJobStatus $status): bool
    {
        if ($this->authorizer === null) {
            return false;
        }
        $right = is_string($status->args['domain'] ?? null) && $status->args['domain'] !== '' ? 'env.sites.operate' : 'env.server.operate';

        return $this->authorizer->authorize($request, $right)->isAllowed();
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
        $started = $this->operations->start($actor, 'env.backup.schedule', ['domain' => $domain, 'enabled' => 'yes']);
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

    /**
     * A run's address opened as a page leads to «История», which opens the run in its side panel (owner decision
     * dec-d9f352569fd1): before the server is asked anything, so a value the server gives once is not spent on the
     * redirect. A run's part (asked by the side panel), a page with a notice just left for it, a later page of its
     * output and an update of the panel itself stay here.
     */
    private function inHistory(Request $request, string $job): ?RedirectResponse
    {
        if (!$request->isMethod('GET') || $request->headers->has('X-Larena-Fragment') || $request->query->count() > 0
            || $this->router?->has(self::HISTORY_ROUTE) !== true) {
            return null;
        }
        if ($request->hasSession() && ($request->session()->has('larena_env_notice') || $request->session()->has(UpdateController::JOB.'.'.$job))) {
            return null;
        }
        $address = route('larena.env.admin.job', ['job' => $job], false);

        return new RedirectResponse(route(self::HISTORY_ROUTE, [], false).'?open='.rawurlencode($address));
    }

    /** Who started a job, as a key of job.started_by_*: this person, another person of the panel, or the server itself. */
    public static function startedBy(?string $started, PanelActor $actor): ?string
    {
        if ($started === null || $started === '') {
            return null;
        }
        if ($started === $actor->user || $started === $actor->impersonatedBy) {
            return 'you';
        }

        return str_starts_with($started, 'user:') ? 'other' : 'server';
    }
}
