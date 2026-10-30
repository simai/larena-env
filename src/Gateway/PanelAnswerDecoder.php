<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use JsonException;
use Larena\Core\Gateway\GatewayAnswer;
use Larena\Core\Gateway\GatewayManifest;
use Larena\Core\Gateway\GatewayRejected;
use Larena\Core\Gateway\GatewayRequest;
use Larena\Core\Process\ProcessResult;

/**
 * What every live driver shares: the protocol the gateway speaks and how its answer is read. The answer's `schema`
 * (simai-panel-answer/1) is split into the protocol and version larena/core's runner compares with the manifest.
 */
final class PanelAnswerDecoder
{
    public const string PROTOCOL = 'simai-panel';
    public const int PROTOCOL_VERSION = 1;

    /** A host description with many sites is well above the runner's default limit. */
    public const int STDOUT_LIMIT_BYTES = 8_388_608;

    private function __construct()
    {
    }

    public static function manifest(): GatewayManifest
    {
        return new GatewayManifest('simai-env.panel-gate', self::PROTOCOL, self::PROTOCOL_VERSION, PanelCommands::names());
    }

    public static function decode(GatewayRequest $request, ProcessResult $result): GatewayAnswer
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
