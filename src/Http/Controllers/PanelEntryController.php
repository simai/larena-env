<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larena\Core\FirstRun\FirstRunCoordinator;
use Larena\Env\Panel\SetupLink;

/** The panel's address: the setup link while there is no administrator, otherwise on to the server screen. */
final readonly class PanelEntryController
{
    public function __construct(private SetupLink $link, private FirstRunCoordinator $firstRun, private Translator $translator)
    {
    }

    public function __invoke(Request $request): RedirectResponse|Response
    {
        $candidate = $request->query('setup');
        if (!is_string($candidate)) {
            return new RedirectResponse('/admin/server');
        }
        if (!$this->firstRun->availability()->isAvailable()) {
            // The first administrator exists: whatever the link was, it has done its work.
            $this->link->consume();

            return new RedirectResponse('/admin/server');
        }
        if (!$this->link->accepts($candidate)) {
            return new Response((string) $this->translator->get('larena-env::panel.setup_link_invalid'), 403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Referrer-Policy' => 'no-referrer']);
        }
        $request->session()->regenerate();
        $request->session()->put(SetupLink::SESSION_KEY, $this->link->digest());

        return new RedirectResponse('/admin/setup');
    }
}
