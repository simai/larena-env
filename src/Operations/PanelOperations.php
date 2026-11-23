<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelAttachment;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\People\PanelAudit;
use Larena\Env\Server\SiteDetailsReader;
use Throwable;
use UnexpectedValueException;

/**
 * An action always goes the same way: the panel asks what it would do, a person confirms that plan, and only then the
 * gateway starts it as a job the screen follows. Nothing here runs anything itself, and nothing throws: a refusal and
 * a failure both come back as a reason code the screen can say in words.
 *
 * A change is never asked without the audit log (auditor msg-3d61bd36, point 2): without a writable audit sink a start is
 * refused `audit_unavailable` before the server is asked, while plans and reads go on. A started job's receipt carries
 * who, the start's request id, the plan it follows, the job and the server's operation id, and the same set is written
 * as `env.server.change_started`.
 */
final readonly class PanelOperations
{
    /** The audit event of every change started on the server. */
    public const string CHANGE_STARTED = 'env.server.change_started';

    /** The audit event of a job the server was asked to stop and stopped. */
    public const string JOB_CANCELLED = 'env.server.job_cancelled';

    private PlanTrail $trail;

    public function __construct(
        private PanelGateway $gateway,
        private ?PanelAudit $audit = null,
        ?PlanTrail $trail = null,
    ) {
        $this->trail = $trail ?? new PlanTrail();
    }

    /** Where the answers come from (the gateway's driver), as every result names it. */
    public function source(): string
    {
        return $this->gateway->source();
    }

    /**
     * What the site's action would do. Nothing runs.
     *
     * @param array<string, string> $input the form's own fields, as typed
     */
    public function plan(PanelActor $actor, SiteOperation $operation, string $domain, array $input = []): PlanResult
    {
        return $this->planCommand($actor, $operation->command(), $operation->arguments($domain, $input));
    }

    /**
     * The same action, confirmed by the person who saw its plan: the gateway starts it and answers a job.
     *
     * @param array<string, string> $input
     */
    public function start(PanelActor $actor, SiteOperation $operation, string $domain, array $input = []): JobStartResult
    {
        return $this->startCommand($actor, $operation->command(), $operation->arguments($domain, $input));
    }

    /**
     * What any action of the command table would do (a new site, a new owner), with its gateway arguments. Nothing runs.
     *
     * @param array<string, string|list<string>> $args
     * @param ?PanelAttachment $attachment a file the action carries: the plan names it, nothing is sent yet
     */
    public function planCommand(PanelActor $actor, string $command, array $args, ?PanelAttachment $attachment = null): PlanResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::plan($actor, $command, $args, $attachment));
        } catch (PanelRefused $refused) {
            return new PlanResult($source, null, $refused->reasonCode, true);
        }
        if ($answer->status !== PanelAnswerStatus::Ok) {
            // What stopped it, in the server's words (simai-env 1.60.0 checks; 1.61.0 a named refusal's detail).
            $detail = $answer->data['detail'] ?? null;

            return new PlanResult($source, null, $answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused,
                PlanChecks::fromData($answer->data['checks'] ?? null), is_string($detail) && trim($detail) !== '' ? mb_substr(trim($detail), 0, 500) : null,
                $command === 'site:restore-ready' ? \Larena\Env\Admin\RestoreWords::found($answer->data['restore'] ?? null) : null,
                $answer->requestId, $answer->operationId);
        }
        if ($answer->kind !== 'plan') {
            return new PlanResult($source, null, PanelReason::ANSWER_SHAPE_INVALID, requestId: $answer->requestId, operationId: $answer->operationId);
        }
        try {
            $plan = PanelPlan::fromData($answer->data);
        } catch (UnexpectedValueException) {
            return new PlanResult($source, null, PanelReason::ANSWER_SHAPE_INVALID, requestId: $answer->requestId, operationId: $answer->operationId);
        }
        // The plan the receipt of the screen keeps, and the start names (PlanTrail).
        $this->trail->planned($command, $answer->requestId, $answer->operationId);

        return new PlanResult($source, $plan, requestId: $answer->requestId, operationId: $answer->operationId);
    }

    /**
     * That action confirmed: the gateway starts it and answers a job.
     *
     * @param array<string, string|list<string>> $args
     * @param ?PanelAttachment $attachment the file the confirmed action sends after its request
     */
    public function startCommand(PanelActor $actor, string $command, array $args, ?PanelAttachment $attachment = null,
        ?string $operation = null, ?string $reversible = null): JobStartResult
    {
        $source = $this->gateway->source();
        $who = $actor->impersonatedBy ?? $actor->user;
        $onBehalf = $actor->impersonatedBy !== null ? $actor->user : null;
        // No change without the audit log: refused before the server is asked anything.
        if ($this->audit === null || !$this->audit->available()) {
            return new JobStartResult($source, null, PanelReason::AUDIT_UNAVAILABLE, true, who: $who, onBehalfOf: $onBehalf, command: $command,
                operation: $operation, reversible: $reversible);
        }
        $plan = $this->trail->takeFor($command);
        try {
            $request = PanelRequest::confirmed($actor, $command, $args, $attachment);
        } catch (PanelRefused $refused) {
            return new JobStartResult($source, null, $refused->reasonCode, true, null, null, $plan['plan_id'] ?? null,
                $plan['plan_operation_id'] ?? null, $who, $onBehalf, $command, $operation, $reversible);
        }
        try {
            $answer = $this->gateway->ask($request);
        } catch (PanelRefused $refused) {
            return new JobStartResult($source, null, $refused->reasonCode, true, $request->requestId, null, $plan['plan_id'] ?? null,
                $plan['plan_operation_id'] ?? null, $who, $onBehalf, $command, $operation, $reversible);
        }
        $chain = [$answer->requestId, $answer->operationId, $plan['plan_id'] ?? null, $plan['plan_operation_id'] ?? null, $who, $onBehalf, $command,
            $operation, $reversible];
        if ($answer->status !== PanelAnswerStatus::Ok) {
            return new JobStartResult($source, null, $answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused, ...$chain);
        }
        $jobId = $answer->jobId;
        if ($answer->kind !== 'job' || $jobId === null) {
            return new JobStartResult($source, null, PanelReason::ANSWER_SHAPE_INVALID, false, ...$chain);
        }
        $started = new JobStartResult($source, $jobId, null, false, ...$chain);
        try {
            $this->audit->record(self::CHANGE_STARTED, $who, self::subjectOf($command, $args), $started->chain(), $started->planId ?? $started->requestId);
        } catch (Throwable $failure) {
            // The job runs already and the screen follows it; the lost record goes to the application's error log.
            if (function_exists('report')) {
                report($failure);
            }
        }

        return $started;
    }

    /**
     * What a change is about in the audit log: the site, the account or the named thing, else the server.
     *
     * @param array<string, string|list<string>> $args
     */
    private static function subjectOf(string $command, array $args): string
    {
        foreach (['domain', 'name', 'target'] as $key) {
            $value = $args[$key] ?? null;
            if (is_string($value) && $value !== '' && mb_strlen($value) <= 191) {
                return $value;
            }
        }

        return 'env.server:'.$command;
    }

    /** Where a started action stands and what it has printed after the cursor the screen last saw. */
    /**
     * The jobs the server lists (job:list, its last 100), by id: their state without their result, so a value given once
     * (WordPress's password) stays for the screen that shows it. Null when the list cannot be read.
     *
     * @return array<string, PanelJobStatus>|null
     */
    public function jobs(PanelActor $actor): ?array
    {
        return $this->jobPage($actor)?->jobs;
    }

    /**
     * A page of the server's jobs (simai-env 1.60.0 job:list {before, limit, state, since}): `before` continues after that
     * job, `state` narrows to a comma list of states, `since` (ISO, UTC) keeps what ended after it and all that runs. A
     * server before 1.60.0 takes no arguments: it is asked plainly and its last 100 are the one page. Null when the list
     * cannot be read.
     */
    public function jobPage(PanelActor $actor, ?string $before = null, ?int $limit = null, ?string $state = null, ?string $since = null): ?JobPage
    {
        $args = array_filter(['before' => $before, 'limit' => $limit === null ? null : (string) max(1, min(100, $limit)), 'state' => $state, 'since' => $since],
            static fn (?string $value): bool => $value !== null && $value !== '');
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'job:list', $args));
            if ($args !== [] && $answer->status === PanelAnswerStatus::Refused && $answer->reason === PanelReason::INVALID_ARGUMENT) {
                // An older server: its whole list, once; there is nothing before it.
                if ($before !== null) {
                    return new JobPage([]);
                }
                $answer = $this->gateway->ask(PanelRequest::read($actor, 'job:list'));
            }
        } catch (PanelRefused) {
            return null;
        }
        if (SiteDetailsReader::failureOf($answer) !== null) {
            return null;
        }
        $jobs = [];
        foreach (is_array($answer->data['jobs'] ?? null) ? $answer->data['jobs'] : [] as $item) {
            try {
                $status = is_array($item) ? PanelJobStatus::fromData($item) : null;
            } catch (UnexpectedValueException) {
                $status = null;
            }
            if ($status !== null) {
                $jobs[$status->jobId] = $status;
            }
        }
        $counts = is_array($answer->data['counts'] ?? null) ? $answer->data['counts'] : [];
        $next = $answer->data['next_before'] ?? null;

        return new JobPage($jobs, ($answer->data['more'] ?? false) === true, is_string($next) && preg_match('/\A[a-f0-9]{24}\z/D', $next) === 1 ? $next : null,
            is_int($counts['running'] ?? null) ? $counts['running'] : null, is_int($counts['failed_since'] ?? null) ? $counts['failed_since'] : null);
    }

    public function view(PanelActor $actor, string $jobId, int $after = 0): JobViewResult
    {
        $source = $this->gateway->source();
        try {
            $statusAnswer = $this->gateway->ask(PanelRequest::read($actor, 'job:status', ['job_id' => $jobId]));
        } catch (PanelRefused $refused) {
            return new JobViewResult($source, null, null, $refused->reasonCode, true);
        }
        $failure = SiteDetailsReader::failureOf($statusAnswer);
        if ($failure !== null) {
            return new JobViewResult($source, null, null, $failure[0], $failure[1]);
        }
        try {
            $status = PanelJobStatus::fromData($statusAnswer->data);
        } catch (UnexpectedValueException) {
            return new JobViewResult($source, null, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
        $log = null;
        $reason = null;
        try {
            $logAnswer = $this->gateway->ask(PanelRequest::read($actor, 'job:log', ['job_id' => $jobId, 'after' => (string) max(0, $after)]));
            $logFailure = SiteDetailsReader::failureOf($logAnswer);
            $log = $logFailure === null ? PanelJobLog::fromData($logAnswer->data) : null;
            $reason = $logFailure === null ? null : $logFailure[0];
        } catch (PanelRefused $refused) {
            $reason = $refused->reasonCode;
        } catch (UnexpectedValueException) {
            $reason = PanelReason::ANSWER_SHAPE_INVALID;
        }

        // The status is what the screen is for; a log that is not there yet is said beside it, not instead of it.
        $started = $statusAnswer->data['request_id'] ?? null;
        $operation = $statusAnswer->data['operation_id'] ?? null;

        return new JobViewResult($source, $status, $log, $reason, false, is_string($started) && $started !== '' ? $started : null,
            is_string($operation) && $operation !== '' ? $operation : $statusAnswer->operationId);
    }

    /**
     * Stops a job that is still running; a finished one is refused with `not_running`, which the screen says. Stopping a
     * job changes the server, so it goes the way of a change (wave A's addition c): refused `audit_unavailable` before the
     * server is asked where the audit log cannot be written, and a stop the server took is written as
     * `env.server.job_cancelled` with who, for whom, the stop's request and operation, and the job with the request and
     * operation that started it — the job id links it to that job's `env.server.change_started`, and correlates it.
     */
    public function cancel(PanelActor $actor, string $jobId, ?string $operation = null): JobViewResult
    {
        $source = $this->gateway->source();
        if ($this->audit === null || !$this->audit->available()) {
            return new JobViewResult($source, null, null, PanelReason::AUDIT_UNAVAILABLE, true);
        }
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'job:cancel', ['job_id' => $jobId]));
        } catch (PanelRefused $refused) {
            return new JobViewResult($source, null, null, $refused->reasonCode, true);
        }
        $failure = self::cancelFailure($answer);
        if ($failure !== null) {
            return new JobViewResult($source, null, null, $failure[0], $failure[1]);
        }
        $view = $this->view($actor, $jobId);
        $who = $actor->impersonatedBy ?? $actor->user;
        try {
            $this->audit->record(self::JOB_CANCELLED, $who, 'env.job:'.$jobId, array_filter([
                'who' => $who,
                'on_behalf_of' => $actor->impersonatedBy !== null ? $actor->user : null,
                'operation' => $operation,
                'reversible' => $operation !== null ? 'false' : null,
                'command' => 'job:cancel',
                'request_id' => $answer->requestId,
                'operation_id' => $answer->operationId,
                'job_id' => $jobId,
                'job_request_id' => $view->requestId,
                'job_operation_id' => $view->operationId,
            ], static fn (?string $value): bool => $value !== null && $value !== ''), $jobId);
        } catch (Throwable $failure) {
            // The server stopped the job already; the lost record goes to the application's error log.
            if (function_exists('report')) {
                report($failure);
            }
        }

        return $view;
    }

    /** @return array{0: string, 1: bool}|null */
    private static function cancelFailure(PanelAnswer $answer): ?array
    {
        return $answer->status === PanelAnswerStatus::Ok ? null
            : [$answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused];
    }
}
