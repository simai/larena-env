<?php

declare(strict_types=1);

namespace Larena\Env\Contracts;

use Larena\Env\Gateway\PanelBatchAnswer;
use Larena\Env\Gateway\PanelRequest;

/**
 * A driver that can ask several reads of one person in one call (simai-env 1.52.0 `batch`): each call starts
 * simai-env, 0.7-1 s on a small server, so five reads one by one wait about five seconds. Answers keep the order of
 * the requests. A server that does not know `batch` refuses it as a whole: the caller then asks one by one. A batch
 * the gateway did not answer at all says so apart: the server is down, and asking each read again would only wait.
 */
interface PanelBatchGateway
{
    /** At most this many reads in one call (simai-env's limit). */
    public const int MOST = 12;

    /**
     * @param list<PanelRequest> $requests reads of one person, 2 to MOST
     *
     * @return PanelBatchAnswer the answers in order, or why the batch as a whole was not answered
     */
    public function askAll(array $requests): PanelBatchAnswer;
}
