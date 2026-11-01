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
 * The live driver, over larena/core's gateway runner. Failures become reason codes only: ssh's stderr can name the key's
 * path, so it never reaches an answer, a message or a log. This class writes no log at all; the gateway keeps its own.
 */
final readonly class SshGateway implements PanelGateway, PanelFileGateway
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
                new SshGatewayAdapter($this->target),
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
        if (!is_readable($this->target->keyPath) || !is_readable($this->target->knownHostsPath)) {
            return PanelDownload::of(PanelAnswer::failed($request->requestId, PanelReason::CREDENTIALS_UNREADABLE));
        }

        return GatewayTransfer::download(new SshGatewayAdapter($this->target), $this->executor, $request, $into);
    }
}
