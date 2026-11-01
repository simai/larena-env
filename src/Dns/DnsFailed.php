<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

use RuntimeException;

/** A refusal from the panel's DNS, by a reason the screens say in words (larena-env::admin.dns.reasons). */
final class DnsFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
