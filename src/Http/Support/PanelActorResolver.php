<?php

declare(strict_types=1);

namespace Larena\Env\Http\Support;

use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\People\PersonOwners;

/**
 * Who asks the gateway, at which of the panel's two levels (owner decision 2026-10-31). Whoever may read the
 * server (env.server.read) asks for the whole server, owner null, unless they look at the panel as one of its people:
 * then they ask as that person and their owner, marked as acting for them (impersonated_by). Anyone else asks only as
 * the owner of the server they are tied to (PersonOwners), whose sites the gateway narrows the answer to; a person not
 * tied to an owner is refused, never sent as the whole server. Every screen of the panel asks through here.
 */
final readonly class PanelActorResolver
{
    /** The session key that holds the person a system-level person looks at the panel as. */
    public const string ACTING_AS = 'larena_env.acting_as';
    /** The person's name, for the notice every screen shows while someone acts for them. */
    public const string ACTING_AS_LABEL = 'larena_env.acting_as_label';

    public function __construct(private AccessOperationAuthorizer $authorizer, private ?PersonOwners $owners = null)
    {
    }

    public function resolve(Request $request): PanelActor
    {
        $user = (string) $request->attributes->get('larena_access_actor', '');
        if ($this->authorizer->authorize($request, 'env.server.read')->isAllowed()) {
            $actingAs = $request->hasSession() ? $request->session()->get(self::ACTING_AS) : null;
            if (is_string($actingAs) && $actingAs !== '' && $actingAs !== $user) {
                $owner = $this->owners?->ownerOf($actingAs);
                if ($owner !== null) {
                    return new PanelActor($actingAs, $owner, $user);
                }
                // The person is no longer tied to an owner: back to the whole server, never to a space no one has.
                $request->session()->forget([self::ACTING_AS, self::ACTING_AS_LABEL]);
            }

            return new PanelActor($user);
        }
        $owner = $this->owners?->ownerOf($user);
        if ($owner === null) {
            throw new PanelRefused(PanelReason::OWNER_UNMAPPED);
        }

        return new PanelActor($user, $owner);
    }
}
