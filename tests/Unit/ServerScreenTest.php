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
$text = static function (string $key, array $replace = []) use ($lines): string {
    $value = data_get($lines['admin'], $key);
    env_assert(is_string($value), "No English text for {$key}.");
    foreach ($replace as $name => $with) {
        $value = str_replace(':'.$name, $with, $value);
    }

    return $value;
};

// The translation helpers of the framework, for this package's development install.
/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $text;
    $line = $text(str_replace('larena-env::admin.', '', $key));
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, $value, $line);
    }

    return $line;
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

$overview = (new ServerOverviewReader(new RecordedAnswersGateway(env_recorded_path())))->read(new \Larena\Env\Gateway\PanelActor('admin@example.com'))->overview;
env_assert($overview instanceof ServerOverview, 'The recording reads.');

$page = ServerSitesPage::of($overview, $text);
env_assert($page->total === 4 && $page->rows !== null && $page->portUrl === null, 'The sites are rows in place.');
env_assert(count($overview->withoutSites(['DEMO-php.test'])->sites) === 3 && count($overview->withoutSites([])->sites) === 4, 'The panel leaves its own site out.');
env_assert($page->instanceId === 'env-server-sites' && $page->selectable === false && $page->rowWrites === [], 'The list writes nothing.');
env_assert($page->rowLinks === ['view' => '/admin/server/sites/__ID__'], 'A row opens the site\'s own screen.');
$byDomain = array_column($page->rows, null, 'id');
env_assert(array_column($page->columns, 'key') === ['domain', 'answers', 'owner', 'profile', 'php', 'certificate', 'backups', 'disk'], 'Whether it answers, whose, what it runs, its certificate, its backups and its disk.');
env_assert($byDomain['demo-owned.test']['answers'] === $text('sites.answers_code', ['code' => '200']) && $byDomain['demo-owned.test']['disk'] === '1.0 MB', 'It answers 200 and takes 1 MB.');
env_assert($byDomain['demo-owned.test']['owner'] === 'demo' && $byDomain['demo-php.test']['owner'] === '—', 'The owner, or none.');
env_assert($byDomain['demo-redirect.test']['php'] === '—' && $byDomain['demo-php.test']['certificate'] === $text('sites.certificate_none'), 'Empty values in words.');
env_assert($byDomain['demo-owned.test']['backups'] !== $text('sites.backups_unscheduled'), 'A site with its daily backup and none yet does not say it has none scheduled.');
env_assert($byDomain['demo-php.test']['backups'] === $text('sites.backups_last', ['time' => '2026-01-01T00:00:00']).' · '.$text('sites.backups_scheduled'), 'Its newest backup is named, and its daily one.');
$soon = new ServerOverview('now', '1', 'ubuntu', '24.04', [], [], [new \Larena\Env\Server\SiteSummary('a.test', 'static', 'none', 'letsencrypt', null, null, null, 9, true)]);
$soonRow = ServerSitesPage::of($soon, $text)->rows[0];
env_assert($soonRow['certificate'] === $text('sites.certificate_ends', ['days' => '9']) && $soonRow['backups'] === $text('sites.backups_scheduled'), 'A certificate that ends soon is said so.');

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
$state = static fn (array $data): string => $factory->make('larena-env::admin.index', $data + ['section' => 'sites', 'source' => 'ssh', 'overview' => null, 'refused' => false, 'reason' => null, 'reasonText' => null, 'sitesView' => null, 'canCreate' => false, 'metrics' => null])->render();

$ok = $state(['section' => 'overview', 'source' => 'recorded', 'overview' => $overview]);
env_assert(str_contains($ok, 'data-env-state="ok"') && str_contains($ok, 'data-env-source="recorded"'), 'The recorded data is marked as recorded: '.$ok);
env_assert(str_contains($ok, 'ubuntu 24.04') && str_contains($ok, 'data-env-service="nginx"') && !str_contains($ok, 'stub-dataview'), 'The overview draws the status, not the sites.');
$sites = $state(['source' => 'recorded', 'overview' => $overview, 'sitesView' => ['stub' => true]]);
env_assert(str_contains($sites, 'stub-dataview env-server-sites larena/env 4') && !str_contains($sites, 'data-env-service'), 'The sites screen draws the sites, not the server\'s status.');

$live = $state(['overview' => $overview, 'sitesView' => ['stub' => true]]);
env_assert(!str_contains($live, 'data-env-create-site') && str_contains($state(['overview' => $overview, 'sitesView' => ['stub' => true], 'canCreate' => true]), 'data-env-create-site'), 'A new site is offered to whoever may make one.');

// The load now, when the server reports it (Panel plan 3.3): a nearly full disk is said first.
$metrics = \Larena\Env\Server\ServerMetrics::fromData(['cpu' => ['cores' => 8, 'load1' => 0.5, 'load5' => 0.4, 'load15' => 0.3],
    'memory' => ['total_bytes' => 12_000_000_000, 'used_bytes' => 4_000_000_000], 'swap' => ['total_bytes' => 0, 'used_bytes' => 0],
    'disk' => [['mount' => '/', 'total_bytes' => 100_000_000_000, 'used_bytes' => 98_000_000_000], ['mount' => 'bad']], 'checked_at' => '2026-11-01T10:00:00Z']);
env_assert($metrics->cores === 8 && $metrics->load === [0.5, 0.4, 0.3] && count($metrics->disks) === 1, 'The load reads, a malformed disk is left out.');
$warnings = \Larena\Env\Warnings\PanelWarnings::of($overview, $metrics, new DateTimeImmutable('2026-11-01T12:00:00Z'));
$loaded = $state(['section' => 'overview', 'overview' => $overview, 'metrics' => $metrics, 'warnings' => $warnings]);
env_assert(str_contains($loaded, 'data-env-metrics') && str_contains($loaded, 'data-env-warning="disk_full"') && str_contains($loaded, '98%')
    && strpos($loaded, 'data-env-warnings') < strpos($loaded, 'data-env-metrics') && str_contains($loaded, 'role="alert"'), 'A disk at 98% is said first, as an error.');
env_assert(str_contains($loaded, 'data-env-warning-site="demo-php.test"') && str_contains($loaded, $text('warnings.backup_stale', ['domain' => 'demo-php.test', 'time' => '2026-01-01 00:00 UTC'])), 'An old backup is said in words.');
$listed = $state(['overview' => $overview, 'sitesView' => ['stub' => true], 'warnings' => $warnings]);
env_assert(strpos($listed, 'data-env-warnings') < strpos($listed, 'stub-dataview'), 'The sites screen says what needs attention above the list.');
env_assert(str_contains($state(['overview' => $overview, 'sitesView' => ['stub' => true], 'warnings' => []]), 'data-env-warnings-none'), 'Nothing to say is said too.');
env_assert(!str_contains($ok, 'data-env-warnings'), 'Warnings not read, none drawn.');

// The load history (Panel plan 4.6): a line per measure, a legend in words, nothing drawn without measurements.
$history = ['day' => new \Larena\Env\History\MetricHistory('day', ['load' => [[0.1, 20.0], [0.9, 60.0]], 'disk:/' => [[0.5, 40.0]]], 2, '2026-11-01 01:00:00', '2026-11-01 11:00:00'),
    'week' => new \Larena\Env\History\MetricHistory('week', [], 0, null, null)];
$drawn = $state(['section' => 'overview', 'overview' => $overview, 'history' => $history]);
env_assert(str_contains($drawn, 'data-env-history-chart="day"') && str_contains($drawn, '<polyline') && str_contains($drawn, '<circle')
    && str_contains($drawn, 'data-env-history-line="disk:/"') && str_contains($drawn, $text('history.disk', ['mount' => '/'])) && str_contains($drawn, 'data-env-history-none="week"'), 'The history is drawn: '.$drawn);
env_assert(!str_contains($loaded, $text('metrics.swap')), 'No swap, no swap line.');
env_assert(!str_contains($ok, 'data-env-metrics'), 'Without the server\'s numbers, no load section.');
env_assert(!str_contains($live, 'data-env-source'), 'A live server has no such notice.');

$refused = $state(['refused' => true, 'reason' => 'owner_scope', 'reasonText' => 'Another owner.']);
env_assert(str_contains($refused, 'data-env-state="refused"') && str_contains($refused, 'data-env-reason="owner_scope"') && str_contains($refused, 'Another owner.'), 'A refusal shows its reason.');
env_assert(!str_contains($refused, 'stub-dataview'), 'A refusal shows no list.');

$failed = $state(['reason' => 'gateway_not_configured', 'reasonText' => 'Not configured.']);
env_assert(str_contains($failed, 'data-env-state="failed"') && str_contains($failed, 'data is unavailable') && str_contains($failed, 'Not configured.'), 'A failure shows its reason: '.$failed);

$escaped = $state(['section' => 'overview', 'overview' => $hostile]);
env_assert(!str_contains($escaped, '<img') && str_contains($escaped, '&lt;img'), 'What the server says is escaped.');

$source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/index.blade.php');
env_assert(preg_match('/\{!!/', $source) === 0, 'The view prints nothing raw.');

echo "ServerScreenTest passed.\n";
