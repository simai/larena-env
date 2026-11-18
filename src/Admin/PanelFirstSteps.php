<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Access\Runtime\AccessOperationAuthorizer;
use Larena\Admin\Contracts\AdminFirstStepsSource;
use Larena\Admin\FirstSteps\AdminFirstStep;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Controllers\ServerSetupController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Setup\ServerSetup;

/**
 * The panel's «Первые шаги» on the server's overview (owner decision dec-83b7d143b782, 2026-11-17): the first site first,
 * then the steps of the server's setup that the short first-run wizard left out, each done by what the server shows and
 * opening its own step of the setup alone. Only the whole server's administrator sees them.
 */
final readonly class PanelFirstSteps implements AdminFirstStepsSource
{
    /** The setup's steps offered here, in order; the sign-in's is the wizard's own and shows here as done or not. */
    private const array STEPS = ['login' => 5, 'address' => 30, 'alerts' => 40, 'backups' => 50, 'protection' => 60, 'updates' => 70];

    /**
     * @param array<string> $ownHosts the panel's own addresses: its own site is not «a first site»
     */
    public function __construct(
        private PanelActorResolver $actors,
        private ServerOverviewReader $overviews,
        private ServerSetupController $setup,
        private Translator $translator,
        private ?AccessOperationAuthorizer $authorizer = null,
        private array $ownHosts = [],
    ) {
    }

    public function firstSteps(Request $request, string $place): array
    {
        if ($place !== 'server' || $this->authorizer === null || !$this->authorizer->authorize($request, 'env.server.operate')->isAllowed()) {
            return [];
        }
        $actor = $request->attributes->get(ResolvePanelActor::ATTRIBUTE);
        if (!$actor instanceof PanelActor) {
            try {
                $actor = $this->actors->resolve($request);
            } catch (PanelRefused) {
                return [];
            }
        }
        if ($actor->owner !== null) {
            return [];
        }
        $overview = $this->overviews->read($actor)->overview?->withoutSites(array_values(array_unique([$request->getHost(), ...$this->ownHosts])));
        if ($overview === null) {
            return [];
        }
        $items = [];
        if ($overview->capabilities?->allows('site:add') === true) {
            $items[] = new AdminFirstStep('env.site', $this->text('first_steps.site.title'), $this->text('first_steps.site.text'), $overview->sites !== [],
                $this->text('first_steps.site.action'), route('larena.env.admin.sites.new', [], false), 10);
        }
        $states = $this->setup->states($actor, $request);
        foreach (self::STEPS as $step => $order) {
            $command = ServerSetupController::COMMANDS[$step] ?? null;
            if ($command !== null && $overview->capabilities?->allows($command) !== true) {
                continue;
            }
            $items[] = new AdminFirstStep('env.'.$step, $this->text('first_steps.'.$step.'.title'), $this->text('first_steps.'.$step.'.text'),
                ($states[$step] ?? ServerSetup::TODO) === ServerSetup::DONE, $this->text('first_steps.'.$step.'.action'),
                route('larena.env.admin.overview.setup', ['step' => $step], false), $order);
        }

        return $items;
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
