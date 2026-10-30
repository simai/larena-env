<?php

declare(strict_types=1);

namespace Larena\Env\Http\Middleware;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Core\FirstRun\FirstRunCoordinator;
use Larena\Env\Panel\SetupLink;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * A panel faces the internet from its first minute, so its first-run screen opens only through the setup link. When the
 * first administrator exists, the token is removed from .env.
 */
final readonly class GuardFirstRun
{
    private const array SETUP_ROUTES = ['larena.auth.admin.setup', 'larena.auth.admin.setup.store'];

    public function __construct(private SetupLink $link, private FirstRunCoordinator $firstRun, private Translator $translator)
    {
    }

    public function handle(Request $request, Closure $next): BaseResponse
    {
        if (!in_array($request->route()?->getName(), self::SETUP_ROUTES, true)) {
            return $next($request);
        }
        if ($this->firstRun->availability()->isAvailable()
            && !$this->link->sessionIsOpen($request->session()->get(SetupLink::SESSION_KEY))) {
            return new Response((string) $this->translator->get('larena-env::panel.setup_link_required'), 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $response = $next($request);
        if (!$this->firstRun->availability()->isAvailable() && $request->session()->has(SetupLink::SESSION_KEY)) {
            $this->link->consume();
            $request->session()->forget(SetupLink::SESSION_KEY);
        }

        return $response;
    }
}
