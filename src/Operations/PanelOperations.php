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
                $command === 'site:restore-ready' ? \Larena\Env\Admin\RestoreWords::found($answer->data['restore'] ?? null) : null);
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
     * @param array<string, string|list<string>> $args
     * @param ?PanelAttachment $attachment the file the confirmed action sends after its request
     */
    public function startCommand(PanelActor $actor, string $command, array $args, ?PanelAttachment $attachment = null): JobStartResult
    {
        $source = $this->gateway->source();
        try {
            $answer = $this->gateway->ask(PanelRequest::confirmed($actor, $command, $args, $attachment));
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
