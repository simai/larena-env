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
    ) {
    }

    public function index(Request $request): mixed
    {
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
        $metrics = $actor->owner === null && $overview?->capabilities?->allows('self:metrics') === true ? $this->extras?->metrics($actor) : null;
        $now = PanelWarnings::now();

        return $this->views->make('larena-env::admin.index', [
            'section' => $section,
            'canCreate' => $this->authorizer?->authorize($request, 'env.sites.operate')->isAllowed() ?? false,
            'metrics' => $section === 'overview' ? $metrics : null,
            // What needs attention (Panel plan 4.4): the person's own sites, and the disks for the whole server.
            'warnings' => $overview === null ? null : PanelWarnings::of($overview, $metrics, $now),
            // The load over the last day and week (Panel plan 4.6), as the panel's check measured it.
            'history' => $section === 'overview' && $actor->owner === null && $this->samples !== null
                ? ['day' => $this->samples->history('day', $now), 'week' => $this->samples->history('week', $now)] : null,
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

    private function reasonText(string $reason): string
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]);
    }
}
