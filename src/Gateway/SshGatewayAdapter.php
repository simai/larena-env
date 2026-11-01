<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Core\Gateway\GatewayAdapter;
use Larena\Core\Gateway\GatewayAnswer;
use Larena\Core\Gateway\GatewayManifest;
use Larena\Core\Gateway\GatewayRejected;
use Larena\Core\Gateway\GatewayRequest;
use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;

/**
 * Carries a request to simai-env's gateway: ssh with a key that has a forced command on the server, so no remote
 * command is ever named. Everything from the request travels on stdin as one JSON line; the argv holds only the
 * configured target, and no shell is involved.
 */
final readonly class SshGatewayAdapter implements GatewayAdapter
{
    public function __construct(private SshGatewayTarget $target)
    {
    }

    public function manifest(): GatewayManifest
    {
        return PanelAnswerDecoder::manifest();
    }

    public function invocation(GatewayRequest $request): ProcessSpecification
    {
        $line = $request->arguments['line'] ?? null;
        if (!is_string($line)) {
            throw new GatewayRejected('gateway_request_invalid', 'The request carries no protocol line.');
        }
        // A confirmed upload or save sends its file after the line, as one stream (PanelRequest::stdin()).
        $stdin = $request->arguments['stdin'] ?? $line;
        $timeout = max($this->target->timeoutSeconds, (float) ($request->arguments['timeout'] ?? 0));

        return new ProcessSpecification(
            argv: [
                $this->target->binary,
                '-F', 'none',
                '-T',
                '-i', $this->target->keyPath,
                '-p', (string) $this->target->port,
                '-o', 'BatchMode=yes',
                '-o', 'IdentitiesOnly=yes',
                '-o', 'IdentityAgent=none',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$this->target->knownHostsPath,
                '-o', 'ConnectTimeout=10',
                '-o', 'ForwardAgent=no',
                '-o', 'ForwardX11=no',
                '-o', 'ClearAllForwardings=yes',
                '-o', 'LogLevel=ERROR',
                '--',
                $this->target->user.'@'.$this->target->host,
            ],
            workingDirectory: '/',
            environment: [],
            stdin: $stdin,
            stdoutLimitBytes: PanelAnswerDecoder::STDOUT_LIMIT_BYTES,
            timeoutSeconds: $timeout,
        );
    }

    public function answer(GatewayRequest $request, ProcessResult $result): GatewayAnswer
    {
        return PanelAnswerDecoder::decode($request, $result);
    }
}
