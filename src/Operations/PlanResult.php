<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * What an action would do, or the reason code why the panel cannot say. Nothing has run either way. A plan the server
 * refused or could not make says what stopped it (simai-env 1.60.0 `data.checks`) and, for a named refusal, its own
 * words (`data.detail`, simai-env 1.61.0).
 */
final readonly class PlanResult
{
    public function __construct(
        public string $source,
        public ?PanelPlan $plan,
        public ?string $reason = null,
        public bool $refused = false,
        public ?PlanChecks $checks = null,
        public ?string $detail = null,
        // What a restore's check found before it refused (simai-env 1.63.4 `data.restore`): the tables, the engine.
        /** @var array<string, mixed>|null */
        public ?array $restore = null,
        // The audit chain (auditor msg-3d61bd36, point 2): the plan's own request id — the plan id the start and the audit
        // event name — and the server's operation id of that answer, which links it to the server's own log.
        public ?string $requestId = null,
        public ?string $operationId = null,
    ) {
    }

    /** Whether the server said what stops the action: a named refusal with its words, or a plan check that failed. */
    public function said(): bool
    {
        return $this->plan === null && (($this->checks !== null && $this->checks->blockers !== []) || $this->detail !== null);
    }
}
