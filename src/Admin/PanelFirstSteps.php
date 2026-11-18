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
 * The panel's «Советы» on the server's overview (owner decisions dec-83b7d143b782 and dec-45381ada2c3d, 2026-11-17): the
 * first site first, then the steps of the server's setup that the short first-run wizard left out, each done by what
 * the server shows and opening its own step of the setup alone, then the advised fixes of the security check (formerly
 * «Рекомендуемые действия», PX-38) — a fix planned and confirmed by one click, or its screen. What is urgent is the alert
 * bar's, not here. Only the whole server's administrator sees them.
 */
final readonly class PanelFirstSteps implements AdminFirstStepsSource
{
    /** The setup's steps offered here, in order; the sign-in's is the wizard's own and shows here as done or not. */
    private const array STEPS = ['login' => 5, 'address' => 30, 'alerts' => 40, 'backups' => 50, 'protection' => 60, 'updates' => 70];

    /** The request attribute where the overview leaves its recommended actions (RecommendedActions::of) for the memo. */
    public const string ADVICE = 'larena_env_advice';

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
        array_push($items, ...self::advice($request->attributes->get(self::ADVICE)));

        return $items;
    }

    /**
     * The security check's advised and done fixes as advice: not the urgent ones (the bar's), not the waiting update
     * (the bar's too), not the setup steps (above).
     *
     * @return list<AdminFirstStep>
     */
    public static function advice(mixed $actions): array
    {
        $items = [];
        $order = 100;
        foreach (is_array($actions) ? $actions : [] as $action) {
            $key = is_array($action) && is_string($action['key'] ?? null) ? $action['key'] : '';
            $state = $action['state'] ?? null;
            if (!str_starts_with($key, 'security:') || !in_array($state, [\Larena\Env\Admin\RecommendedActions::TODO, \Larena\Env\Admin\RecommendedActions::DONE], true)) {
                continue;
            }
            $id = substr('env.'.strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', str_replace(':', '.', $key))), 0, 64);
            $do = is_array($action['action'] ?? null) ? $action['action'] : null;
            try {
                $items[] = new AdminFirstStep($id, (string) $action['title'], (string) ($action['line'] ?? ''), $state === \Larena\Env\Admin\RecommendedActions::DONE,
                    (string) ($do['label'] ?? ''), is_string($do['url'] ?? null) ? $do['url'] : '', $order++,
                    ($do['kind'] ?? null) === 'form' && is_array($do['fields'] ?? null) ? array_map('strval', $do['fields']) : []);
            } catch (\InvalidArgumentException) {
                // An item the memo cannot say (an address outside the panel) stays on «Безопасность».
            }
        }

        return $items;
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
