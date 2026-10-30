<?php

declare(strict_types=1);

// The screen's view in its three states on a stub of the admin's layout, and the sites through the admin's real page
// description. The real admin shell is proved in an assembly.

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Env\Admin\ServerSitesPage;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Ui\Facades\Smart;
use Larena\Ui\Frontend\FrontendRuntimeLock;
use Larena\Ui\Runtime\SmartManager;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$lines = ['admin' => $lines];
$text = static function (string $key) use ($lines): string {
    $value = data_get($lines['admin'], $key);
    env_assert(is_string($value), "No English text for {$key}.");

    return $value;
};

// The translation helpers of the framework, for this package's development install.
function __(string $key): string
{
    global $text;

    return $text(str_replace('larena-env::admin.', '', $key));
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server';
}

// Both languages have the same keys.
$russian = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
$flatten = static fn (array $tree): array => array_keys(Illuminate\Support\Arr::dot($tree));
env_assert($flatten($lines['admin']) === $flatten($russian), 'en and ru must have the same keys.');

$overview = (new ServerOverviewReader(new RecordedAnswersGateway(env_recorded_path())))->read('admin@example.com')->overview;
env_assert($overview instanceof ServerOverview, 'The recording reads.');

$page = ServerSitesPage::of($overview, $text);
env_assert($page->total === 4 && $page->rows !== null && $page->portUrl === null, 'The sites are rows in place.');
env_assert($page->instanceId === 'env-server-sites' && $page->selectable === false && $page->rowWrites === [], 'The list writes nothing.');
env_assert($page->rowLinks === ['view' => '/admin/server/sites/__ID__'], 'A row opens the site\'s own screen.');
$byDomain = array_column($page->rows, null, 'id');
env_assert($byDomain['demo-php.test']['database'] === 'mysql' && $byDomain['demo-owned.test']['owner'] === 'demo', 'The cells.');
env_assert($byDomain['demo-redirect.test']['php'] === '—' && $byDomain['demo-static.test']['release'] === '20261030173846', 'Empty values and the release.');

// The admin's real presenter draws the list, as the controller asks it to.
$manager = SmartManager::withDefaults();
Smart::resolveUsing(static fn (): SmartManager => $manager);
$presented = (new AdminDataviewPagePresenter(null, static fn (string $key): mixed => null))->present($page, [
    'schema' => 'larena.core_assets.activation_contract',
    'status' => 'ready',
    'activation_owner' => 'larena/core:core.assets',
    'activation_mode' => 'verified_immutable_bundle',
    'physical_publication_ready' => true,
    'writes_database' => false,
    'copies_to_root' => false,
    'uses_hardcoded_cdn' => false,
    'runtime_pair' => FrontendRuntimeLock::bundled()->pairId(),
    'renderable_tags' => [],
]);
env_assert(str_contains($presented['composite_html'], 'demo-php.test') && str_contains($presented['composite_html'], 'env-server-sites'), 'The presenter draws the rows.');
env_assert($presented['page_assembly']['page_id'] === 'page.admin.env-server', 'The page names its assembly.');

// The view on stubs.
$files = new Filesystem();
$compiled = env_temp_directory();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, $compiled), $files));
$finder = new FileViewFinder($files, []);
$factory = new Factory($resolver, $finder, new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');

$hostile = new ServerOverview('now', '1.25.0', '<img src=x onerror=alert(1)>', '24.04', [], ['8.3'], []);
$state = static fn (array $data): string => $factory->make('larena-env::admin.index', $data + ['source' => 'ssh', 'overview' => null, 'refused' => false, 'reason' => null, 'reasonText' => null, 'sitesView' => null])->render();

$ok = $state(['source' => 'recorded', 'overview' => $overview, 'sitesView' => ['stub' => true]]);
env_assert(str_contains($ok, 'data-env-state="ok"') && str_contains($ok, 'data-env-source="recorded"'), 'The recorded data is marked as recorded: '.$ok);
env_assert(str_contains($ok, 'ubuntu 24.04') && str_contains($ok, 'data-env-service="nginx"') && str_contains($ok, 'stub-dataview env-server-sites larena/env 4'), 'Status and the sites list are drawn.');

$live = $state(['overview' => $overview, 'sitesView' => ['stub' => true]]);
env_assert(!str_contains($live, 'data-env-source'), 'A live server has no such notice.');

$refused = $state(['refused' => true, 'reason' => 'owner_scope', 'reasonText' => 'Another owner.']);
env_assert(str_contains($refused, 'data-env-state="refused"') && str_contains($refused, 'data-env-reason="owner_scope"') && str_contains($refused, 'Another owner.'), 'A refusal shows its reason.');
env_assert(!str_contains($refused, 'stub-dataview'), 'A refusal shows no list.');

$failed = $state(['reason' => 'gateway_not_configured', 'reasonText' => 'Not configured.']);
env_assert(str_contains($failed, 'data-env-state="failed"') && str_contains($failed, 'data is unavailable') && str_contains($failed, 'Not configured.'), 'A failure shows its reason: '.$failed);

$escaped = $state(['overview' => $hostile, 'sitesView' => ['stub' => true]]);
env_assert(!str_contains($escaped, '<img') && str_contains($escaped, '&lt;img'), 'What the server says is escaped.');

$source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/index.blade.php');
env_assert(preg_match('/\{!!/', $source) === 0, 'The view prints nothing raw.');

echo "ServerScreenTest passed.\n";
