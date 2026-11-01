<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use RuntimeException;

/**
 * A refusal of FakeFilesGateway, with the gateway's reason code.
 *
 * @internal
 */
final class FakeRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
