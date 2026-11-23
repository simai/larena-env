<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAttachment;
use Throwable;

/**
 * What the panel hands its operation handlers besides Core's context, for one operation at a time: who asks at which
 * level (the gateway narrows an owner's answers), the file a change carries, and the panel's operations of this
 * request. The handler leaves its typed result here (the plan, the job, the stop) and a DNS refusal, for the screen.
 * Nothing in it is part of the approved input: Core's digest covers the input alone.
 */
final class EnvOperationCall
{
    public ?PanelOperations $panel = null;
    public ?PanelActor $actor = null;
    public ?PanelAttachment $attachment = null;
    public mixed $result = null;
    public ?Throwable $failure = null;

    public function begin(PanelOperations $panel, PanelActor $actor, ?PanelAttachment $attachment = null): void
    {
        $this->panel = $panel;
        $this->actor = $actor;
        $this->attachment = $attachment;
        $this->result = null;
        $this->failure = null;
    }

    /** @return array{0: mixed, 1: ?Throwable} what the handler left, and the call cleared */
    public function end(): array
    {
        $left = [$this->result, $this->failure];
        $this->panel = null;
        $this->actor = null;
        $this->attachment = null;
        $this->result = null;
        $this->failure = null;

        return $left;
    }
}
