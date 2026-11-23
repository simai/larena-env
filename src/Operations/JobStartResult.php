<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * The job a confirmed action started, or the reason code why it did not start. A started one is the job's receipt of the
 * audit chain (auditor msg-3d61bd36, point 2): who started it (and for whom, while acting as someone), the start's request
 * id, the plan it follows, the job and the server's operation id; the same set is the audit event `env.server.change_started`.
 */
final readonly class JobStartResult
{
    public function __construct(
        public string $source,
        public ?string $jobId,
        public ?string $reason = null,
        public bool $refused = false,
        public ?string $requestId = null,
        public ?string $operationId = null,
        public ?string $planId = null,
        public ?string $planOperationId = null,
        public ?string $who = null,
        public ?string $onBehalfOf = null,
        public ?string $command = null,
    ) {
    }

    /**
     * The chain as the audit event keeps it: only what is known, never an argument's value.
     *
     * @return array<string, string>
     */
    public function chain(): array
    {
        return array_filter([
            'who' => $this->who,
            'on_behalf_of' => $this->onBehalfOf,
            'command' => $this->command,
            'request_id' => $this->requestId,
            'plan_id' => $this->planId,
            'plan_operation_id' => $this->planOperationId,
            'job_id' => $this->jobId,
            'operation_id' => $this->operationId,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
