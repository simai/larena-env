<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/** Where a started action stands, as `job:status` reports it. */
enum JobState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** The target was locked by another run: nothing of this job has happened. */
    case Busy = 'busy';
    case Cancelled = 'cancelled';

    public function isOver(): bool
    {
        return $this !== self::Running;
    }
}
