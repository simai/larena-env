<?php

declare(strict_types=1);

// Panel plan stage 5 on the panel's side: the services, a site's scheduled jobs, the firewall and who reaches the panel,
// and the kinds of site the server makes. No simai-env release has the commands yet: they are offered only by a server
// that lists them, and a stand-in on recorded answers answers them for the tests and the install check.

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Core\Process\RecordingProcessExecutor;
use Larena\Env\Cron\CronSchedule;
use Larena\Env\Firewall\AddressList;
use Larena\Env\Firewall\TimedChange;
use Larena\Env\Gateway\FakeServerToolsGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Controllers\NewSiteController;
use Larena\Env\Http\Controllers\ServicesController;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$text = static function (string $key, array $replace = []) use ($lines): string {
    $value = data_get($lines, $key);
    env_assert(is_string($value), "No English text for {$key}.");
    foreach ($replace as $name => $with) {
        $value = str_replace(':'.$name, $with, $value);
    }

    return $value;
};

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $text;

    return $text(str_replace('larena-env::admin.', '', $key), $replace);
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('staff@example.com', 'demo');
$refusedWith = static function (callable $make): ?string {
    try {
        $make();
    } catch (PanelRefused $refused) {
        return $refused->reasonCode;
    }

    return null;
};

// Hidden until a server lists them: no release has them yet.
foreach (['service:restart', 'service:log', 'self:profiles', 'cron:list', 'cron:job-add', 'firewall:status', 'firewall:rule-add', 'panel:access'] as $command) {
    env_assert(PanelCommands::since($command) === null && !(new PanelCapabilities('9.9.9'))->allows($command)
        && (new PanelCapabilities('1.32.0', [$command]))->allows($command), "{$command} only where the server lists it.");
}

// The table refuses before the gateway does: a service off the list, a schedule of other than five fields, a command of
// two lines, a port of a proxy that is none, and an address that is none; the panel's allow list is the one list.
foreach ([['service:restart', ['name' => 'nginx']], ['service:reload', ['name' => 'php8.3-fpm']], ['service:log', ['name' => 'mysql', 'lines' => '100']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '0 3 * * mon', 'command' => 'echo «привет»']],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '3000', 'upstream-path' => '/', 'websockets' => 'yes']],
    ['firewall:rule-add', ['port' => '8000:8100', 'proto' => 'tcp', 'from' => '203.0.113.0/24', 'action' => 'allow']],
    ['firewall:block', ['address' => '2001:db8::1', 'ttl' => '3600']],
    ['panel:access', ['allow' => ['203.0.113.7', '198.51.100.0/24']]], ['panel:access', ['allow' => 'any']]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args)) === null, "Accepted: {$command} ".json_encode($args));
}
foreach ([['service:restart', ['name' => 'sshd']], ['service:restart', ['name' => 'nginx; reboot']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * *', 'command' => 'x']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * * *', 'command' => "a\nrm -rf /"]],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '70000']],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '3000', 'upstream-path' => 'http://evil']],
    ['firewall:rule-add', ['port' => '22', 'action' => 'reject']], ['firewall:block', ['address' => 'example.com']],
    ['panel:access', ['allow' => []]], ['panel:access', ['allow' => array_fill(0, 51, '203.0.113.7')]], ['panel:access', ['allow' => ['any; drop']]]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args)) === 'invalid_argument', "Refused: {$command} ".json_encode($args));
}

// Schedules in words, and the addresses a person types.
env_assert(CronSchedule::words('*/5 * * * *') === ['five_minutes', []] && CronSchedule::words('30 2 * * *') === ['daily_at', ['time' => '02:30']]
    && CronSchedule::words('7 * * * *') === ['hourly_at', ['minute' => '07']] && CronSchedule::words('0 4 * * 7') === ['weekly_at', ['time' => '04:00', 'weekday' => '0']]
    && CronSchedule::words('0 4 1-5 * *') === null, 'A schedule in words, or as written.');
env_assert(CronSchedule::valid('*/5 * * * *') && !CronSchedule::valid('* * * *') && !CronSchedule::valid('* * * * * *'), 'Five fields.');
env_assert(AddressList::parse("203.0.113.7\n198.51.100.0/24, 2001:db8::/32") === ['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']
    && AddressList::parse('203.0.113.7 bad') === null && AddressList::parse('10.0.0.0/33') === null, 'Addresses and networks are read; anything else is refused.');
env_assert(AddressList::contains(['198.51.100.0/24'], '198.51.100.42') && !AddressList::contains(['198.51.100.0/24'], '198.51.101.1')
    && AddressList::contains(['2001:db8::/32'], '2001:db8:1::5') && !AddressList::contains(['203.0.113.7'], '2001:db8::1')
    && AddressList::contains(['10.0.0.0/7'], '11.255.0.1') && !AddressList::contains(['10.0.0.0/7'], '12.0.0.1'), 'Whether an address is among them.');
$change = new TimedChange('c1', 'firewall', '2026-01-01T00:02:00Z');
env_assert($change->secondsLeft(new DateTimeImmutable('2026-01-01T00:00:30Z')) === 90 && $change->secondsLeft(new DateTimeImmutable('2026-01-01T01:00:00Z')) === 0,
    'How long a change waits to be kept.');

// The stand-in: only when its folder is configured, on top of the files' one too.
$directory = env_temp_directory();
$gateway = PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'tools' => $directory.'/tools']], true, static fn () => new RecordingProcessExecutor());
env_assert($gateway instanceof FakeServerToolsGateway, 'The stand-in only when its folder is configured.');
env_assert(PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path(), 'files' => $directory.'/files', 'tools' => $directory.'/tools']],
    true, static fn () => new RecordingProcessExecutor()) instanceof FakeServerToolsGateway, 'Both stand-ins together.');
$listed = (new ServerOverviewReader($gateway))->read($admin)->overview->capabilities;
env_assert($listed->allows('service:restart') && $listed->allows('firewall:status') && $listed->allows('cron:list') && $listed->allows('self:profiles'), 'The host description lists them.');
$ownListed = (new ServerOverviewReader($gateway))->read($owner)->overview?->capabilities;
env_assert($ownListed === null || (!$ownListed->allows('service:restart') && !$ownListed->allows('firewall:status')), 'An owner is never offered the services or the firewall.');

$operations = new PanelOperations($gateway);
$extras = new SiteExtrasReader($gateway);

// The services (5.1): planned, confirmed, a job; their journal; the administrator's alone.
$planned = $operations->planCommand($admin, 'service:restart', ['name' => 'nginx']);
env_assert($planned->plan !== null && $planned->plan->command === 'service:restart', 'A restart is planned first.');
$restarted = $operations->startCommand($admin, 'service:restart', ['name' => 'nginx']);
$view = $operations->view($admin, (string) $restarted->jobId);
env_assert($view->status !== null && $view->status->change === null && in_array('Restarted nginx', $view->log->lines ?? [], true), 'Confirmed, its job says what it did.');
env_assert($extras->serviceLog($admin, 'nginx') === ['nginx: started', 'nginx: settings checked, running'], 'The journal of a service.');
env_assert($operations->planCommand($owner, 'service:restart', ['name' => 'nginx'])->reason === 'owner_admin_only', 'An owner cannot restart the server\'s services.');
env_assert(ServicesController::reloads('nginx') && ServicesController::reloads('php8.3-fpm') && !ServicesController::reloads('mysql'), 'Only nginx and PHP-FPM reread settings.');

// The kinds of site (5.5): from the server, with the fields each asks.
$profiles = $extras->profiles($admin);
$byName = [];
foreach ($profiles ?? [] as $profile) {
    $byName[$profile->name] = $profile;
}
env_assert(isset($byName['proxy'], $byName['bitrix']) && $byName['proxy']->takes('upstream-port') && !$byName['static']->takes('php'), 'The server\'s kinds and their arguments.');
env_assert(NewSiteController::groupsOf('proxy', $byName) === ['proxy'] && NewSiteController::groupsOf('bitrix', $byName) === ['php', 'database']
    && NewSiteController::groupsOf('redirect', $byName) === ['redirect'] && NewSiteController::groupsOf('static', $byName) === []
    && NewSiteController::groupsOf('nope', $byName) === [], 'Each kind asks only its own fields.');
env_assert(NewSiteController::groupsOf('laravel', null) === ['php', 'database'] && NewSiteController::groupsOf('node', null) === [], 'Without the server\'s list, the six every server has.');
env_assert(is_array($extras->profiles($owner)), 'An owner sees the kinds too: they make sites in their space.');

// A site's scheduled jobs (5.4): its own, added, turned off and on, removed; its runs, the newest first; an owner's own sites only.
$jobs = $extras->cronJobs($admin, 'demo-php.test');
env_assert(is_array($jobs) && count($jobs) === 1 && $jobs[0]->id === 'job-backup' && $jobs[0]->lastRun?->exitCode === 1, 'A site\'s jobs and the last run.');
$runs = $extras->cronRuns($admin, 'job-backup');
env_assert(count($runs ?? []) === 2 && $runs[0]->startedAt === '2026-01-02T02:30:00Z' && !$runs[0]->succeeded() && $runs[0]->output === ['Disk is full'], 'The runs, the newest first, with their output.');
$added = $operations->startCommand($admin, 'cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '*/5 * * * *', 'command' => 'php artisan queue:work --stop-when-empty']);
$jobs = $extras->cronJobs($admin, 'demo-php.test');
env_assert($added->jobId !== null && count($jobs) === 2 && $jobs[1]->schedule === '*/5 * * * *' && $jobs[1]->lastRun === null, 'A job is added.');
$operations->startCommand($admin, 'cron:job-disable', ['id' => $jobs[1]->id]);
env_assert($extras->cronJobs($admin, 'demo-php.test')[1]->enabled === false, 'Turned off.');
$operations->startCommand($admin, 'cron:job-remove', ['id' => $jobs[1]->id]);
env_assert(count($extras->cronJobs($admin, 'demo-php.test')) === 1, 'Removed.');
env_assert($extras->cronJobs($owner, 'demo-php.test') === null && is_array($extras->cronJobs($owner, 'demo-owned.test')), 'An owner reads their own site\'s jobs only.');
env_assert($operations->planCommand($owner, 'cron:job-enable', ['id' => 'job-backup'])->reason === 'owner_scope'
    && $operations->planCommand($owner, 'cron:job-enable', ['id' => 'job-owned'])->plan !== null, 'And changes their own site\'s jobs only.');

// The firewall (5.2): a change is applied, waits to be kept, and comes back by itself; SSH and the panel's port stay open.
$status = $extras->firewall($admin);
env_assert($status !== null && $status->active && count($status->rules) === 3 && $status->rules[0]->protected && !$status->rules[1]->protected
    && $status->panelAccess === 'any' && $status->pending === [], 'The firewall\'s state.');
env_assert($operations->planCommand($admin, 'firewall:rule-remove', ['id' => '1'])->reason === 'firewall_protected'
    && $operations->planCommand($admin, 'firewall:rule-add', ['port' => '22', 'action' => 'deny'])->reason === 'firewall_protected', 'SSH cannot be closed.');
$applied = $operations->startCommand($admin, 'firewall:rule-add', ['port' => '8080', 'proto' => 'tcp', 'action' => 'allow']);
$appliedView = $operations->view($admin, (string) $applied->jobId);
$pending = $appliedView->status?->change;
env_assert($pending !== null && $pending->kind === 'firewall' && $pending->pending() && $pending->secondsLeft(new DateTimeImmutable()) > 60,
    'The job\'s result names the change and when it is undone.');
$status = $extras->firewall($admin);
env_assert(count($status->rules) === 4 && $status->pendingOf('firewall')?->changeId === $pending->changeId, 'Applied, and waiting to be kept.');
$kept = $operations->startCommand($admin, 'change:confirm', ['change_id' => $pending->changeId]);
env_assert($kept->jobId !== null && $extras->firewall($admin)->pending === [] && count($extras->firewall($admin)->rules) === 4, 'Kept in time: it stays.');
env_assert($operations->startCommand($admin, 'change:confirm', ['change_id' => $pending->changeId])->reason === 'unknown_change', 'A change kept already cannot be kept again.');
$blocked = $operations->view($admin, (string) $operations->startCommand($admin, 'firewall:block', ['address' => '203.0.113.9', 'ttl' => '3600'])->jobId)->status?->change;
env_assert(count($extras->firewall($admin)->blocks) === 1 && $blocked !== null, 'An address is blocked, waiting to be kept.');
$state = json_decode((string) file_get_contents($directory.'/tools/state.json'), true);
$state['changes'][$blocked->changeId]['expires_at'] = '2000-01-01T00:00:00Z';
file_put_contents($directory.'/tools/state.json', json_encode($state));
env_assert($extras->firewall($admin)->blocks === [] && count($extras->firewall($admin)->rules) === 4, 'Not kept in time: the previous rules come back by themselves.');
env_assert($extras->firewall($owner) === null && $operations->planCommand($owner, 'firewall:block', ['address' => '203.0.113.9'])->reason === 'owner_admin_only', 'The firewall is the administrator\'s.');

// Who reaches the panel (5.3): a list or anyone, kept like the firewall.
$access = $operations->view($admin, (string) $operations->startCommand($admin, 'panel:access', ['allow' => ['203.0.113.7', '198.51.100.0/24']])->jobId)->status?->change;
env_assert($access?->kind === 'panel_access' && $extras->firewall($admin)->panelAccess === ['203.0.113.7', '198.51.100.0/24']
    && $extras->firewall($admin)->pendingOf('panel_access') !== null, 'The panel\'s allow list, waiting to be kept.');

// The screens on stubs of the admin's layout.
$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, env_temp_directory()), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$overview = (new ServerOverviewReader($gateway))->read($admin)->overview;
$services = static fn (array $data): string => $factory->make('larena-env::admin.services', $data + ['source' => 'recorded', 'services' => $overview->services,
    'reasonText' => null, 'allows' => ['restart' => true, 'reload' => true, 'log' => true], 'logOf' => null, 'log' => null, 'notice' => null])->render();
$all = $services([]);
env_assert(str_contains($all, 'data-env-service="nginx"') && str_contains($all, 'data-env-service-op="restart"') && str_contains($all, 'data-env-service-op="reload"')
    && substr_count($all, 'data-env-service-op="reload"') === 2 && str_contains($all, 'data-env-service-state="inactive"') && str_contains($all, 'role="alert"'), 'Each service, its state, and restart or reread: '.$all);
$readOnly = $services(['allows' => ['restart' => false, 'reload' => false, 'log' => false]]);
env_assert(str_contains($readOnly, 'data-env-services-unsupported') && !str_contains($readOnly, 'data-env-service-op') && !str_contains($readOnly, 'data-env-service-log-link'), 'A server without them: the state only.');
env_assert(str_contains($services(['logOf' => 'nginx', 'log' => ['line <b>']]), 'line &lt;b&gt;'), 'The journal, escaped.');

$firewall = static fn (array $data): string => $factory->make('larena-env::admin.firewall', $data + ['source' => 'recorded', 'supported' => true,
    'status' => $extras->firewall($admin), 'allows' => ['rule-add' => true, 'rule-remove' => true, 'block' => true, 'unblock' => true, 'access' => true, 'keep' => true],
    'pending' => null, 'now' => new DateTimeImmutable(), 'ttls' => \Larena\Env\Http\Controllers\FirewallController::BLOCK_TTLS, 'address' => '203.0.113.7',
    'addressListed' => true, 'notice' => null])->render();
$walls = $firewall(['pending' => new TimedChange('c9', 'firewall', '2999-01-01T00:00:00Z')]);
env_assert(str_contains($walls, 'data-env-firewall="on"') && str_contains($walls, 'data-env-timed-keep="c9"') && str_contains($walls, 'data-env-firewall-locked')
    && preg_match('/data-env-firewall-rule="1"\s+data-env-firewall-protected/', $walls) === 1 && substr_count($walls, 'data-env-firewall-op="rule-remove"') === 2
    && str_contains($walls, 'data-env-firewall-op="block"'), 'The firewall: its rules, the protected ones locked, a change to keep in time: '.$walls);
env_assert(str_contains($firewall(['supported' => false, 'status' => null]), 'data-env-firewall-unsupported'), 'An older server: said, nothing offered.');
$panelPage = static fn (array $data): string => $factory->make('larena-env::admin.panel-access', $data + ['source' => 'recorded', 'supported' => true,
    'status' => $extras->firewall($admin), 'allows' => ['access' => true, 'keep' => true], 'pending' => $extras->firewall($admin)->pendingOf('panel_access'),
    'now' => new DateTimeImmutable(), 'address' => '192.0.2.1', 'addressListed' => false, 'ttls' => [], 'notice' => null])->render();
$outside = $panelPage([]);
env_assert(str_contains($outside, 'data-env-panel-access="list"') && str_contains($outside, 'data-env-panel-access-outside') && str_contains($outside, 'data-env-timed-keep=')
    && str_contains($outside, 'data-env-panel-access-everyone') && str_contains($outside, '198.51.100.0/24'), 'Your address is not in the list: said before anything changes: '.$outside);

$cronTab = static fn (array $cron): string => $factory->make('larena-env::admin.partials.cron', ['domain' => 'demo-php.test', 'cron' => $cron + ['jobs' => null,
    'opened' => null, 'runs' => null, 'allows' => ['add' => true, 'remove' => true, 'enable' => true, 'disable' => true, 'runs' => true],
    'presets' => CronSchedule::PRESETS, 'when' => []]])->render();
$jobsNow = $extras->cronJobs($admin, 'demo-php.test');
$tab = $cronTab(['jobs' => $jobsNow, 'when' => ['job-backup' => 'Every day at 02:30'], 'opened' => $jobsNow[0], 'runs' => $extras->cronRuns($admin, 'job-backup')]);
env_assert(str_contains($tab, 'data-env-cron-job="job-backup"') && str_contains($tab, 'Every day at 02:30') && str_contains($tab, 'data-env-cron-last="failed"')
    && str_contains($tab, 'data-env-cron-op="disable"') && !str_contains($tab, 'data-env-cron-op="enable"') && str_contains($tab, 'data-env-cron-runs="job-backup"')
    && str_contains($tab, 'Disk is full') && str_contains($tab, 'data-env-cron-op="add"') && str_contains($tab, 'value="custom"'), 'The scheduler tab: '.$tab);
env_assert(str_contains($cronTab(['jobs' => []]), 'data-env-cron-none') && str_contains($cronTab([]), 'data-env-cron="unreadable"'), 'No jobs, or none readable, said so.');

$job = $factory->make('larena-env::admin.job', ['jobId' => (string) $applied->jobId, 'source' => 'recorded', 'status' => $appliedView->status, 'log' => $appliedView->log,
    'refused' => false, 'reasonText' => null, 'notice' => null, 'change' => new TimedChange('c7', 'firewall', '2999-01-01T00:00:00Z'), 'canKeep' => true,
    'now' => new DateTimeImmutable()])->render();
env_assert(str_contains($job, 'data-env-timed-change="c7"') && str_contains($job, 'data-env-timed-keep="c7"') && str_contains($job, 'name="kind" value="firewall"'), 'The job of a timed change offers to keep it: '.$job);

$wizard = $factory->make('larena-env::admin.new-site', ['profiles' => ['static', 'proxy', 'bitrix'], 'phpProfiles' => [], 'phpVersions' => ['8.3'], 'owners' => null,
    'old' => [], 'notice' => null, 'kind' => 'proxy', 'serverProfiles' => [
        'static' => ['label' => 'Static files', 'hint' => 'Files.', 'groups' => []],
        'proxy' => ['label' => 'App on a port', 'hint' => 'A port.', 'groups' => ['proxy']],
        'bitrix' => ['label' => '1C-Bitrix', 'hint' => 'Bitrix.', 'groups' => ['php', 'database']]]])->render();
env_assert(substr_count($wizard, 'data-env-profile=') === 3 && str_contains($wizard, 'data-env-field="proxy"') && str_contains($wizard, 'name="upstream-port"')
    && !str_contains($wizard, 'data-env-field="php"') && str_contains($wizard, 'App on a port'), 'A proxy asks for the local port only: '.$wizard);

foreach (['services', 'firewall', 'panel-access', 'partials/cron', 'partials/timed-change'] as $name) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$name.'.blade.php');
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "The {$name} view prints nothing raw and holds no script.");
}

echo "ServerToolsTest passed.\n";
