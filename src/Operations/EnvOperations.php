<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Core\Contracts\OperationApproval;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Runtime\ProposalDigest;
use Larena\Core\Runtime\RegistryOperationRuntime;
use Larena\Env\Dns\DnsFailed;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAttachment;
use Larena\Env\Gateway\PanelReason;

/**
 * The screens' one way to change the server (plan accepted in msg-3ee29ace, §5.4): a Core operation by its Larena name,
 * through Core's registry runtime. The plan is the operation's proposal: Core checks the person holds the operation's
 * right, then the handler asks the server what it would do. The confirmed change is the operation executed against the
 * approval of exactly that input (risk `external` always requires one): Core checks the right again, writes its own
 * decision and result to the audit log, and only then the handler starts the job.
 *
 * The person's confirmation is still the plan receipt the screen gave (PlanReceipts::confirms, checked before a start);
 * the approval Core is handed here is that confirmed input's digest, issued for the person who confirmed it.
 *
 * Without Core's runtime (it is offered only where the audit log is, AccessRuntimeRegistrar) nothing is planned or
 * started: refused `audit_unavailable`, as a change without the audit log always was. Reads stay outside Core: the
 * job's page, its log and the list of jobs come straight from PanelOperations.
 */
final readonly class EnvOperations
{
    public function __construct(
        private PanelOperations $panel,
        private ?RegistryOperationRuntime $runtime,
        private EnvOperationCall $call,
    ) {
    }

    /**
     * What the operation would do. Nothing runs.
     *
     * @param array<string, string|list<string>> $args the command's arguments, as the gateway takes them
     */
    public function plan(PanelActor $actor, string $operation, array $args, ?PanelAttachment $attachment = null): PlanResult
    {
        $refused = $this->refusal($operation);
        if ($refused !== null) {
            return new PlanResult($this->panel->source(), null, $refused, true);
        }
        [$result, $planned] = $this->run($actor, $operation, $args, $attachment, false);
        if ($planned instanceof PlanResult) {
            return $planned;
        }

        return new PlanResult($this->panel->source(), null, self::reasonOf($result), true);
    }

    /**
     * The operation, confirmed: the gateway starts it and answers a job.
     *
     * @param array<string, string|list<string>> $args
     */
    public function start(PanelActor $actor, string $operation, array $args, ?PanelAttachment $attachment = null): JobStartResult
    {
        $who = $actor->impersonatedBy ?? $actor->user;
        $onBehalf = $actor->impersonatedBy !== null ? $actor->user : null;
        $command = EnvOperationCatalog::command($operation);
        $refused = $this->refusal($operation);
        if ($refused !== null) {
            return new JobStartResult($this->panel->source(), null, $refused, true, who: $who, onBehalfOf: $onBehalf, command: $command,
                operation: $operation, reversible: EnvOperationCatalog::reversibility($operation));
        }
        [$result, $started] = $this->run($actor, $operation, $args, $attachment, true);
        if ($started instanceof JobStartResult) {
            return $started;
        }

        return new JobStartResult($this->panel->source(), null, self::reasonOf($result), true, who: $who, onBehalfOf: $onBehalf, command: $command,
            operation: $operation, reversible: EnvOperationCatalog::reversibility($operation));
    }

    /**
     * A site's action of its own screen (SiteOperation), as the operation its command is.
     *
     * @param array<string, string> $input the form's own fields
     */
    public function planSite(PanelActor $actor, SiteOperation $operation, string $domain, array $input = []): PlanResult
    {
        return $this->plan($actor, (string) EnvOperationCatalog::primaryFor($operation->command()), $operation->arguments($domain, $input));
    }

    /** @param array<string, string> $input */
    public function startSite(PanelActor $actor, SiteOperation $operation, string $domain, array $input = []): JobStartResult
    {
        return $this->start($actor, (string) EnvOperationCatalog::primaryFor($operation->command()), $operation->arguments($domain, $input));
    }

    /**
     * A job's command asked again («Повторить»): the operation the panel starts that command as.
     *
     * @param array<string, string|list<string>> $args
     */
    public function planAgain(PanelActor $actor, string $command, array $args): PlanResult
    {
        return $this->plan($actor, EnvOperationCatalog::primaryFor($command) ?? '', $args);
    }

    /** @param array<string, string|list<string>> $args */
    public function startAgain(PanelActor $actor, string $command, array $args): JobStartResult
    {
        return $this->start($actor, EnvOperationCatalog::primaryFor($command) ?? '', $args);
    }

    /** Stops a running job (env.job.cancel): refused without its right or the audit log, written when the server took it. */
    public function cancel(PanelActor $actor, string $jobId): JobViewResult
    {
        $operation = 'env.job.cancel';
        $refused = $this->refusal($operation);
        if ($refused !== null) {
            return new JobViewResult($this->panel->source(), null, null, $refused, true);
        }
        [$result, $view] = $this->run($actor, $operation, ['job_id' => $jobId], null, true);
        if ($view instanceof JobViewResult) {
            return $view;
        }

        return new JobViewResult($this->panel->source(), null, null, self::reasonOf($result), true);
    }

    /**
     * A change of DNS through the provider's API (env.dns.connect, env.dns.disconnect, env.dns.records_apply,
     * env.site.dns_point), confirmed on the screen before.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> what the change answered (the zones found, the batch made)
     *
     * @throws DnsFailed the provider's or the panel's refusal, in its reason code
     */
    public function dns(PanelActor $actor, string $operation, array $input): array
    {
        $refused = EnvOperationCatalog::command($operation) === null && EnvOperationCatalog::has($operation) ? $this->refusal($operation)
            : PanelReason::NOT_ALLOWED;
        if ($refused !== null) {
            throw new DnsFailed($refused);
        }
        $this->call->begin($this->panel, $actor);
        try {
            $result = $this->execute($actor, $operation, $input);
        } finally {
            [$answer, $failure] = $this->call->end();
        }
        if ($failure instanceof DnsFailed) {
            throw $failure;
        }
        if (!is_array($answer)) {
            throw new DnsFailed(self::reasonOf($result));
        }

        return $answer;
    }

    /** @return array<string, PanelJobStatus>|null */
    public function jobs(PanelActor $actor): ?array
    {
        return $this->panel->jobs($actor);
    }

    public function jobPage(PanelActor $actor, ?string $before = null, ?int $limit = null, ?string $state = null, ?string $since = null): ?JobPage
    {
        return $this->panel->jobPage($actor, $before, $limit, $state, $since);
    }

    public function view(PanelActor $actor, string $jobId, int $after = 0): JobViewResult
    {
        return $this->panel->view($actor, $jobId, $after);
    }

    /** Why the operation cannot be asked at all: not one of the panel's, or no runtime (no audit log). */
    private function refusal(string $operation): ?string
    {
        if (!EnvOperationCatalog::has($operation)) {
            return PanelReason::NOT_ALLOWED;
        }

        return $this->runtime === null ? PanelReason::AUDIT_UNAVAILABLE : null;
    }

    /**
     * The proposal or the approved execution, with what the handler left for the screen.
     *
     * @param array<string, string|list<string>> $args
     *
     * @return array{0: ?OperationResult, 1: mixed}
     */
    private function run(PanelActor $actor, string $operation, array $args, ?PanelAttachment $attachment, bool $execute): array
    {
        $input = $args;
        if ($attachment !== null) {
            $input['attachment'] = ['sha256' => $attachment->sha256, 'size' => $attachment->size];
        }
        $this->call->begin($this->panel, $actor, $attachment);
        try {
            $result = $execute ? $this->execute($actor, $operation, $input) : $this->runtime?->propose($operation, self::context($actor, $input));
        } finally {
            [$left] = $this->call->end();
        }

        return [$result, $left];
    }

    /** @param array<string, mixed> $input */
    private function execute(PanelActor $actor, string $operation, array $input): ?OperationResult
    {
        $who = $actor->impersonatedBy ?? $actor->user;
        $approval = new OperationApproval($operation, ProposalDigest::forInput($operation, $input), $who);

        return $this->runtime?->execute($operation, self::context($actor, $input), $approval);
    }

    /** @param array<string, mixed> $input */
    private static function context(PanelActor $actor, array $input): OperationContext
    {
        // The rights are those of the person signed in, also while they look at the panel as someone else.
        return new OperationContext($actor->impersonatedBy ?? $actor->user, 'env-'.bin2hex(random_bytes(12)), metadata: $input);
    }

    /** Core's refusal in the panel's reason codes: a right not held is `not_allowed`, an audit log not written `audit_unavailable`. */
    private static function reasonOf(?OperationResult $result): string
    {
        $code = is_string($result?->normalizedError['code'] ?? null) ? $result->normalizedError['code'] : null;
        $status = $result?->decision->status->value;

        return match (true) {
            $result === null => PanelReason::AUDIT_UNAVAILABLE,
            in_array($status, ['denied'], true), in_array($code, ['access_denied', 'access_contract_invalid', 'operation_not_registered'], true) => PanelReason::NOT_ALLOWED,
            is_string($code) && str_starts_with($code, 'audit_') => PanelReason::AUDIT_UNAVAILABLE,
            default => PanelReason::ANSWER_INVALID,
        };
    }
}
