<?php

declare(strict_types=1);

namespace Larena\Env\Http\Middleware;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Support\PanelActorResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every panel screen asks the gateway as the person and the owner they act as (PanelActorResolver); a person the
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

    /** @return array{0: string, 1: ?string} */
    public static function of(Request $request): array
    {
        $actor = $request->attributes->get(self::ATTRIBUTE);

        return is_array($actor) && count($actor) === 2 ? [(string) $actor[0], $actor[1] === null ? null : (string) $actor[1]]
            : throw new PanelRefused(\Larena\Env\Gateway\PanelReason::OWNER_UNMAPPED);
    }
}
