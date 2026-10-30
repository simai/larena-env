<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use RuntimeException;

/** A request refused here, before it left, with the code the gateway itself would answer. */
final class PanelRefused extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Panel request refused: '.$reasonCode);
    }
}
