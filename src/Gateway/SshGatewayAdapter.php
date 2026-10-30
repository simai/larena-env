<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use JsonException;
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
 * configured target, and no shell is involved. The answer's `schema` (simai-panel-answer/1) is split into the protocol
 * and version larena/core's runner compares with the manifest.
 */
final readonly class SshGatewayAdapter implements GatewayAdapter
{
    public const string PROTOCOL = 'simai-panel';
    public const int PROTOCOL_VERSION = 1;

    /** A host description with many sites is well above the runner's default limit. */
    private const int STDOUT_LIMIT_BYTES = 8_388_608;

    public function __construct(private SshGatewayTarget $target)
    {
    }

    public function manifest(): GatewayManifest
    {
        return new GatewayManifest('simai-env.panel-gate', self::PROTOCOL, self::PROTOCOL_VERSION, PanelCommands::names());
    }

    public function invocation(GatewayRequest $request): ProcessSpecification
    {
        $line = $request->arguments['line'] ?? null;
        if (!is_string($line)) {
            throw new GatewayRejected('gateway_request_invalid', 'The request carries no protocol line.');
        }

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
            stdin: $line,
            stdoutLimitBytes: self::STDOUT_LIMIT_BYTES,
            timeoutSeconds: $this->target->timeoutSeconds,
        );
    }

    public function answer(GatewayRequest $request, ProcessResult $result): GatewayAnswer
    {
        // The gateway always exits with zero and says what happened in its answer; anything else is ssh or sudo failing.
        if ($result->exitCode !== 0) {
            throw new GatewayRejected('gateway_process_failed', 'The gateway did not answer.');
        }
        try {
            $document = json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GatewayRejected('gateway_answer_invalid', 'The gateway answer is not JSON.');
        }
        if (!is_array($document) || !is_string($document['schema'] ?? null)
            || preg_match('#\Asimai-panel-answer/([1-9][0-9]{0,5})\z#D', $document['schema'], $schema) !== 1) {
            throw new GatewayRejected('gateway_answer_invalid', 'The gateway answer does not name its schema.');
        }
        // A refusal of a request it could not read has no id; any other answer must be to this request.
        $id = $document['request_id'] ?? null;
        if ($id !== $request->arguments['request_id'] && !($id === null && ($document['status'] ?? null) === 'refused')) {
            throw new GatewayRejected('gateway_answer_invalid', 'The gateway answered another request.');
        }
        unset($document['schema']);

        return new GatewayAnswer(self::PROTOCOL, (int) $schema[1], $document);
    }
}
