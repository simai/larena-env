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

        return new ProcessSpecification(
            argv: [$this->target->binary, '-n', $this->target->gate],
            workingDirectory: '/',
            environment: [],
            stdin: $line,
            stdoutLimitBytes: PanelAnswerDecoder::STDOUT_LIMIT_BYTES,
            timeoutSeconds: $this->target->timeoutSeconds,
        );
    }

    public function answer(GatewayRequest $request, ProcessResult $result): GatewayAnswer
    {
        return PanelAnswerDecoder::decode($request, $result);
    }
}
