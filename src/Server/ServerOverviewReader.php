<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use UnexpectedValueException;

/** Reads the first screen's data through whichever driver is configured. Never throws: a refusal is a reason code. */
final readonly class ServerOverviewReader
{
    public function __construct(private PanelGateway $gateway)
    {
    }

    public function read(string $user, ?string $owner = null): ServerOverviewResult
    {
        $source = $this->gateway->source();
        try {
            $request = PanelRequest::read(new PanelActor($user, $owner), 'self:describe');
        } catch (PanelRefused $refused) {
            return new ServerOverviewResult($source, null, $refused->reasonCode, true);
        }
        $answer = $this->gateway->ask($request);
        if ($answer->status !== PanelAnswerStatus::Ok) {
            return new ServerOverviewResult($source, null, $answer->reason, $answer->status === PanelAnswerStatus::Refused);
        }
        if ($answer->kind !== 'result') {
            return new ServerOverviewResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
        try {
            return new ServerOverviewResult($source, ServerOverview::fromHostDescription($answer->data));
        } catch (UnexpectedValueException) {
            return new ServerOverviewResult($source, null, PanelReason::ANSWER_SHAPE_INVALID);
        }
    }
}
