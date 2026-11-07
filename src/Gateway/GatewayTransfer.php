<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Core\Gateway\GatewayAdapter;
use Larena\Core\Gateway\GatewayRejected;
use Larena\Core\Gateway\GatewayRequest;
use Larena\Core\Gateway\GatewayRunner;
use Larena\Core\Process\ProcessExecutor;
use Larena\Core\Process\ProcessRejected;
use Larena\Core\Process\ProcessSpecification;

/**
 * What the live drivers share for files (Panel plan 6.1, 6.2): a confirmed upload or save is the request line and the
 * attachment as one stream, with time for its size; a download goes straight to a local file, never into memory, and
 * is read back by PanelDownload.
 *
 * @internal
 */
final class GatewayTransfer
{
    /** Seconds a transfer is given beyond the driver's own timeout, per megabyte: at least 1 MB/s. */
    private const float SECONDS_PER_MEGABYTE = 1.0;
    /** A download of up to the largest size it may have. */
    private const float DOWNLOAD_TIMEOUT_SECONDS = 3600.0;
    /** The answer's line before the bytes. */
    private const int HEADER_BYTES = 65_536;

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PanelRefused
     */
    public static function arguments(PanelRequest $request): array
    {
        $arguments = ['request_id' => $request->requestId, 'line' => $request->toLine()];
        if ($request->sendsBytes() && $request->attachment !== null) {
            $arguments['stdin'] = $request->stdin();
            $arguments['timeout'] = 30.0 + $request->attachment->size / 1_048_576 * self::SECONDS_PER_MEGABYTE;
        }

        return $arguments;
    }

    /**
     * Several reads of one person as one `batch` call (simai-env 1.52.0). The batch carries the first request's id;
     * its answers are matched back by order. Null when the batch is not answered as a batch.
     *
     * @param list<PanelRequest> $requests
     *
     * @return list<PanelAnswer>|null
     */
    public static function batch(GatewayAdapter $adapter, ProcessExecutor $executor, array $requests): ?array
    {
        if (count($requests) < 2 || count($requests) > \Larena\Env\Contracts\PanelBatchGateway::MOST) {
            return null;
        }
        $actor = $requests[0]->actor;
        $reads = [];
        foreach ($requests as $request) {
            if ($request->actor != $actor || $request->confirm || $request->attachment !== null || PanelCommands::kind($request->command) !== PanelCommands::READ) {
                return null;
            }
            $reads[] = $request->args === [] ? ['command' => $request->command] : ['command' => $request->command, 'args' => (object) $request->args];
        }
        $id = $requests[0]->requestId;
        $line = json_encode(['schema' => PanelRequest::SCHEMA, 'request_id' => $id, 'actor' => $actor->toDocument(), 'command' => 'batch', 'requests' => $reads],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        try {
            $answer = (new GatewayRunner($executor))->run($adapter, new GatewayRequest('batch', ['request_id' => $id, 'line' => $line]));
        } catch (GatewayRejected|ProcessRejected) {
            return null;
        }
        $document = $answer->payload;
        $answers = $document['answers'] ?? null;
        if (($document['status'] ?? null) !== 'ok' || ($document['kind'] ?? null) !== 'batch' || !is_array($answers) || !array_is_list($answers) || count($answers) !== count($requests)) {
            return null;
        }
        $result = [];
        foreach ($requests as $index => $request) {
            $one = $answers[$index];
            $result[] = is_array($one) && ($one['request_id'] ?? null) === $id.'.'.$index
                ? PanelAnswer::fromDocument($request->requestId, $one)
                : PanelAnswer::failed($request->requestId, PanelReason::ANSWER_INVALID);
        }

        return $result;
    }

    public static function download(GatewayAdapter $adapter, ProcessExecutor $executor, PanelRequest $request, string $into): PanelDownload
    {
        if (!PanelCommands::streams($request->command)) {
            return PanelDownload::of(PanelAnswer::refused($request->requestId, PanelReason::NOT_ALLOWED));
        }
        $sink = @fopen($into, 'wb');
        if ($sink === false) {
            return PanelDownload::of(PanelAnswer::failed($request->requestId, PanelReason::BAD_REQUEST));
        }
        try {
            $base = $adapter->invocation(new GatewayRequest($request->command, ['request_id' => $request->requestId, 'line' => $request->toLine()]));
            $result = $executor->run(new ProcessSpecification(
                argv: $base->argv,
                workingDirectory: $base->workingDirectory,
                environment: $base->environment,
                stdin: $base->stdin,
                stdoutSink: $sink,
                // One byte over the largest download and its line stops the child: the file is refused, not cut.
                stdoutLimitBytes: PanelCommands::DOWNLOAD_MAX_BYTES + self::HEADER_BYTES,
                timeoutSeconds: max($base->timeoutSeconds, self::DOWNLOAD_TIMEOUT_SECONDS),
                captureStdout: false,
            ));
        } catch (GatewayRejected|ProcessRejected $rejected) {
            fclose($sink);

            return PanelDownload::of(PanelAnswer::failed($request->requestId, $rejected->reasonCode));
        }
        fclose($sink);
        if ($result->stdoutTruncated) {
            return PanelDownload::of(PanelAnswer::failed($request->requestId, PanelReason::TOO_LARGE));
        }
        if (!$result->started || $result->stopReason !== null || $result->signaled || $result->exitCode !== 0) {
            return PanelDownload::of(PanelAnswer::failed($request->requestId, 'gateway_process_failed'));
        }

        return PanelDownload::read($request->requestId, $into);
    }
}
