<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/** What an action would do, or the reason code why the panel cannot say. Nothing has run either way. */
final readonly class PlanResult
{
    public function __construct(
        public string $source,
        public ?PanelPlan $plan,
        public ?string $reason = null,
        public bool $refused = false,
    ) {
    }
}
