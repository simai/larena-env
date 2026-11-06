<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Env\Admin\ServerSitesPage;
use Larena\Env\History\MetricSamples;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Warnings\PanelWarnings;

/**
 * The panel's first screen, its sites, and the server's overview: its status and services, for whoever may read the
 * whole server. Both only read, through the gateway, as the person and the owner they act as.
 */
final readonly class ServerAdminController
{
    public function __construct(
        private ServerOverviewReader $reader,
        private Factory $views,
        private Translator $translator,
        private AdminDataviewPagePresenter $dataviews,
        private \Illuminate\Contracts\Config\Repository $config,
        private ?\Larena\Access\Runtime\AccessOperationAuthorizer $authorizer = null,
        private ?\Larena\Env\Server\SiteExtrasReader $extras = null,
        private ?MetricSamples $samples = null,
        private ?\Larena\Env\Setup\ServerSetup $setup = null,
        private ?ServerSetupController $setupSteps = null,
    ) {
    }

    public function index(Request $request): mixed
    {
        // The first sign-in of the server's administrator goes through the server's setup once (ENV 2026-11-06).
        if ($this->canSetUp($request) && $this->setup?->offerOnce(PanelWarnings::now()) === true) {
            return new \Illuminate\Http\RedirectResponse(route('larena.env.admin.setup', [], false));
        }

        return $this->page($request, 'sites');
    }

    public function overview(Request $request): mixed
    {
        return $this->page($request, 'overview');
    }

    private function page(Request $request, string $section): mixed
    {
        $actor = \Larena\Env\Http\Middleware\ResolvePanelActor::of($request);
        $result = $this->reader->read($actor);
        /** @param array<string, string> $replace */
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);
        // The gateway lists every site of the server, the panel's own among it; it is reached by the address it answers on.
        $ownHost = parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);
        $overview = $result->overview?->withoutSites(is_string($ownHost) ? [$request->getHost(), $ownHost] : [$request->getHost()]);

        // The server's load now (Panel plan 3.3), when the server's gateway reports it: the whole server's, so only the
        // system level reads it, and it also tells whether a disk is running out.
        $metrics = $section === 'overview' && $actor->owner === null && $overview?->capabilities?->allows('self:metrics') === true ? $this->extras?->metrics($actor) : null;
        $now = PanelWarnings::now();

        $history = $section === 'overview' && $actor->owner === null ? $this->history($actor, $overview, $now) : null;
        // One chart per measure for the period chosen above them (owner 2026-11-06), where the server keeps its history.
        $charts = null;
        if ($section === 'overview' && $actor->owner === null && $overview?->capabilities?->allows('self:metrics-history') === true && $this->extras !== null) {
            $period = \Larena\Env\History\HistoryCharts::period($request->query('period'));
            [, $source, $before] = \Larena\Env\History\HistoryCharts::PERIODS[$period];
            $data = $this->extras->metricsHistoryData($actor, $source);
            // A server before 1.50.0 keeps no hour of its own, and a fresh one has none yet: its day's last points then.
            if ($source !== $before && !\Larena\Env\History\HistoryCharts::hasPoints($data)) {
                $data = $this->extras->metricsHistoryData($actor, $before);
            }
            $charts = $data === null ? null : \Larena\Env\History\HistoryCharts::fromServer($period, \Larena\Env\History\HistoryCharts::scale($request->query('scale')), $data,
                static fn (string $key, array $replace = []): string => $text($key, $replace));
        }

        return $this->views->make('larena-env::admin.index', [
            'section' => $section,
            'canCreate' => $this->authorizer?->authorize($request, 'env.sites.operate')->isAllowed() ?? false,
            'metrics' => $metrics,
            // The services as «Службы» lists them (service:list), so both screens say the same; else the description's.
            'services' => $section === 'overview' && $overview?->capabilities?->allows('service:list') === true
                ? ($this->extras?->services($actor) ?? $overview->services) : ($overview->services ?? []),
            // What needs attention is in the admin's alert bar on every page (owner decision 2026-11-05), not a block here.
            // The load over time (Panel plan 4.6): the server's own history where it keeps one, else the panel's samples.
            'history' => $history,
            'charts' => $charts,
            // What is left of the server's setup, until done or hidden.
            'setupLeft' => $section === 'overview' ? $this->setupLeft($request, $actor) : [],
            'setupLinks' => $this->setupSteps?->links() ?? [],
            // A safe update waiting (simai-env 1.32.0): only a hint here, the update screen says what it changes.
            'updates' => $section === 'overview' && $actor->owner === null ? $this->updates($actor, $overview) : [],
            'historyFromServer' => $overview?->capabilities?->allows('self:metrics-history') === true,
            'source' => $result->source,
            'overview' => $overview,
            'refused' => $result->refused,
            'reason' => $result->reason,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'sitesView' => $overview === null || $section !== 'sites' ? null : $this->dataviews->present(
                ServerSitesPage::of($overview, $text),
                AdminProductAssetManifest::dataviewActivation(),
            ),
        ]);
    }

    private function canSetUp(Request $request): bool
    {
        return $this->setup !== null && $this->setupSteps !== null && \Larena\Env\Http\Middleware\ResolvePanelActor::of($request)->owner === null
            && ($this->authorizer?->authorize($request, 'env.server.operate')->isAllowed() ?? false);
    }

    /** @return list<string> the setup's steps not done yet, in order */
    private function setupLeft(Request $request, \Larena\Env\Gateway\PanelActor $actor): array
    {
        if (!$this->canSetUp($request) || $this->setup?->hidden() !== false || $this->setupSteps === null) {
            return [];
        }

        return array_keys(array_filter($this->setupSteps->states($actor), static fn (string $state): bool => $state !== \Larena\Env\Setup\ServerSetup::DONE));
    }

    /** @return list<array{target: string, available: ?string}> the targets the server has an update for */
    private function updates(\Larena\Env\Gateway\PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview): array
    {
        if ($overview?->capabilities?->allows('self:update-plan') !== true || $this->extras === null) {
            return [];
        }
        $updates = [];
        foreach (\Larena\Env\Update\UpdatePlan::TARGETS as $target) {
            $plan = $this->extras->updatePlan($actor, $target);
            $plan = $target === 'panel' ? $plan?->beside(UpdateController::panelBuild()) : $plan;
            if ($plan?->updateAvailable === true) {
                $updates[] = ['target' => $target, 'available' => $plan->available];
            }
        }

        return $updates;
    }

    /** @return array<string, \Larena\Env\History\MetricHistory>|null */
    private function history(\Larena\Env\Gateway\PanelActor $actor, ?\Larena\Env\Server\ServerOverview $overview, \DateTimeImmutable $now): ?array
    {
        if ($overview?->capabilities?->allows('self:metrics-history') === true && $this->extras !== null) {
            $periods = [];
            foreach (['day'] as $period) {
                $history = $this->extras->metricsHistory($actor, $period);
                if ($history !== null) {
                    $periods[$period] = $history;
                }
            }
            if ($periods !== []) {
                return $periods;
            }
        }

        return $this->samples === null ? null : ['day' => $this->samples->history('day', $now), 'week' => $this->samples->history('week', $now)];
    }

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
