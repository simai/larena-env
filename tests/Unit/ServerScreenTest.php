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

// A server's alert is acknowledged by a form, as on the site card.
function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
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
env_assert(array_column($page->columns, 'key') === ['domain', 'answers', 'profile', 'certificate', 'backups'], 'Whether it works, what it runs, its certificate and its backups.');
env_assert($byDomain['demo-owned.test']['answers'] === 'works' && !isset($byDomain['demo-owned.test']['disk']), 'It works; the disk is on its own screen.');
$columns = (string) json_encode(array_column($page->columns, null, 'key'), JSON_UNESCAPED_UNICODE);
env_assert(str_contains($columns, '"answers":{"key":"answers","label":"'.$text('sites.answers').'","type":"choice","options":[{"value":"works"')
    && str_contains($columns, '{"value":"down","label":"'.$text('sites.state.down').'","color":"danger"}')
    && str_contains($columns, '"type":"choice","options":[{"value":"generic"') && str_contains($columns, '{"value":"redirect","label":"'.$text('sites.kinds.redirect').'"}'), 'The state and the kind are coloured choices the list filters by: '.$columns);
env_assert($byDomain['demo-php.test']['certificate'] === $text('sites.certificate_none'), 'Empty values in words.');
env_assert($byDomain['demo-owned.test']['backups'] !== $text('sites.backups_unscheduled'), 'A site with its daily backup and none yet does not say it has none scheduled.');
env_assert($byDomain['demo-php.test']['backups'] === \Larena\Env\Server\Moment::short('2026-01-01T00:00:00Z'), 'Its newest backup is named.');
env_assert(\Larena\Env\Server\Moment::short('2026-01-01T00:00:00Z', 2026) === '01.01 00:00 UTC' && \Larena\Env\Server\Moment::short('2025-01-01T00:00:00Z', 2026) === '01.01.2025 00:00 UTC', 'A backup this year without its year.');
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
$kinds = array_map(static fn ($warning): string => $warning->kind.':'.($warning->domain ?? ''), $warnings);
env_assert(in_array('disk_full:', $kinds, true) && $warnings[0]->kind === 'disk_full' && $warnings[0]->severity === 'error' && in_array('backup_stale:demo-php.test', $kinds, true), 'A disk at 98% is said first, as an error; an old backup is said too.');
// What needs attention is in the admin's alert bar on every page (owner decision 2026-11-05): the screens draw no block of it.
$left = $state(['section' => 'overview', 'overview' => $overview, 'setupLeft' => ['alerts', 'backups']]);
env_assert(str_contains($left, 'data-env-setup-left') && str_contains($left, 'data-env-setup-left-step="alerts"') && str_contains($left, 'data-env-setup-hide')
    && !str_contains($ok, 'data-env-setup-left'), 'What is left of the setup stands on the overview until done or hidden.');
$loaded = $state(['section' => 'overview', 'overview' => $overview, 'metrics' => $metrics, 'warnings' => $warnings]);
env_assert(str_contains($loaded, 'data-env-metrics') && str_contains($loaded, '98%') && !str_contains($loaded, 'data-env-warnings')
    && !str_contains($state(['overview' => $overview, 'sitesView' => ['stub' => true], 'warnings' => $warnings]), 'data-env-warnings'), 'The overview and the sites draw no warnings block.');

// The load history (Panel plan 4.6): a line per measure, a legend in words, nothing drawn without measurements.
$history = ['day' => new \Larena\Env\History\MetricHistory('day', ['load' => [[0.1, 20.0], [0.9, 60.0]], 'disk:/' => [[0.5, 40.0]]], 2, '2026-11-01 01:00:00', '2026-11-01 11:00:00'),
    'week' => new \Larena\Env\History\MetricHistory('week', [], 0, null, null)];
$drawn = $state(['section' => 'overview', 'overview' => $overview, 'history' => $history]);
env_assert(str_contains($drawn, 'data-env-history-chart="day"') && str_contains($drawn, '<polyline') && str_contains($drawn, '<circle')
    && str_contains($drawn, 'data-env-history-line="disk:/"') && str_contains($drawn, $text('history.disk', ['mount' => '/'])) && str_contains($drawn, 'data-env-history-none="week"'), 'The history is drawn: '.$drawn);
env_assert(!str_contains($drawn, 'data-env-history-from-server') && str_contains($state(['section' => 'overview', 'overview' => $overview, 'history' => $history,
    'historyFromServer' => true]), 'data-env-history-from-server'), 'The server\'s own history says so.');

// One chart per measure for the chosen period (owner 2026-11-06): the processor in percent of its cores, the memory and the
// disk in gigabytes of the whole, a scale in units and the times along the bottom; an hour is the day's last points.
$day = ['schema' => 'simai-metrics-history/1', 'period' => 'day', 'step_seconds' => 300, 'points' => [
    ['at' => '2026-11-01T10:00:00Z', 'load1' => 1.0, 'cpu_cores' => 4, 'memory_used_bytes' => 2 * 1_073_741_824, 'memory_total_bytes' => 8 * 1_073_741_824,
        'disk' => [['mount' => '/', 'used_bytes' => 10 * 1_073_741_824, 'total_bytes' => 40 * 1_073_741_824]]],
    ['at' => '2026-11-01T11:30:00Z', 'load1' => 2.0, 'cpu_cores' => 4, 'memory_used_bytes' => 3 * 1_073_741_824, 'memory_total_bytes' => 8 * 1_073_741_824,
        'disk' => [['mount' => '/', 'used_bytes' => 11 * 1_073_741_824, 'total_bytes' => 40 * 1_073_741_824]]],
    ['at' => '2026-11-01T12:00:00Z', 'load1' => 3.0, 'cpu_cores' => 4, 'memory_used_bytes' => 4 * 1_073_741_824, 'memory_total_bytes' => 8 * 1_073_741_824,
        'disk' => [['mount' => '/', 'used_bytes' => 12 * 1_073_741_824, 'total_bytes' => 40 * 1_073_741_824]]],
]];
$words = static fn (string $key, array $replace = []): string => $text($key, $replace);
$hour = \Larena\Env\History\HistoryCharts::fromServer('hour', 'fixed', $day, $words);
env_assert($hour->count === 2 && array_column($hour->charts, 'key') === ['load', 'memory', 'disk:/'] && $hour->charts[0]['ticks'] === ['100 %', '75 %', '50 %', '25 %', '0 %']
    && $hour->charts[1]['capacity'] === '8,0 '.$text('history.gb') && $hour->charts[1]['last'] === '4,0 '.$text('history.gb') && $hour->charts[0]['last'] === '75 %'
    && $hour->charts[0]['times'][0] === '11:00' && $hour->charts[0]['times'][5] === '12:00' && $hour->charts[2]['ticks'][0] === '40 '.$text('history.gb'),
    'The hour\'s charts: '.json_encode($hour->charts, JSON_UNESCAPED_UNICODE));
$auto = \Larena\Env\History\HistoryCharts::fromServer('day', 'auto', $day, $words);
env_assert($auto->count === 3 && $auto->charts[0]['ticks'][0] === '100 %' && $auto->charts[2]['ticks'][0] === '20 '.$text('history.gb')
    && \Larena\Env\History\HistoryCharts::period('year') === 'day' && \Larena\Env\History\HistoryCharts::scale('x') === 'fixed', 'The scale by the values: '.json_encode($auto->charts[2]['ticks']));
// simai-env 1.50.0: the processor's busy share, the network in and out and the disk's reading and writing a second, with
// the link and the disk named in the header; the first point's rates are null (fixture set 26).
$rates = ['schema' => 'simai-metrics-history/1', 'period' => 'hour', 'step_seconds' => 60,
    'host' => ['net_interface' => 'eth0', 'net_link_bits_per_s' => 200_000_000, 'disk_device' => 'nvme0n1', 'disk_kind' => 'nvme'], 'points' => [
    ['at' => '2026-11-01T11:59:00Z', 'load1' => 3.0, 'cpu_cores' => 4, 'cpu_percent' => null, 'net_rx_bytes_per_s' => null, 'net_tx_bytes_per_s' => null,
        'disk_read_bytes_per_s' => null, 'disk_write_bytes_per_s' => null],
    ['at' => '2026-11-01T12:00:00Z', 'load1' => 3.0, 'cpu_cores' => 4, 'cpu_percent' => 12.5, 'net_rx_bytes_per_s' => 125000, 'net_tx_bytes_per_s' => 2500000,
        'disk_read_bytes_per_s' => 4096, 'disk_write_bytes_per_s' => 1048576],
]];
$fast = \Larena\Env\History\HistoryCharts::fromServer('hour', 'fixed', $rates, $words);
$byKey = array_column($fast->charts, null, 'key');
env_assert(array_keys($byKey) === ['load', 'network', 'io'] && $byKey['load']['last'] === '13 %' && count($byKey['load']['hovers']) === 1
    && $byKey['network']['ticks'][0] === '200 '.$text('history.mbit') && $byKey['network']['capacity'] === 'eth0 '.$text('history.link', ['speed' => '200 '.$text('history.mbit')])
    && array_column($byKey['network']['series'], 'key') === ['in', 'out'] && $byKey['network']['last'] === $text('history.in').' 1,0 '.$text('history.mbit').' · '.$text('history.out').' 20 '.$text('history.mbit')
    && $byKey['io']['unit'] === $text('history.mbs') && $byKey['io']['capacity'] === 'NVMe nvme0n1' && $byKey['io']['ticks'][0] === '2,0 '.$text('history.mbs')
    && str_contains($byKey['io']['hovers'][0]['label'], $text('history.read').' < 0,1 '.$text('history.mbs')),
    'The rates of 1.50.0: '.json_encode($fast->charts, JSON_UNESCAPED_UNICODE));
// On its own scale a quiet network is drawn in kilobits, whatever the link; the ticks keep the decimals their step needs.
$quiet = $rates;
$quiet['points'][1]['net_rx_bytes_per_s'] = 1500;
$quiet['points'][1]['net_tx_bytes_per_s'] = 2000;
$quietNet = array_column(\Larena\Env\History\HistoryCharts::fromServer('hour', 'auto', $quiet, $words)->charts, null, 'key')['network'];
env_assert($quietNet['unit'] === $text('history.kbit') && $quietNet['ticks'] === array_map(static fn (string $tick): string => $tick.' '.$text('history.kbit'), ['20', '15', '10', '5', '0']),
    'A quiet network in kilobits: '.json_encode($quietNet['ticks'], JSON_UNESCAPED_UNICODE));
$ratesPage = $state(['section' => 'overview', 'overview' => $overview, 'history' => $history, 'charts' => $fast]);
env_assert(substr_count($ratesPage, 'data-env-chart-legend') === 2 && str_contains($ratesPage, 'data-env-chart-line="out"') && str_contains($ratesPage, 'data-env-chart-series="write"'),
    'Two lines and a legend: '.$ratesPage);
env_assert(\Larena\Env\History\HistoryCharts::hasPoints($rates) && !\Larena\Env\History\HistoryCharts::hasPoints(['points' => []]) && !\Larena\Env\History\HistoryCharts::hasPoints(null),
    'Whether the server kept points.');
$chartsPage = $state(['section' => 'overview', 'overview' => $overview, 'history' => $history, 'charts' => $hour]);
env_assert(str_contains($chartsPage, 'data-env-charts="hour"') && substr_count($chartsPage, 'data-env-chart="') === 3 && substr_count($chartsPage, 'data-env-charts-period=') === 5
    && preg_match('/data-env-charts-period="hour"\s+aria-current="true"/', $chartsPage) === 1 && str_contains($chartsPage, '<title>01.11 12:00 UTC · 75 %</title>')
    && !str_contains($chartsPage, 'data-env-history-chart='), 'The charts replace the old history: '.$chartsPage);

// A safe update waiting (simai-env 1.32.0): the overview says so in a line and links to the update screen.
$hinted = $state(['section' => 'overview', 'overview' => $overview, 'updates' => [['target' => 'env', 'available' => '9.9.0']]]);
env_assert(str_contains($hinted, 'data-env-update-hint') && str_contains($hinted, 'data-env-update-hint-target="env"') && str_contains($hinted, '9.9.0')
    && !str_contains($hinted, 'data-env-update-hint-target="panel"'), 'An update is hinted on the overview: '.$hinted);
env_assert(!str_contains($ok, 'data-env-update-hint'), 'No update, no hint.');

// Where the server sends its alerts (simai-env 1.31.0): the channel now, its last sending and error, the form with a
// token field that is never filled, and a test; a server without it says so and offers nothing.
$channel = static fn (array $data): string => $factory->make('larena-env::admin.alerts', $data + ['supported' => true, 'canTest' => true, 'channel' => null, 'notice' => null])->render();
$telegram = $channel(['channel' => ['kind' => 'telegram', 'target_hint' => 'chat …4567', 'last_sent_at' => '2026-01-01T00:00:00Z', 'last_error' => 'timeout <b>']]);
env_assert(str_contains($telegram, 'data-env-alerts-channel="telegram"') && str_contains($telegram, 'chat …4567') && str_contains($telegram, '01.01.2026 00:00 UTC')
    && str_contains($telegram, 'timeout &lt;b&gt;') && str_contains($telegram, 'type="password" id="env-alerts-token" name="token"')
    && !preg_match('/name="token"[^>]*value=/', $telegram) && str_contains($telegram, 'data-env-alerts-test'), 'The channel: '.$telegram);
$off = $channel([]);
env_assert(str_contains($off, 'data-env-alerts-channel="off"') && str_contains($off, $text('alerts.never')) && str_contains($off, $text('alerts.no_error')), 'Nowhere yet.');
$older = $channel(['supported' => false, 'canTest' => false]);
env_assert(str_contains($older, 'data-env-alerts-unsupported') && !str_contains($older, 'data-env-alerts-form'), 'An older server: nothing to change.');
$alertsSource = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/alerts.blade.php');
env_assert(!str_contains($alertsSource, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $alertsSource) !== 1, 'The alerts view prints nothing raw and holds no script.');
env_assert(!str_contains($loaded, $text('metrics.swap')), 'No swap, no swap line.');
env_assert(!str_contains($ok, 'data-env-metrics'), 'Without the server\'s numbers, no load section.');
// The measures as tiles that take their threshold's colour; swap mostly taken is said, a container's file mounts are not disks.
env_assert(str_contains($loaded, 'data-env-metric="disk:/" data-env-metric-tone="error"') && str_contains($loaded, 'data-env-metric="memory" data-env-metric-tone="success"')
    && str_contains($loaded, 'data-env-metric="load" data-env-metric-tone="success"'), 'The tiles take their colour: '.$loaded);
$swapped = \Larena\Env\Server\ServerMetrics::fromData(['cpu' => ['cores' => 2, 'load1' => 1.0, 'load5' => 1.0, 'load15' => 1.0],
    'swap' => ['total_bytes' => 1_000, 'used_bytes' => 830], 'disk' => [['mount' => '/etc/hostname', 'total_bytes' => 100, 'used_bytes' => 1],
    ['mount' => '/', 'total_bytes' => 100, 'used_bytes' => 10]], 'checked_at' => '2026-11-01T10:00:00Z']);
env_assert(array_column($swapped->disks, 'mount') === ['/'] && $swapped->loadPercent() === 50, 'A mounted file is not a disk; the load is in percent of the cores.');
$swapWarnings = \Larena\Env\Warnings\PanelWarnings::of($overview, $swapped, new DateTimeImmutable('2026-11-01T12:00:00Z'));
env_assert(($swapWarnings[array_search('swap_full', array_map(static fn ($warning): string => $warning->kind, $swapWarnings), true)] ?? null)?->severity === 'warning', 'Swap at 83% is a warning.');
// The server's own alerts do not watch swap: the panel's reading is one warning of its own, also next to them.
env_assert(\Larena\Env\Warnings\PanelWarnings::swap($swapped)?->kind === 'swap_full'
    && \Larena\Env\Warnings\PanelWarnings::swap(\Larena\Env\Server\ServerMetrics::fromData(['swap' => ['total_bytes' => 1_000, 'used_bytes' => 960]]))?->severity === 'error'
    && \Larena\Env\Warnings\PanelWarnings::swap(\Larena\Env\Server\ServerMetrics::fromData(['swap' => ['total_bytes' => 1_000, 'used_bytes' => 790]])) === null
    && \Larena\Env\Warnings\PanelWarnings::swap(null) === null, 'Swap: a warning from 80%, an error from 95%, nothing below or unknown.');
$swapDrawn = $state(['section' => 'overview', 'overview' => $overview, 'metrics' => $swapped, 'warnings' => $swapWarnings]);
env_assert(str_contains($swapDrawn, 'data-env-metric="swap" data-env-metric-tone="warning"'), 'Swap is a tile, its warning in the alert bar.');
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
