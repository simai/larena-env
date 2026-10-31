<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Env\Admin\ServerSitesPage;
use Larena\Env\Server\ServerOverviewReader;

/** The panel's first screen: the server's status and its sites. It only reads, through the gateway. */
final readonly class ServerAdminController
{
    public function __construct(
        private ServerOverviewReader $reader,
        private Factory $views,
        private Translator $translator,
        private AdminDataviewPagePresenter $dataviews,
        private \Illuminate\Contracts\Config\Repository $config,
    ) {
    }

    public function index(Request $request): mixed
    {
        $result = $this->reader->read((string) $request->attributes->get('larena_access_actor', ''));
        $text = fn (string $key): string => (string) $this->translator->get('larena-env::admin.'.$key);
        // The gateway lists every site of the server, the panel's own among it; it is reached by the address it answers on.
        $ownHost = parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);
        $overview = $result->overview?->withoutSites(is_string($ownHost) ? [$request->getHost(), $ownHost] : [$request->getHost()]);

        return $this->views->make('larena-env::admin.index', [
            'source' => $result->source,
            'overview' => $overview,
            'refused' => $result->refused,
            'reason' => $result->reason,
            'reasonText' => $result->reason === null ? null : $this->reasonText($result->reason),
            'sitesView' => $overview === null ? null : $this->dataviews->present(
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
