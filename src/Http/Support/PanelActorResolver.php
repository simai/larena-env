<?php

declare(strict_types=1);

namespace Larena\Env\Http\Support;

use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;

/**
 * Who asks the gateway, at which of the panel's two levels (owner decision 2026-10-31). Whoever may read the
 * server (env.server.read) asks for the whole server, owner null. Anyone else asks only as an owner of the
 * server, whose sites the gateway narrows the answer to; a person not yet tied to an owner is refused, never
 * sent as the whole server. Every screen of the panel asks through here.
 */
final readonly class PanelActorResolver
{
    public function __construct(private AccessOperationAuthorizer $authorizer)
    {
    }

    /** @return array{0: string, 1: ?string} the person and the owner they act as */
    public function resolve(Request $request): array
    {
        $user = (string) $request->attributes->get('larena_access_actor', '');
        if ($this->authorizer->authorize($request, 'env.server.read')->isAllowed()) {
            return [$user, null];
        }
        // The tie between a person and an owner of the server comes with the owners' screen (Panel plan, stage 2).
        throw new PanelRefused(PanelReason::OWNER_UNMAPPED);
    }
}
