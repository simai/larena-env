<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Server\SiteDetailsReader;
use UnexpectedValueException;

/**
 * An action always goes the same way: the panel asks what it would do, a person confirms that plan, and only then the
 * gateway starts it as a job the screen follows. Nothing here runs anything itself, and nothing throws: a refusal and
 * a failure both come back as a reason code the screen can say in words.
 */
final readonly class PanelOperations
{
    public function __construct(private PanelGateway $gateway)
    {
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
     * @param array<string, string> $args
     */
    public function planCommand(PanelActor $actor, string $command, array $args): PlanResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::plan($actor, $command, $args));
        } catch (PanelRefused $refused) {
            return new PlanResult($source, null, $refused->reasonCode, true);
        }
        if ($answer->status !== PanelAnswerStatus::Ok) {
            return new PlanResult($source, null, $answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused);
        }
        if ($answer->kind !== 'plan') {
            return new PlanResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
        try {
            return new PlanResult($source, PanelPlan::fromData($answer->data));
        } catch (UnexpectedValueException) {
            return new PlanResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
    }

    /**
     * That action confirmed: the gateway starts it and answers a job.
     *
     * @param array<string, string> $args
     */
    public function startCommand(PanelActor $actor, string $command, array $args): JobStartResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::confirmed($actor, $command, $args));
        } catch (PanelRefused $refused) {
            return new JobStartResult($source, null, $refused->reasonCode, true);
        }
        if ($answer->status !== PanelAnswerStatus::Ok) {
            return new JobStartResult($source, null, $answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused);
        }
        $jobId = $answer->jobId;

        return $answer->kind === 'job' && $jobId !== null
            ? new JobStartResult($source, $jobId)
            : new JobStartResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
    }

    /** Where a started action stands and what it has printed after the cursor the screen last saw. */
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
        return new JobViewResult($source, $status, $log, $reason);
    }

    /** Stops a job that is still running; a finished one is refused with `not_running`, which the screen says. */
    public function cancel(PanelActor $actor, string $jobId): JobViewResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, 'job:cancel', ['job_id' => $jobId]));
        } catch (PanelRefused $refused) {
            return new JobViewResult($source, null, null, $refused->reasonCode, true);
        }
        $failure = self::cancelFailure($answer);

        return $failure === null
            ? $this->view($actor, $jobId)
            : new JobViewResult($source, null, null, $failure[0], $failure[1]);
    }

    /** @return array{0: string, 1: bool}|null */
    private static function cancelFailure(PanelAnswer $answer): ?array
    {
        return $answer->status === PanelAnswerStatus::Ok ? null
            : [$answer->reason ?? PanelReason::ANSWER_INVALID, $answer->status === PanelAnswerStatus::Refused];
    }
}
