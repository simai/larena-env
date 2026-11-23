<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * The plan a change comes from, from the plan's screen to the start (auditor msg-3d61bd36, point 2). One trail serves a
 * request (a scoped binding): PanelOperations notes the plan the server answered, PlanReceipts keeps it in the receipt
 * the plan's screen gives, and the confirmation that brings the receipt back hands it to the start, which writes it into
 * the job's receipt and the audit event. Nothing here is a secret: the server's request and operation ids, the command.
 */
final class PlanTrail
{
    /** @var array{command: string, plan_id: string, plan_operation_id: ?string}|null */
    private ?array $planned = null;

    /** @var array{command: string, plan_id: string, plan_operation_id: ?string}|null */
    private ?array $confirmed = null;

    /** The plan the server just answered, for the receipt the screen gives next. */
    public function planned(string $command, string $planId, ?string $operationId): void
    {
        $this->planned = ['command' => $command, 'plan_id' => $planId, 'plan_operation_id' => $operationId];
    }

    /** @return array{command: string, plan_id: string, plan_operation_id: ?string}|null */
    public function last(): ?array
    {
        return $this->planned;
    }

    /**
     * The plan a confirmed receipt kept, for the start that follows it.
     *
     * @param array<array-key, mixed>|null $plan
     */
    public function confirm(?array $plan): void
    {
        $this->confirmed = is_array($plan) && is_string($plan['command'] ?? null) && is_string($plan['plan_id'] ?? null)
            ? ['command' => $plan['command'], 'plan_id' => $plan['plan_id'],
                'plan_operation_id' => is_string($plan['plan_operation_id'] ?? null) ? $plan['plan_operation_id'] : null]
            : null;
    }

    /**
     * The confirmed plan of this command, once, else a plan of it the server answered in this same request; null when
     * the start follows no plan of it (a follow-up the panel starts itself, a plan of another command).
     *
     * @return array{command: string, plan_id: string, plan_operation_id: ?string}|null
     */
    public function takeFor(string $command): ?array
    {
        if ($this->confirmed !== null && $this->confirmed['command'] === $command) {
            [$plan, $this->confirmed] = [$this->confirmed, null];

            return $plan;
        }

        return $this->planned !== null && $this->planned['command'] === $command ? $this->planned : null;
    }
}
