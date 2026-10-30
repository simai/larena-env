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
 * The live driver, over larena/core's gateway runner. Failures become reason codes only: ssh's stderr can name the key's
 * path, so it never reaches an answer, a message or a log. This class writes no log at all; the gateway keeps its own.
 */
final readonly class SshGateway implements PanelGateway
{
    public function __construct(
        private SshGatewayTarget $target,
        private ProcessExecutor $executor,
    ) {
    }

    public function source(): string
    {
        return 'ssh';
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        if (!is_readable($this->target->keyPath) || !is_readable($this->target->knownHostsPath)) {
            return PanelAnswer::failed($request->requestId, PanelReason::CREDENTIALS_UNREADABLE);
        }
        try {
            $answer = (new GatewayRunner($this->executor))->run(
                new SshGatewayAdapter($this->target),
                new GatewayRequest($request->command, ['request_id' => $request->requestId, 'line' => $request->toLine()]),
            );
        } catch (GatewayRejected|ProcessRejected $rejected) {
            return PanelAnswer::failed($request->requestId, $rejected->reasonCode);
        }

        return PanelAnswer::fromDocument($request->requestId, $answer->payload);
    }
}
