<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use RuntimeException;

/**
 * The server gave the panel nothing to look at (critics 2026-11-07: an empty bar then read as «all is well»). Thrown
 * out of the kept read on purpose, so the bar says so on every page and nothing is kept.
 *
 * @internal
 */
final class ServerUnanswered extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The server did not answer: '.$reason);
    }
}
