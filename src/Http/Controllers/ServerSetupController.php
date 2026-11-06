<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Setup\ServerSetup;
use Larena\Env\Warnings\PanelWarnings;

/**
 * The server's first setup (ENV task 2026-11-06): one step at a time, each done on its own screen or put off, and
 * whatever the server already shows done is passed by itself.
 */
final readonly class ServerSetupController
{
    public function __construct(
        private ServerSetup $setup,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Router $router,
    ) {
    }

    public function show(Request $request, ?string $step = null): mixed
    {
        $states = $this->states(ResolvePanelActor::of($request));
        $step = is_string($step) && array_key_exists($step, ServerSetup::STEPS) ? $step : (ServerSetup::next($states) ?? array_key_first(ServerSetup::STEPS));

        return $this->views->make('larena-env::admin.setup', [
            'step' => $step,
            'number' => array_search($step, array_keys(ServerSetup::STEPS), true) + 1,
            'states' => $states,
            'links' => $this->links(),
            'finished' => ServerSetup::next($states) === null,
        ]);
    }

    public function mark(Request $request): RedirectResponse
    {
        $step = (string) $request->input('step', '');
        $state = $request->input('state') === ServerSetup::DONE ? ServerSetup::DONE : ServerSetup::SKIPPED;
        $this->setup->mark($step, $state, PanelWarnings::now());
        $states = $this->states(ResolvePanelActor::of($request));
        $next = ServerSetup::next($states, $step) ?? ServerSetup::next($states);

        return new RedirectResponse($next === null
            ? route('larena.env.admin.overview', [], false)
            : route('larena.env.admin.setup', ['step' => $next], false));
    }

    public function hide(Request $request): RedirectResponse
    {
        $this->setup->hide(PanelWarnings::now());

        return new RedirectResponse(route('larena.env.admin.overview', [], false));
    }

    /** @return array<string, string> */
    public function states(PanelActor $actor): array
    {
        return ServerSetup::states($this->setup->marks(), $this->doneOnServer($actor));
    }

    /** @return array<string, ?string> each step's screen, where this panel has it */
    public function links(): array
    {
        $links = [];
        foreach (ServerSetup::STEPS as $step => $route) {
            $links[$step] = $this->router->has($route) ? route($route, [], false) : null;
        }

        return $links;
    }

    /** @return array<string, bool> what the server already shows done: a domain with a certificate, a channel, a level, a storage */
    private function doneOnServer(PanelActor $actor): array
    {
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        $alerts = $capabilities->allows('self:alerts') ? $this->extras->alerts($actor) : null;

        return [
            'address' => $capabilities->allows('panel:address') && $this->extras->panelAddress($actor)?->domainSsl === true,
            'alerts' => in_array($alerts?->channel['kind'] ?? null, ['webhook', 'telegram'], true),
            'protection' => $capabilities->allows('security:status') && in_array($this->extras->security($actor)?->level, ['enhanced', 'strict'], true),
            'backups' => $capabilities->allows('backup:storage-list') && ($this->extras->backupStorages($actor) ?? []) !== [],
        ];
    }
}
