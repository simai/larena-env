<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Closure;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use LogicException;

/**
 * Carries a panel operation out through the gateway (plan accepted in msg-3ee29ace, §5.3). The proposal asks the server
 * for its plan (PanelOperations::planCommand) and runs nothing; the approved change starts the job
 * (PanelOperations::startCommand), whose own rules stay: no audit log, no start, and the audit chain is written as
 * `env.server.change_started` with the operation and its reversibility. The stop of a job goes the same way
 * (PanelOperations::cancel). The simai-env command is the descriptor's `gateway_command`, never the caller's.
 */
final readonly class GatewayOperationHandler implements OperationProposalHandler
{
    /** @param Closure(): EnvOperationCall $call the operation the panel is carrying out now */
    public function __construct(private Closure $call)
    {
    }

    /** @return array<string, mixed> */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        [$call, $panel, $actor, $command] = $this->current($descriptor);
        $plan = $panel->planCommand($actor, $command, self::arguments($context), $call->attachment);
        $call->result = $plan;

        return array_filter([
            'kind' => 'gateway_plan',
            'operation' => $descriptor->name,
            'reversible' => EnvOperationCatalog::reversibility($descriptor->name),
            'command' => $command,
            'planned' => $plan->plan !== null,
            'reason' => $plan->reason,
            'plan_id' => $plan->requestId,
            'plan_operation_id' => $plan->operationId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        [$call, $panel, $actor, $command] = $this->current($descriptor);
        $args = self::arguments($context);
        if ($command === 'job:cancel') {
            $jobId = is_string($args['job_id'] ?? null) ? $args['job_id'] : '';
            $view = $panel->cancel($actor, $jobId, $descriptor->name);
            $call->result = $view;

            return array_filter(['operation' => $descriptor->name, 'reversible' => EnvOperationCatalog::reversibility($descriptor->name),
                'command' => $command, 'job_id' => $jobId, 'state' => $view->status?->state->value, 'reason' => $view->reason],
                static fn (mixed $value): bool => $value !== null);
        }
        $started = $panel->startCommand($actor, $command, $args, $call->attachment, $descriptor->name, EnvOperationCatalog::reversibility($descriptor->name));
        $call->result = $started;

        return $started->chain() + array_filter(['reason' => $started->reason], static fn (?string $value): bool => $value !== null);
    }

    /** @return array{0: EnvOperationCall, 1: PanelOperations, 2: \Larena\Env\Gateway\PanelActor, 3: string} */
    private function current(OperationDescriptor $descriptor): array
    {
        $call = ($this->call)();
        $command = $descriptor->metadata['gateway_command'] ?? null;
        if ($call->panel === null || $call->actor === null) {
            // Only the panel's own screens carry a person to the gateway; an operation run another way has no one to ask as.
            throw new LogicException('env_operation_call_missing');
        }
        if (!is_string($command) || EnvOperationCatalog::command($descriptor->name) !== $command) {
            throw new LogicException('env_operation_command_mismatch');
        }

        return [$call, $call->panel, $call->actor, $command];
    }

    /**
     * The command's arguments: the approved input without the file's facts, which travel as the attachment itself.
     *
     * @return array<string, string|list<string>>
     */
    private static function arguments(OperationContext $context): array
    {
        $args = $context->metadata;
        unset($args['attachment']);

        /** @var array<string, string|list<string>> $args */
        return $args;
    }
}
