<?php

declare(strict_types=1);

namespace Larena\Env\Server;

final readonly class ServiceState
{
    public function __construct(
        public string $name,
        public string $state,
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }
}
