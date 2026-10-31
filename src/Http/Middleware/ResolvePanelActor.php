<?php

declare(strict_types=1);

namespace Larena\Env\Http\Middleware;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Support\PanelActorResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every panel screen asks the gateway as the person, the owner they act as and who acts for them (PanelActorResolver); a person the
 * panel cannot place at a level is refused here with the reason, before any request leaves.
 */
final readonly class ResolvePanelActor
{
    public const string ATTRIBUTE = 'larena_env_actor';

    public function __construct(private PanelActorResolver $actors, private Translator $translator)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $request->attributes->set(self::ATTRIBUTE, $this->actors->resolve($request));
        } catch (PanelRefused $refused) {
            return new \Illuminate\Http\Response((string) $this->translator->get('larena-env::admin.reasons.'.$refused->reasonCode), 403);
        }

        return $next($request);
    }

    public static function of(Request $request): PanelActor
    {
        $actor = $request->attributes->get(self::ATTRIBUTE);

        return $actor instanceof PanelActor ? $actor : throw new PanelRefused(\Larena\Env\Gateway\PanelReason::OWNER_UNMAPPED);
    }
}
