<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Env\Admin\ServerDatabasesPage;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Server\SiteExtrasReader;

/**
 * «Базы данных» (simai-env 1.59.0): the databases of the sites a person reaches, from the server's own list (db:list);
 * an owner sees their own sites' only. A row opens the site's card on «База данных».
 */
final readonly class DatabasesController
{
    public function __construct(
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
        private AdminDataviewPagePresenter $dataviews,
    ) {
    }

    public function index(Request $request): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $databases = $this->extras->allDatabases($actor);
        $text = fn (string $key, array $replace = []): string => (string) $this->translator->get('larena-env::admin.'.$key, $replace);

        return $this->views->make('larena-env::admin.databases', [
            'databases' => $databases,
            'view' => $databases === null ? null : $this->dataviews->present(ServerDatabasesPage::of($databases, $text), AdminProductAssetManifest::dataviewActivation()),
            'matched' => $databases === null ? 0 : count(array_filter($databases, static fn ($database): bool => $database->site !== null && $database->site !== '')),
        ]);
    }
}
