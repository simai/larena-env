<?php

declare(strict_types=1);

namespace Larena\Env\Area;

use Larena\Core\Contracts\ScopeRegistry;
use Larena\Core\Enums\ScopeStatus;

/** Registers the server's scope; a second run changes nothing. Called by a command, never while booting. */
final readonly class ServerAreaRegistrar
{
    public const string ACTOR = 'env.install';

    public function __construct(private ScopeRegistry $scopes)
    {
    }

    /** @return 'applied'|'already_applied'|'archived' */
    public function ensure(?string $correlationId = null): string
    {
        $existing = $this->scopes->read(ServerArea::ref());
        if ($existing !== null) {
            return $existing->status === ScopeStatus::Active ? 'already_applied' : 'archived';
        }
        $this->scopes->create(ServerArea::ref(), ServerArea::LABEL, self::ACTOR, null, $correlationId);

        return 'applied';
    }
}
