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
 * Carries a request to simai-env's gateway on this server: `sudo -n <gate>`. The sudo rule allows the gate with no
 * arguments at all, so the argv is fixed; the request travels on stdin as one JSON line, and no shell is involved.
 */
final readonly class SudoGatewayAdapter implements GatewayAdapter
{
    public function __construct(private SudoGatewayTarget $target)
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
            argv: [$this->target->binary, '-n', $this->target->gate],
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
