<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Core\Gateway\GatewayRejected;
use Larena\Core\Gateway\GatewayRequest;
use Larena\Core\Gateway\GatewayRunner;
use Larena\Core\Process\ProcessExecutor;
use Larena\Core\Process\ProcessRejected;
use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;

/**
 * The live driver of a panel installed on the server it manages. Failures become reason codes only: sudo's stderr is
 * never kept, shown or logged. The gateway keeps its own log.
 */
final readonly class SudoGateway implements PanelGateway, PanelFileGateway
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
        // A download is never asked this way: its bytes follow the answer (download()).
        if (PanelCommands::streams($request->command)) {
            return PanelAnswer::refused($request->requestId, PanelReason::NOT_ALLOWED);
        }
        try {
            $arguments = GatewayTransfer::arguments($request);
        } catch (PanelRefused $refused) {
            return PanelAnswer::refused($request->requestId, $refused->reasonCode);
        }
        try {
            $answer = (new GatewayRunner($this->executor))->run(
                new SudoGatewayAdapter($this->target),
                new GatewayRequest($request->command, $arguments),
            );
        } catch (GatewayRejected|ProcessRejected $rejected) {
            return PanelAnswer::failed($request->requestId, $rejected->reasonCode);
        } finally {
            if (is_resource($arguments['stdin'] ?? null)) {
                fclose($arguments['stdin']);
            }
        }

        return PanelAnswer::fromDocument($request->requestId, $answer->payload);
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        return GatewayTransfer::download(new SudoGatewayAdapter($this->target), $this->executor, $request, $into);
    }
}
