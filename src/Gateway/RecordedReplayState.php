<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * What a replay of recorded answers remembers within one gateway: the job it last started and whether the value that
 * job's first answer gives once was given already, so its next answer is the one without it, as on a real server.
 */
final class RecordedReplayState
{
    public ?string $startedCommand = null;

    public bool $secretGiven = false;
}
