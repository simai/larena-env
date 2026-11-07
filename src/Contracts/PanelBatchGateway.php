<?php

declare(strict_types=1);

namespace Larena\Env\Contracts;

use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelRequest;

/**
 * A driver that can ask several reads of one person in one call (simai-env 1.52.0 `batch`): each call starts
 * simai-env, 0.7-1 s on a small server, so five reads one by one wait about five seconds. Answers keep the order of
 * the requests. A server that does not know `batch` refuses it as a whole: the caller then asks one by one.
 */
interface PanelBatchGateway
{
    /** At most this many reads in one call (simai-env's limit). */
    public const int MOST = 12;

    /**
     * @param list<PanelRequest> $requests reads of one person, 2 to MOST
     *
     * @return list<PanelAnswer>|null the answers in order, or null when the server answered the batch as a whole
     *                                with a refusal or failure (an older simai-env): ask one by one
     */
    public function askAll(array $requests): ?array;
}
