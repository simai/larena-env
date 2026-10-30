<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Core\Gateway\GatewayRejected;
use Larena\Core\Gateway\GatewayRequest;
use Larena\Core\Gateway\GatewayRunner;
use Larena\Core\Process\ProcessExecutor;
use Larena\Core\Process\ProcessRejected;
use Larena\Env\Contracts\PanelGateway;

/**
 * The live driver of a panel installed on the server it manages. Failures become reason codes only: sudo's stderr is
 * never kept, shown or logged. The gateway keeps its own log.
 */
final readonly class SudoGateway implements PanelGateway
{
    public function __construct(
        private SudoGatewayTarget $target,
        private ProcessExecutor $executor,
    ) {
    }

    public function source(): string
    {
        return 'sudo';
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        try {
            $answer = (new GatewayRunner($this->executor))->run(
                new SudoGatewayAdapter($this->target),
                new GatewayRequest($request->command, ['request_id' => $request->requestId, 'line' => $request->toLine()]),
            );
        } catch (GatewayRejected|ProcessRejected $rejected) {
            return PanelAnswer::failed($request->requestId, $rejected->reasonCode);
        }

        return PanelAnswer::fromDocument($request->requestId, $answer->payload);
    }
}
