<?php

declare(strict_types=1);

// Panel plan stage 5 on the panel's side, on recorded answers: the services and the kinds of site the server makes
// (simai-env 1.33.0, set 9), a site's scheduled jobs (1.34.0, set 10), the firewall and who reaches the panel, each
// change undone by the server unless kept in time (1.35.0, set 11), the server's own DNS token for certificates (1.36.0, set 12), the server's PHP versions (1.37.0, set 13).

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
use Larena\Env\Firewall\FirewallStatus;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Controllers\NewSiteController;
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

// The services and the kinds of site came with simai-env 1.33.0; the rest wait, hidden until a server lists them.
foreach (['service:list', 'service:restart', 'service:reload', 'service:log', 'self:profiles'] as $command) {
    env_assert(PanelCommands::since($command) === '1.33.0' && (new PanelCapabilities('1.33.0'))->allows($command) && !(new PanelCapabilities('1.32.0'))->allows($command),
        "{$command} from simai-env 1.33.0.");
}
env_assert((new PanelCapabilities('1.33.0'))->takes('site:add', 'upstream-port') && (new PanelCapabilities('1.33.0'))->takes('site:add', 'bitrix-files')
    && !(new PanelCapabilities('1.32.0'))->takes('site:add', 'upstream-port'), 'An app on a port and a Bitrix installer from 1.33.0.');
foreach (['cron:list', 'cron:job-add', 'cron:job-remove', 'cron:job-enable', 'cron:job-disable', 'cron:job-runs'] as $command) {
    env_assert(PanelCommands::since($command) === '1.34.0' && (new PanelCapabilities('1.34.0'))->allows($command) && !(new PanelCapabilities('1.33.0'))->allows($command),
        "{$command} from simai-env 1.34.0.");
}
foreach (['firewall:status', 'firewall:rule-add', 'firewall:rule-remove', 'firewall:block', 'firewall:unblock', 'panel:access', 'change:confirm', 'change:status'] as $command) {
    env_assert(PanelCommands::since($command) === '1.35.0' && (new PanelCapabilities('1.35.0'))->allows($command) && !(new PanelCapabilities('1.34.0'))->allows($command),
        "{$command} from simai-env 1.35.0.");
}

// The table refuses before the gateway does: a service off the list, a schedule of other than five fields, a command of
// two lines, a port of a proxy that is none, and an address that is none; the panel's allow list is the one list.
foreach ([['service:restart', ['name' => 'nginx']], ['service:reload', ['name' => 'php8.3-fpm']], ['service:log', ['name' => 'mysql', 'lines' => '100']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '0,30 8-18/2 1-15 * 1-5', 'command' => 'echo «привет»']],
    ['cron:job-runs', ['id' => 'a1b2c3d4', 'limit' => '50']],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '3000', 'upstream-path' => '/', 'websockets' => 'yes']],
    ['site:add', ['domain' => 'shop.test', 'profile' => 'bitrix', 'bitrix-files' => 'restore']],
    ['firewall:rule-add', ['port' => '8080', 'proto' => 'tcp', 'from' => '203.0.113.0/24', 'action' => 'allow']], ['firewall:rule-add', ['port' => '53', 'proto' => 'udp']],
    ['firewall:rule-remove', ['id' => 'efdea676']], ['firewall:block', ['address' => '2001:db8::1', 'ttl' => '3600']], ['firewall:block', ['address' => '203.0.113.7', 'ttl' => '0']],
    ['change:confirm', ['change_id' => 'c966b67eb35b']],
    ['panel:access', ['allow' => ['203.0.113.7', '198.51.100.0/24']]], ['panel:access', ['allow' => 'any']]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args)) === null, "Accepted: {$command} ".json_encode($args));
}
foreach ([['service:restart', ['name' => 'sshd']], ['service:restart', ['name' => 'nginx; reboot']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * *', 'command' => 'x']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * * *', 'command' => "a\nrm -rf /"]],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '@daily * * * *', 'command' => 'x']], ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '0 3 * * mon', 'command' => 'x']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * * *', 'command' => '-rf']], ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * * *', 'command' => ' x']],
    ['cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '* * * * *', 'command' => str_repeat('a', 1001)]],
    ['cron:job-remove', ['id' => 'job-backup']], ['cron:job-runs', ['id' => 'a1b2c3d4', 'limit' => '51']],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '70000']],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '80']], ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '443']],
    ['site:add', ['domain' => 'shop.test', 'profile' => 'bitrix', 'bitrix-files' => 'download']], ['service:restart', ['name' => 'redis']],
    ['site:add', ['domain' => 'app.test', 'profile' => 'proxy', 'upstream-port' => '3000', 'upstream-path' => 'http://evil']],
    ['firewall:rule-add', ['port' => '22', 'proto' => 'tcp', 'action' => 'reject']], ['firewall:rule-add', ['port' => '8000:8100', 'proto' => 'tcp']],
    ['firewall:rule-add', ['port' => '8080', 'proto' => 'any']], ['firewall:rule-add', ['port' => '8080']], ['firewall:rule-remove', ['id' => '1']],
    ['firewall:block', ['address' => 'example.com']], ['firewall:block', ['address' => '203.0.113.7', 'ttl' => '5']],
    ['panel:access', ['allow' => []]], ['panel:access', ['allow' => array_fill(0, 51, '203.0.113.7')]], ['panel:access', ['allow' => ['any; drop']]]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args)) === 'invalid_argument', "Refused: {$command} ".json_encode($args));
}

// Schedules in words, and the addresses a person types.
env_assert(CronSchedule::words('*/5 * * * *') === ['five_minutes', []] && CronSchedule::words('30 2 * * *') === ['daily_at', ['time' => '02:30']]
    && CronSchedule::words('7 * * * *') === ['hourly_at', ['minute' => '07']] && CronSchedule::words('0 4 * * 7') === ['weekly_at', ['time' => '04:00', 'weekday' => '0']]
    && CronSchedule::words('0 4 1-5 * *') === null, 'A schedule in words, or as written.');
env_assert(CronSchedule::valid('*/5 * * * *') && !CronSchedule::valid('* * * *') && !CronSchedule::valid('* * * * * *'), 'Five fields.');
foreach (CronSchedule::PRESETS as $preset) {
    env_assert(CronSchedule::valid($preset), "Each preset is five fields the server takes: {$preset}.");
}
foreach (['59 23 31 12 7', '0,15,30,45 * * * *', '*/10 2-4 * * 0', '0 8-18/2 * * 1-5'] as $schedule) {
    env_assert(CronSchedule::valid($schedule), "Taken: {$schedule}.");
}
foreach (['61 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *', '* * * * 8', '*/0 * * * *', '5-2 * * * *', '@daily', '@reboot', '0 3 * * mon', '0  3 * * *', '1,,2 * * * *'] as $schedule) {
    env_assert(!CronSchedule::valid($schedule), "Refused before any job: {$schedule}.");
}
env_assert(CronSchedule::commandValid('php artisan schedule:run') && !CronSchedule::commandValid('-x') && !CronSchedule::commandValid('x ')
    && !CronSchedule::commandValid("a\tb") && !CronSchedule::commandValid('') && CronSchedule::commandValid(str_repeat('я', 1000)) && !CronSchedule::commandValid(str_repeat('я', 1001)),
    'One printable line up to 1000 characters, not starting with "-".');
env_assert(AddressList::parse("203.0.113.7\n198.51.100.0/24, 2001:db8::/32") === ['203.0.113.7', '198.51.100.0/24', '2001:db8::/32']
    && AddressList::parse('203.0.113.7 bad') === null && AddressList::parse('10.0.0.0/33') === null, 'Addresses and networks are read; anything else is refused.');
env_assert(AddressList::contains(['198.51.100.0/24'], '198.51.100.42') && !AddressList::contains(['198.51.100.0/24'], '198.51.101.1')
    && AddressList::contains(['2001:db8::/32'], '2001:db8:1::5') && !AddressList::contains(['203.0.113.7'], '2001:db8::1')
    && AddressList::contains(['10.0.0.0/7'], '11.255.0.1') && !AddressList::contains(['10.0.0.0/7'], '12.0.0.1'), 'Whether an address is among them.');
$change = new TimedChange('c1', 'firewall', '2026-01-01T00:02:00Z');
env_assert($change->secondsLeft(new DateTimeImmutable('2026-01-01T00:00:30Z')) === 90 && $change->secondsLeft(new DateTimeImmutable('2026-01-01T01:00:00Z')) === 0,
    'How long a change waits to be kept.');

// The recorded answers list the commands of 1.35.0 to the administrator; an owner is never offered the server's tools.
$gateway = PanelGatewayFactory::make(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path()]], true, static fn () => new RecordingProcessExecutor());
$listed = (new ServerOverviewReader($gateway))->read($admin)->overview->capabilities;
env_assert($listed->allows('service:restart') && $listed->allows('firewall:status') && $listed->allows('change:confirm') && $listed->allows('cron:list') && $listed->allows('self:profiles'), 'The host description lists them.');
$ownListed = (new ServerOverviewReader($gateway))->read($owner)->overview?->capabilities;
env_assert($ownListed === null || (!$ownListed->allows('service:restart') && !$ownListed->allows('firewall:status')), 'An owner is never offered the services or the firewall.');

$operations = new PanelOperations($gateway);
$extras = new SiteExtrasReader($gateway);

// The services (5.1, recorded set 9): only those the server has, each with whether it rereads its settings; planned,
// confirmed, a job; the journal; the administrator's alone.
$services = $extras->services($admin);
env_assert(array_map(static fn ($service): string => $service->name, $services ?? []) === ['nginx', 'mysql', 'cron', 'php8.3-fpm']
    && $services[0]->canReload && !$services[1]->canReload && $services[3]->canReload && $services[0]->isActive()
    && $services[0]->since === 'Thu 2026-01-01 00:00:00 UTC' && $services[0]->enabled === 'enabled', 'The server\'s services (simai-services/1).');
$planned = $operations->planCommand($admin, 'service:restart', ['name' => 'nginx']);
env_assert($planned->plan !== null && $planned->plan->command === 'service:restart', 'A restart is planned first.');
env_assert($operations->planCommand($admin, 'service:reload', ['name' => 'nginx'])->plan?->command === 'service:reload', 'A reread too.');
env_assert($operations->startCommand($admin, 'service:restart', ['name' => 'nginx'])->jobId !== null, 'Confirmed, a job.');
$journal = $extras->serviceLog($admin, 'nginx');
env_assert(count($journal ?? []) === 1 && str_contains($journal[0], 'Started nginx.service'), 'The journal of a service (simai-service-log/1).');
env_assert($operations->planCommand($owner, 'service:restart', ['name' => 'nginx'])->reason === 'not_allowed' && $extras->services($owner) === null,
    'An owner cannot see or restart the server\'s services.');
env_assert($operations->planCommand($admin, 'service:restart', ['name' => 'sshd'])->reason === 'invalid_argument', 'A service off the list is refused.');
$failed = \Larena\Env\Operations\PanelJobStatus::fromData(['job_id' => 'j1', 'state' => 'failed', 'command' => 'service:reload', 'args' => ['name' => 'nginx'], 'exit_code' => 1]);
env_assert(\Larena\Env\Http\Controllers\ServerJobController::configFailed($failed, ['nginx: the configuration test failed; the service was not reloaded and keeps running']) === 'nginx'
    && \Larena\Env\Http\Controllers\ServerJobController::configFailed($failed, ['Job failed: timeout']) === null, 'A broken configuration is told apart.');

// The kinds of site (5.5, recorded set 9): from the server, only those site:add takes, with the fields each asks.
$profiles = $extras->profiles($admin);
$byName = [];
foreach ($profiles ?? [] as $profile) {
    $byName[$profile->name] = $profile;
}
env_assert(array_keys($byName) === ['bitrix', 'generic', 'laravel', 'node', 'proxy', 'redirect', 'static', 'wordpress'], 'The server\'s kinds: '.implode(', ', array_keys($byName)));
env_assert($byName['proxy']->takes('upstream-port') && $byName['proxy']->args['upstream-port']['required'] && $byName['proxy']->values('websockets') === ['yes', 'no']
    && !$byName['static']->takes('php') && $byName['bitrix']->values('bitrix-files') === ['none', 'setup', 'restore'] && $byName['bitrix']->title === '1C-Bitrix site'
    && $byName['bitrix']->db === 'required' && !$byName['proxy']->takes('backup'), 'Their arguments, values and titles (simai-site-profiles/1).');
env_assert(\Larena\Env\Server\SiteProfile::fromData(['id' => 'php', 'alias' => true, 'args' => []]) === null, 'An alias is not a kind site:add takes.');
env_assert(NewSiteController::groupsOf('proxy', $byName) === ['proxy'] && NewSiteController::groupsOf('bitrix', $byName) === ['php', 'database', 'bitrix']
    && NewSiteController::groupsOf('redirect', $byName) === ['redirect'] && NewSiteController::groupsOf('static', $byName) === []
    && NewSiteController::groupsOf('nope', $byName) === [], 'Each kind asks only its own fields.');
env_assert(!NewSiteController::backsUp('proxy', $byName) && NewSiteController::backsUp('static', $byName) && !NewSiteController::backsUp('redirect', null), 'A proxy and a redirect hold nothing to back up.');
env_assert(NewSiteController::groupsOf('laravel', null) === ['php', 'database'] && NewSiteController::groupsOf('node', null) === [], 'Without the server\'s list, the six every server has.');
env_assert(count($extras->profiles($owner) ?? []) === 8, 'An owner sees the kinds too: they make sites in their space.');
env_assert($operations->planCommand($admin, 'site:add', ['domain' => 'demo-proxy.test', 'profile' => 'proxy', 'upstream-port' => '3000', 'websockets' => 'yes'])->plan?->args['upstream-port'] === '3000'
    && $operations->planCommand($admin, 'site:add', ['domain' => 'demo-bitrix.test', 'profile' => 'bitrix', 'bitrix-files' => 'setup'])->plan?->args['bitrix-files'] === 'setup',
    'An app on a port and a 1C-Bitrix site are planned.');

// A site's scheduled jobs (5.4): its own, added, turned off and on, removed; its runs, the newest first; an owner's own sites only.
$jobs = $extras->cronJobs($admin, 'demo-owned.test');
env_assert(is_array($jobs) && count($jobs) === 1 && $jobs[0]->id === 'a1b2c3d4' && $jobs[0]->schedule === '*/10 * * * *' && $jobs[0]->enabled
    && $jobs[0]->createdAt === '2026-01-01T00:00:00Z' && $jobs[0]->lastRun?->exitCode === 0 && $jobs[0]->lastRun->finishedAt === '2026-01-01T00:00:02Z',
    'A site\'s jobs and the last run (simai-cron-jobs/1).');
$runs = $extras->cronRuns($admin, 'a1b2c3d4');
env_assert(count($runs ?? []) === 1 && $runs[0]->startedAt === '2026-01-01T00:00:00Z' && $runs[0]->succeeded() && $runs[0]->output === ['Synced 12 records.'],
    'The runs, the newest first, with their output (simai-cron-runs/1).');
env_assert(\Larena\Env\Cron\CronRun::fromData(['started' => '2026-01-01T00:00:00Z', 'finished' => '2026-01-01T00:00:01Z', 'exit_code' => 0])?->finishedAt === '2026-01-01T00:00:01Z',
    'The runs\' names the contract\'s text gives are read too.');
env_assert($operations->planCommand($owner, 'cron:job-add', ['domain' => 'demo-owned.test', 'schedule' => '0 3 * * *', 'command' => 'php artisan backup:clean'])->plan?->command === 'cron:job-add'
    && $operations->planCommand($admin, 'cron:job-disable', ['id' => 'a1b2c3d4'])->plan?->command === 'cron:job-disable'
    && $operations->planCommand($admin, 'cron:job-remove', ['id' => 'a1b2c3d4'])->plan?->command === 'cron:job-remove', 'Add, turn off, remove: each planned.');
env_assert($operations->planCommand($owner, 'cron:job-add', ['domain' => 'demo-php.test', 'schedule' => '0 3 * * *', 'command' => 'x'])->reason === 'owner_scope'
    && $extras->cronJobs($owner, 'demo-php.test') === null && count($extras->cronJobs($owner, 'demo-owned.test') ?? []) === 1, 'An owner\'s own sites only.');
env_assert($operations->planCommand($admin, 'cron:job-disable', ['id' => 'ffffffff'])->reason === 'unknown_cron_job', 'A job the server does not have.');

// The firewall (5.2, recorded set 11): its rules with the protected ones, a rule about an address as a whole, the
// blocks, no panel on the recording stand; every change planned, its job's result naming the change to keep in time.
$status = $extras->firewall($admin);
env_assert($status !== null && $status->active && $status->backend === 'ufw' && $status->defaultIncoming === 'deny' && count($status->rules) === 5
    && $status->rules[0]->port === null && $status->rules[0]->from === '203.0.113.7' && $status->rules[0]->action === 'deny' && !$status->rules[0]->protected
    && $status->rules[1]->protected && $status->rules[2]->port === '80' && $status->rules[2]->protected && $status->rules[4]->id === 'efdea676'
    && $status->rules[4]->rule === 'allow from 192.0.2.10 to any port 3306 proto tcp' && !$status->rules[4]->protected, 'The firewall\'s rules (simai-firewall/1).');
env_assert($status->blocks === [['address' => '203.0.113.7', 'until' => null]] && $status->panelAccess === null && $status->pending === null, 'Its blocks; no panel there; nothing waiting.');
foreach ([['firewall:rule-add', ['port' => '8080', 'proto' => 'tcp', 'action' => 'allow']], ['firewall:rule-remove', ['id' => 'efdea676']],
    ['firewall:block', ['address' => '198.51.100.20', 'ttl' => '3600']], ['firewall:unblock', ['address' => '203.0.113.7']],
    ['panel:access', ['allow' => ['192.0.2.10', '198.51.100.0/24']]], ['change:confirm', ['change_id' => 'c966b67eb35b']]] as [$command, $args]) {
    env_assert($operations->planCommand($admin, $command, $args)->plan?->command === $command, "{$command} is planned.");
}
env_assert($operations->startCommand($admin, 'firewall:block', ['address' => '198.51.100.20', 'ttl' => '3600'])->jobId !== null, 'Confirmed, a job.');
env_assert($extras->firewall($owner) === null && $operations->planCommand($owner, 'firewall:block', ['address' => '203.0.113.9'])->reason === 'not_allowed'
    && $operations->planCommand($owner, 'panel:access', ['allow' => 'any'])->reason === 'not_allowed', 'The firewall is the administrator\'s.');
$waiting = FirewallStatus::fromData(['active' => true, 'rules' => [], 'blocks' => [['address' => '203.0.113.9', 'until' => '2999-01-01T00:00:00Z', 'created_at' => '2026-11-03T08:53:29Z']],
    'panel_access' => ['domain' => 'panel.example.com', 'allow' => ['192.0.2.10']],
    'pending_change' => ['change_id' => 'c966b67eb35b', 'kind' => 'panel_access', 'domain' => 'panel.example.com', 'state' => 'pending', 'summary' => 'allow 192.0.2.10', 'expires_at' => '2999-01-01T00:00:00Z']]);
env_assert($waiting->pendingOf('panel_access')?->changeId === 'c966b67eb35b' && $waiting->pendingOf('firewall') === null && $waiting->panelAccess === ['192.0.2.10']
    && $waiting->blocks[0]['until'] === '2999-01-01T00:00:00Z' && $waiting->pending?->summary === 'allow 192.0.2.10', 'The one change waiting, of its kind; the panel\'s allow list.');
env_assert(FirewallStatus::fromData(['pending_change' => ['change_id' => 'c966b67eb35b', 'kind' => 'firewall', 'state' => 'restored']])->pending === null, 'An undone change waits no more.');
$done = \Larena\Env\Operations\PanelJobStatus::fromData(['job_id' => 'j1', 'state' => 'succeeded', 'command' => 'firewall:block', 'args' => [],
    'result' => ['change_id' => 'c966b67eb35b', 'kind' => 'firewall', 'expires_at' => '2026-11-03T08:55:29Z']]);
env_assert($done->change?->changeId === 'c966b67eb35b' && $done->change->kind === 'firewall' && $done->change->pending(), 'The job\'s result names the change to keep (job:status result).');
env_assert(\Larena\Env\Operations\PanelJobStatus::fromData(['job_id' => 'j1', 'state' => 'succeeded', 'command' => 'site:deploy', 'args' => [], 'result' => null])->change === null, 'Other jobs have none.');

// Certificates through DNS (simai-env 1.36.0, set 12): the server keeps a Cloudflare token of its own, the administrator's
// alone to give or take back; an owner reads only whether there is one. A certificate through DNS needs it.
foreach (['ssl:dns-credentials', 'ssl:dns-status'] as $command) {
    env_assert(PanelCommands::since($command) === '1.36.0' && (new PanelCapabilities('1.36.0'))->allows($command) && !(new PanelCapabilities('1.35.0'))->allows($command),
        "{$command} from simai-env 1.36.0.");
}
env_assert((new PanelCapabilities('1.36.0'))->takes('ssl:letsencrypt', 'challenge') && (new PanelCapabilities('1.36.0'))->takes('ssl:letsencrypt', 'wildcard')
    && !(new PanelCapabilities('1.35.0'))->takes('ssl:letsencrypt', 'challenge'), 'A certificate through DNS from 1.36.0.');
foreach ([['ssl:dns-credentials', ['provider' => 'cloudflare', 'token' => str_repeat('a', 40)]], ['ssl:dns-credentials', ['provider' => 'cloudflare', 'remove' => 'yes']],
    ['ssl:letsencrypt', ['domain' => 'demo-php.test', 'email' => 'admin@example.com', 'challenge' => 'dns', 'wildcard' => 'yes']]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args)) === null, "Accepted: {$command} ".json_encode($args));
}
foreach ([['ssl:dns-credentials', ['provider' => 'route53', 'token' => str_repeat('a', 40)]], ['ssl:dns-credentials', ['provider' => 'cloudflare', 'token' => 'short']],
    ['ssl:dns-credentials', ['provider' => 'cloudflare', 'token' => str_repeat('a', 39).';']], ['ssl:dns-credentials', ['token' => str_repeat('a', 40)]],
    ['ssl:letsencrypt', ['domain' => 'demo-php.test', 'email' => 'admin@example.com', 'challenge' => 'tls']]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args)) === 'invalid_argument', "Refused: {$command} ".json_encode($args));
}
$dnsPlan = $operations->planCommand($admin, 'ssl:dns-credentials', ['provider' => 'cloudflare', 'token' => str_repeat('a', 40)]);
env_assert($dnsPlan->plan?->command === 'ssl:dns-credentials' && !str_contains(json_encode($dnsPlan->plan, JSON_THROW_ON_ERROR), str_repeat('a', 40)), 'The plan shows the token as stored, never itself.');
env_assert($operations->planCommand($owner, 'ssl:dns-credentials', ['provider' => 'cloudflare', 'remove' => 'yes'])->reason === 'not_allowed', 'An owner cannot give or take the server\'s token.');
env_assert($operations->planCommand($admin, 'ssl:letsencrypt', ['domain' => 'demo-php.test', 'email' => 'admin@example.com', 'challenge' => 'dns'])->plan?->command === 'ssl:letsencrypt', 'A certificate through DNS is planned.');
$noToken = $extras->dnsCertificates($admin);
env_assert($noToken !== null && !$noToken->configured && $noToken->updatedAt === null, 'The recorded server keeps no token yet (simai-ssl-dns/1).');
$withToken = \Larena\Env\Certificates\DnsCertificates::fromData(['schema' => 'simai-ssl-dns/1', 'providers' => [['provider' => 'cloudflare', 'configured' => true, 'updated_at' => '2026-11-03T10:00:00Z']]]);
env_assert($withToken?->configured === true && $withToken->updatedAt === '2026-11-03T10:00:00Z', 'A token kept since then.');
env_assert(\Larena\Env\Certificates\DnsCertificates::fromData(['schema' => 'simai-ssl-dns/2', 'providers' => []]) === null
    && \Larena\Env\Certificates\DnsCertificates::fromData(['schema' => 'simai-ssl-dns/1', 'providers' => [['provider' => 'other', 'configured' => true]]])?->configured !== true,
    'Another schema is not read; another provider is not Cloudflare.');

// The screens on stubs of the admin's layout.
$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, env_temp_directory()), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$overview = (new ServerOverviewReader($gateway))->read($admin)->overview;
$servicesPage = static fn (array $data): string => $factory->make('larena-env::admin.services', $data + ['source' => 'recorded', 'services' => $services,
    'reasonText' => null, 'allows' => ['restart' => true, 'reload' => true, 'log' => true], 'logOf' => null, 'log' => null, 'notice' => null])->render();
$all = $servicesPage(['services' => [...$services, new \Larena\Env\Server\HostService('redis-server', 'failed', 'disabled', null, false)]]);
env_assert(str_contains($all, 'data-env-service="nginx"') && substr_count($all, 'data-env-service-op="restart"') === 5 && substr_count($all, 'data-env-service-op="reload"') === 2
    && str_contains($all, 'data-env-service-state="failed"') && str_contains($all, 'role="alert"') && str_contains($all, 'data-env-service-disabled')
    && substr_count($all, 'data-env-service-since') === 4, 'Each service, its state, restart, and a reread only where it can: '.$all);
$readOnly = $servicesPage(['allows' => ['restart' => false, 'reload' => false, 'log' => false]]);
env_assert(str_contains($readOnly, 'data-env-services-unsupported') && !str_contains($readOnly, 'data-env-service-op') && !str_contains($readOnly, 'data-env-service-log-link'), 'A server without them: the state only.');
env_assert(str_contains($servicesPage(['logOf' => 'nginx', 'log' => ['line <b>']]), 'line &lt;b&gt;'), 'The journal, escaped.');

$firewall = static fn (array $data): string => $factory->make('larena-env::admin.firewall', $data + ['source' => 'recorded', 'supported' => true,
    'status' => $status, 'allows' => ['rule-add' => true, 'rule-remove' => true, 'block' => true, 'unblock' => true, 'access' => true, 'keep' => true],
    'pending' => null, 'now' => new DateTimeImmutable(), 'ttls' => \Larena\Env\Http\Controllers\FirewallController::BLOCK_TTLS, 'address' => '203.0.113.7',
    'addressListed' => true, 'notice' => null])->render();
$walls = $firewall(['pending' => new TimedChange('c966b67eb35b', 'firewall', '2999-01-01T00:00:00Z')]);
env_assert(str_contains($walls, 'data-env-firewall="on"') && str_contains($walls, 'data-env-timed-keep="c966b67eb35b"') && substr_count($walls, 'data-env-firewall-locked') === 3
    && preg_match('/data-env-firewall-rule="dbcc1294"\s+data-env-firewall-protected/', $walls) === 1 && substr_count($walls, 'data-env-firewall-op="rule-remove"') === 1
    && !str_contains($walls, 'data-env-firewall-rule="8da948cb"') && str_contains($walls, 'data-env-firewall-block="203.0.113.7"')
    && str_contains($walls, 'Let in port 3306 (TCP) from 192.0.2.10') && !str_contains($walls, 'value="any"')
    && str_contains($walls, 'data-env-firewall-op="block"'), 'The firewall: its rules, the protected ones locked, a blocked address with the blocks, a change to keep in time: '.$walls);
env_assert(str_contains($firewall(['supported' => false, 'status' => null]), 'data-env-firewall-unsupported'), 'An older server: said, nothing offered.');
$panelPage = static fn (array $data): string => $factory->make('larena-env::admin.panel-access', $data + ['source' => 'recorded', 'supported' => true,
    'status' => $waiting, 'allows' => ['access' => true, 'keep' => true], 'pending' => $waiting->pendingOf('panel_access'),
    'now' => new DateTimeImmutable(), 'address' => '192.0.2.1', 'addressListed' => false, 'ttls' => [], 'notice' => null])->render();
$outside = $panelPage([]);
env_assert(str_contains($outside, 'data-env-panel-access="list"') && str_contains($outside, 'data-env-panel-access-outside') && str_contains($outside, 'data-env-timed-keep=')
    && str_contains($outside, 'data-env-panel-access-everyone') && str_contains($outside, '192.0.2.10'), 'Your address is not in the list: said before anything changes: '.$outside);

// Where the panel answers (simai-env 1.51.0): by IP with its own certificate, a domain attached later, the port moved.
$byIp = \Larena\Env\Panel\PanelAddress::fromData(['schema' => 'simai-panel-address/1', 'ip' => '203.0.113.5', 'addresses' => ['203.0.113.5'], 'port' => 8500,
    'ip_access' => true, 'ip_url' => 'https://203.0.113.5:8500', 'ip_certificate' => ['kind' => 'self-signed', 'sha256' => 'AB:CD:EF', 'expires' => '2036-11-06'],
    'domain' => null, 'domain_ssl' => false, 'domain_url' => null, 'url' => 'https://203.0.113.5:8500']);
env_assert($byIp !== null && $byIp->ipUrlOnPort(9443) === 'https://203.0.113.5:9443' && $byIp->domain === null
    && \Larena\Env\Panel\PanelAddress::fromData(['schema' => 'other']) === null
    && \Larena\Env\Panel\PanelAddress::fromData(['schema' => 'simai-panel-address/1', 'url' => 'javascript:alert(1)'])?->url === null, 'The panel address read, a stray link refused.');
$allAddress = ['domain' => true, 'domain-remove' => true, 'port' => true, 'ip-access' => true];
$ipPage = $panelPage(['panelAddress' => $byIp, 'addressAllows' => $allAddress]);
env_assert(str_contains($ipPage, 'data-env-panel-address="ip"') && str_contains($ipPage, 'AB:CD:EF') && str_contains($ipPage, '203.0.113.5')
    && str_contains($ipPage, 'data-env-panel-address-op="domain"') && str_contains($ipPage, 'data-env-panel-address-op="port"')
    && !str_contains($ipPage, 'data-env-panel-address-op="ip-access"'), 'By IP only: attach a domain, move the port, the address by IP cannot be turned off: '.$ipPage);
$withDomain = \Larena\Env\Panel\PanelAddress::fromData(['schema' => 'simai-panel-address/1', 'ip' => '203.0.113.5', 'port' => 8500, 'ip_access' => true,
    'ip_url' => 'https://203.0.113.5:8500', 'domain' => 'panel.example.com', 'domain_ssl' => true, 'domain_url' => 'https://panel.example.com', 'url' => 'https://panel.example.com']);
$domainPage = $panelPage(['panelAddress' => $withDomain, 'addressAllows' => $allAddress]);
env_assert(str_contains($domainPage, 'data-env-panel-address="domain"') && str_contains($domainPage, 'data-env-panel-address-op="domain-remove"')
    && str_contains($domainPage, 'data-env-panel-address-op="ip-access"') && !str_contains($domainPage, 'data-env-panel-address-op="domain"'), 'A domain with a certificate: detach it or turn the address by IP off: '.$domainPage);
$readOnly = $panelPage(['panelAddress' => $byIp, 'addressAllows' => array_map(static fn (): bool => false, $allAddress)]);
env_assert(str_contains($readOnly, 'data-env-panel-address-url') && !str_contains($readOnly, 'data-env-panel-address-op='), 'Without the right only the address is shown.');
// The server's setup on the Framework's wizard: every step is done inside it (owner 2026-11-06).
$setupText = static fn (string $key): string => $key;
$setupStates = ['login' => 'done', 'address' => 'done', 'alerts' => 'skipped', 'protection' => 'todo', 'backups' => 'todo', 'updates' => 'todo'];
$setupPage = static fn (string $step, string $phase, array $more = []): string => $factory->make('larena-env::admin.setup', $more + [
    'step' => $step, 'phase' => $phase, 'message' => null, 'states' => $setupStates, 'plan' => null, 'job' => null, 'loginUrl' => '/admin/security',
    'overviewUrl' => '/admin/server/overview', 'capabilities' => \Larena\Env\Gateway\PanelCapabilities::none(),
    'wizard' => \Larena\Env\Http\Controllers\ServerSetupController::wizard($step, $phase, $setupStates, $more['message'] ?? null, $setupText),
    'data' => match ($step) { 'protection' => ['level' => 'basic'], default => [] }])->render();
$levelForm = $setupPage('protection', 'form');
env_assert(str_contains($levelForm, '<sf-wizard place="pages"') && str_contains($levelForm, 'current="protection"') && substr_count($levelForm, '<sf-wizard-step ') === 7
    && str_contains($levelForm, 'name="alerts"') && str_contains($levelForm, 'state="skipped"') && str_contains($levelForm, 'type="form"')
    && str_contains($levelForm, 'larena.env.admin.overview.setup.submit') && str_contains($levelForm, '<sf-dropdown name="set"') && str_contains($levelForm, 'later-href="/admin/server/overview"')
    && !str_contains($levelForm, 'href="/admin/server/security"'), 'A step takes its fields inside the wizard, the others are in its progress: '.$levelForm);
$levelPlan = new \Larena\Env\Operations\PanelPlan('security:level', ['set' => 'enhanced'], null, 'Sets the level.', 'firewall, fail2ban', null);
$review = $setupPage('protection', 'review', ['plan' => $levelPlan]);
env_assert(str_contains($review, 'type="review"') && str_contains($review, 'data-env-setup-plan="security:level"') && str_contains($review, 'action-text="plan.confirm"')
    && str_contains($review, 'value="edit"'), 'The server\'s plan in the step, applied from there or changed: '.$review);
$running = $setupPage('protection', 'running', ['job' => 'job-1']);
env_assert(str_contains($running, 'state="running"') && str_contains($running, 'data-env-setup-job') && str_contains($running, 'type="action"'), 'A run in the step: '.$running);
$setupFailed = $setupPage('protection', 'error', ['job' => 'job-1', 'message' => 'It failed.']);
env_assert(str_contains($setupFailed, 'state="error"') && str_contains($setupFailed, 'message="It failed."'), 'A failed run says so and offers to try again.');
$finish = $setupPage('finish', 'info');
env_assert(str_contains($finish, 'data-env-setup-summary-step="alerts"') && str_contains($finish, 'current="finish"'), 'The result lists what is done and put off.');
// Every view of the panel compiles to valid PHP (critics 2026-11-07: a view that never rendered in a test failed on the stand).
$compiler = new BladeCompiler($files, env_temp_directory());
$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views', FilesystemIterator::SKIP_DOTS));
$compiled = 0;
foreach ($views as $view) {
    if (!str_ends_with($view->getFilename(), '.blade.php')) {
        continue;
    }
    try {
        token_get_all($compiler->compileString((string) file_get_contents($view->getPathname())), TOKEN_PARSE);
    } catch (ParseError $error) {
        throw new RuntimeException('The view '.$view->getPathname().' does not compile: '.$error->getMessage());
    }
    $compiled++;
}
env_assert($compiled > 40, 'Every view is compiled: '.$compiled);
// Every step renders, in its fields and once done (an unparsable view once reached the stand: /setup/alerts, 2026-11-07).
$stepData = ['login' => [], 'address' => ['address' => null], 'alerts' => ['channel' => ['kind' => 'telegram', 'target' => '12345'], 'canTest' => true],
    'protection' => ['level' => 'basic'], 'backups' => ['storages' => []], 'updates' => ['plans' => ['env' => null, 'panel' => null], 'canUpdate' => true]];
foreach ($stepData as $stepName => $data) {
    foreach (['form', 'info'] as $stepPhase) {
        foreach (['todo', 'done'] as $stepState) {
            $states = [$stepName => $stepState] + $setupStates;
            $html = $factory->make('larena-env::admin.setup', ['step' => $stepName, 'phase' => $stepPhase, 'message' => null, 'states' => $states, 'plan' => null, 'job' => null,
                'loginUrl' => '/admin/security', 'overviewUrl' => '/admin/server/overview', 'capabilities' => \Larena\Env\Gateway\PanelCapabilities::none(),
                'wizard' => \Larena\Env\Http\Controllers\ServerSetupController::wizard($stepName, $stepPhase, $states, null, $setupText), 'data' => $data])->render();
            env_assert(str_contains($html, 'current="'.$stepName.'"'), "The step {$stepName} renders ({$stepPhase}, {$stepState}).");
        }
    }
}
$alertsForm = $setupPage('alerts', 'form', ['data' => ['channel' => null, 'canTest' => false]]);
env_assert(str_contains($alertsForm, '<sf-dropdown name="kind"') && str_contains($alertsForm, '&quot;value&quot;:&quot;telegram&quot;'), 'The alerts step offers its channels: '.$alertsForm);
$moving = $factory->make('larena-env::admin.panel-address-moving', ['op' => 'port', 'port' => 9443, 'next' => 'https://203.0.113.5:9443/admin/server/jobs/x', 'nextBase' => 'https://203.0.113.5:9443'])->render();
env_assert(str_contains($moving, 'data-env-panel-address-moving="port"') && str_contains($moving, '9443') && str_contains($moving, 'data-env-panel-address-next'), 'The way to the new address: '.$moving);

$cronTab = static fn (array $cron): string => $factory->make('larena-env::admin.partials.cron', ['domain' => 'demo-php.test', 'cron' => $cron + ['jobs' => null,
    'opened' => null, 'runs' => null, 'allows' => ['add' => true, 'remove' => true, 'enable' => true, 'disable' => true, 'runs' => true],
    'presets' => CronSchedule::PRESETS, 'when' => []]])->render();
$jobsNow = $extras->cronJobs($admin, 'demo-owned.test');
$tab = $cronTab(['jobs' => $jobsNow, 'when' => ['a1b2c3d4' => 'Every 10 minutes'], 'opened' => $jobsNow[0], 'runs' => $extras->cronRuns($admin, 'a1b2c3d4')]);
env_assert(str_contains($tab, 'data-env-cron-job="a1b2c3d4"') && str_contains($tab, 'Every 10 minutes') && str_contains($tab, 'data-env-cron-last="ok"')
    && str_contains($tab, 'data-env-cron-op="disable"') && !str_contains($tab, 'data-env-cron-op="enable"') && str_contains($tab, 'data-env-cron-runs="a1b2c3d4"')
    && str_contains($tab, 'Synced 12 records.') && str_contains($tab, 'data-env-cron-op="add"') && str_contains($tab, 'value="custom"')
    && str_contains($tab, 'data-env-cron-hint') && str_contains($tab, 'the next one is skipped'), 'The scheduler tab: '.$tab);
$full = $cronTab(['jobs' => array_fill(0, CronSchedule::MAX_JOBS, $jobsNow[0])]);
env_assert(str_contains($full, 'data-env-cron-full') && !str_contains($full, 'data-env-cron-op="add"'), 'A site with 50 jobs says so instead of offering one more.');
$failedRun = $cronTab(['jobs' => [\Larena\Env\Cron\CronJob::fromData(['id' => 'b2c3d4e5', 'schedule' => '0 3 * * *', 'command' => 'x', 'enabled' => false,
    'last_run' => ['started_at' => '2026-01-01T03:00:00Z', 'finished_at' => '2026-01-01T03:00:01Z', 'exit_code' => 1]])]]);
env_assert(str_contains($failedRun, 'data-env-cron-last="failed"') && str_contains($failedRun, 'data-env-cron-op="enable"'), 'A failed run, and a job turned off.');
env_assert(str_contains($cronTab(['jobs' => []]), 'data-env-cron-none') && str_contains($cronTab([]), 'data-env-cron="unreadable"'), 'No jobs, or none readable, said so.');

$appliedView = $operations->view($admin, (string) $operations->startCommand($admin, 'firewall:block', ['address' => '198.51.100.20', 'ttl' => '3600'])->jobId);
$job = $factory->make('larena-env::admin.job', ['jobId' => 'fixture', 'source' => 'recorded', 'status' => $appliedView->status, 'log' => $appliedView->log,
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

$bitrixWizard = $factory->make('larena-env::admin.new-site', ['profiles' => ['bitrix', 'proxy'], 'phpProfiles' => [], 'phpVersions' => ['8.3'], 'owners' => null,
    'old' => [], 'notice' => null, 'kind' => 'bitrix', 'dailyBackup' => true, 'serverProfiles' => [
        'bitrix' => ['label' => '1C-Bitrix', 'hint' => 'Bitrix.', 'groups' => ['php', 'database', 'bitrix'], 'backup' => true],
        'proxy' => ['label' => 'App on a port', 'hint' => 'A port.', 'groups' => ['proxy'], 'backup' => false]]])->render();
env_assert(str_contains($bitrixWizard, 'data-env-field="bitrix-files"') && substr_count($bitrixWizard, '<option value="setup"') === 1 && str_contains($bitrixWizard, 'restore.php')
    && str_contains($bitrixWizard, 'data-env-new-daily-backup') && !str_contains($bitrixWizard, 'name="upstream-port"'), 'A 1C-Bitrix site asks for its installer: '.$bitrixWizard);
$proxyWizard = $factory->make('larena-env::admin.new-site', ['profiles' => ['bitrix', 'proxy'], 'phpProfiles' => [], 'phpVersions' => ['8.3'], 'owners' => null,
    'old' => [], 'notice' => null, 'kind' => 'proxy', 'dailyBackup' => true, 'serverProfiles' => [
        'bitrix' => ['label' => '1C-Bitrix', 'hint' => 'Bitrix.', 'groups' => ['php', 'database', 'bitrix'], 'backup' => true],
        'proxy' => ['label' => 'App on a port', 'hint' => 'A port.', 'groups' => ['proxy'], 'backup' => false]]])->render();
env_assert(str_contains($proxyWizard, 'name="upstream-port"') && !str_contains($proxyWizard, 'data-env-new-daily-backup') && !str_contains($proxyWizard, 'bitrix-files'),
    'An app on a port holds nothing to back up.');
$brokenJob = $factory->make('larena-env::admin.job', ['jobId' => 'j1', 'source' => 'recorded', 'status' => $failed, 'log' => null, 'refused' => false, 'reasonText' => null,
    'notice' => null, 'change' => null, 'configFailed' => 'nginx'])->render();
env_assert(str_contains($brokenJob, 'data-env-job-config-failed="nginx"') && str_contains($brokenJob, 'keeps running as it was'), 'A broken configuration said in words: '.$brokenJob);

// The server's PHP versions (simai-env 1.37.0, set 13): read, each change planned and confirmed, the administrator's alone.
foreach (['php:list', 'php:install', 'php:remove', 'php:extension', 'php:update'] as $command) {
    env_assert(PanelCommands::since($command) === '1.37.0' && (new PanelCapabilities('1.37.0'))->allows($command) && !(new PanelCapabilities('1.36.0'))->allows($command),
        "{$command} from simai-env 1.37.0.");
}
$php = $extras->phpVersions($admin);
env_assert($php !== null && $php->native === '8.3' && count($php->versions) === 1 && $php->versions[0]->version === '8.3' && $php->versions[0]->fpmActive
    && $php->versions[0]->native && $php->versions[0]->cliDefault && $php->versions[0]->sites === ['demo-owned.test', 'demo-php.test']
    && $php->versions[0]->packageVersion === '8.3.6-0ubuntu0.24.04.13' && $php->versions[0]->updates === [] && !$php->versions[0]->removable
    && $php->installable === [], 'The server\'s PHP (simai-php/1).');
foreach ([['php:install', ['php' => '8.4', 'include-common' => 'yes']], ['php:remove', ['php' => '8.4']], ['php:update', ['php' => '8.3']],
    ['php:extension', ['php' => '8.3', 'name' => 'imagick', 'enabled' => 'yes']]] as [$command, $args]) {
    env_assert($operations->planCommand($admin, $command, $args)->plan?->command === $command, "{$command} is planned first.");
}
env_assert($operations->planCommand($admin, 'php:extension', ['php' => '8.3', 'name' => 'Bad-Name', 'enabled' => 'yes'])->reason === 'invalid_argument', 'An extension\'s name is checked.');
env_assert($extras->phpVersions($owner) === null && $operations->planCommand($owner, 'php:install', ['php' => '8.4'])->reason === 'not_allowed', 'An owner cannot see or change the server\'s PHP.');
$phpPage = static fn (array $data): string => $factory->make('larena-env::admin.software-php', $data + ['source' => 'recorded', 'php' => $php, 'reasonText' => null,
    'allows' => ['install' => true, 'update' => true, 'remove' => true, 'extension' => true], 'extensionsOf' => null, 'extensions' => null, 'notice' => null])->render();
$two = new \Larena\Env\Software\PhpVersions('8.3', [...$php->versions, new \Larena\Env\Software\PhpVersion('8.4', false, [], false, false, '8.4.1', ['php8.4-fpm'], true)], ['8.5']);
$page = $phpPage(['php' => $two]);
env_assert(str_contains($page, 'data-env-php-version="8.3"') && str_contains($page, 'data-env-php-version="8.4"') && substr_count($page, 'data-env-php-op="remove"') === 1
    && substr_count($page, 'data-env-php-op="update"') === 2 && str_contains($page, 'data-env-php-updates="1"') && str_contains($page, 'data-env-php-native')
    && str_contains($page, 'PHP-FPM is stopped') && str_contains($page, '<option value="8.5">') && str_contains($page, 'data-env-php-op="install"'),
    'Each version, its sites and fixes, removal only where free, and the install form: '.$page);
env_assert(str_contains($phpPage([]), 'data-env-php-installable="none"'), 'Nothing left to install, said in words.');
$readOnlyPhp = $phpPage(['allows' => ['install' => false, 'update' => false, 'remove' => false, 'extension' => false]]);
env_assert(!str_contains($readOnlyPhp, 'data-env-php-op') && str_contains($readOnlyPhp, 'data-env-php-extensions-link'), 'Without the right, the state only.');
$withExtensions = $phpPage(['extensionsOf' => '8.3', 'extensions' => [new \Larena\Env\Server\PhpExtension('curl', true), new \Larena\Env\Server\PhpExtension('imagick', false)]]);
env_assert(str_contains($withExtensions, 'data-env-php-extensions="8.3"') && str_contains($withExtensions, 'name="enabled" value="no"')
    && str_contains($withExtensions, 'name="enabled" value="yes"') && str_contains($withExtensions, 'Turn on imagick in PHP 8.3'), 'Extensions turned on or off: '.$withExtensions);
env_assert(str_contains($phpPage(['php' => null, 'reasonText' => 'No PHP <b>']), 'No PHP &lt;b&gt;'), 'A server without it says so.');

// The server's software and the system's updates (simai-env 1.38.0, set 14): read, each change planned and confirmed, the administrator's alone.
foreach (['software:list', 'software:refresh', 'software:update', 'software:install'] as $command) {
    env_assert(PanelCommands::since($command) === '1.38.0' && (new PanelCapabilities('1.38.0'))->allows($command) && !(new PanelCapabilities('1.37.0'))->allows($command),
        "{$command} from simai-env 1.38.0.");
}
$software = $extras->serverSoftware($admin);
env_assert($software !== null && array_map(static fn ($component): string => $component->name, $software->components) === ['nginx', 'apache', 'certbot', 'fail2ban', 'mysql', 'postgresql', 'redis', 'memcached', 'php', 'node', 'composer', 'git', 'imagemagick', 'jpegoptim', 'optipng', 'ffmpeg', 'supervisor']
    && $software->component('nginx')?->active === true && !$software->component('nginx')->installable && $software->component('redis')?->installable === true
    && $software->system->updates === 5 && $software->system->security === 4 && count($software->system->packages) === 5 && !$software->system->rebootRequired
    && $software->system->indexAgeMinutes() === 185, 'The server\'s software (simai-software/1).');
// simai-env 1.43.0's catalogue: each row's group, what it is for, and what the server would do to it or why not.
$certbot = $software->component('certbot');
$mysql = $software->component('mysql');
env_assert($certbot?->canRemove === true && $certbot->group === 'security' && $certbot->keeps === ['/etc/letsencrypt'] && !$certbot->installable
    && $mysql?->requiredByEnv() === true && $mysql->sites === ['demo-php.test'] && !$mysql->canRemove
    && $software->component('php')?->managedElsewhere() === true && $software->component('memcached')?->installable === true && $software->component('memcached')->purpose !== null,
    'The catalogue\'s rows.');
env_assert(PanelCommands::since('software:remove') === '1.43.0' && !(new PanelCapabilities('1.42.0'))->allows('software:remove')
    && $operations->planCommand($admin, 'software:remove', ['name' => 'memcached'])->plan?->command === 'software:remove'
    && $operations->planCommand($admin, 'software:remove', ['name' => 'nginx'])->reason === 'invalid_argument', 'A removal is planned first, and never of nginx.');
// A row from a server before the catalogue still reads, by its fixed list.
$old = \Larena\Env\Software\SoftwareComponent::fromData(['name' => 'node', 'installed' => false, 'installable' => true]);
env_assert($old?->installable === true && $old->group === null && \Larena\Env\Software\SoftwareComponent::fromData(['name' => 'memcached', 'installable' => true]) === null,
    'A server before the catalogue: its fixed list, software:install for node, redis and postgresql.');
$systemPlan = $operations->planCommand($admin, 'software:update', ['security-only' => 'yes'])->plan;
env_assert($systemPlan?->system?->security === 4 && $systemPlan->update === null, 'A system update\'s plan names its packages.');
env_assert($operations->planCommand($admin, 'software:install', ['name' => 'redis'])->plan?->command === 'software:install', 'A component is planned first.');
env_assert($operations->planCommand($admin, 'software:install', ['name' => 'nginx'])->reason === 'invalid_argument'
    && $operations->planCommand($admin, 'software:install', ['name' => 'node', 'version' => '2x'])->reason === 'invalid_argument', 'Only what software:install adds, in a two-digit major.');
env_assert($extras->serverSoftware($owner) === null && $operations->planCommand($owner, 'software:update', [])->reason === 'not_allowed', 'An owner cannot see or change the server\'s software.');
$softwarePage = static fn (array $data): string => $factory->make('larena-env::admin.software', $data + ['source' => 'recorded', 'software' => $software, 'system' => $software->system,
    'reasonText' => null, 'allows' => ['refresh' => true, 'update' => true, 'install' => true, 'upgrade' => true, 'remove' => true], 'notice' => null])->render();
$page = $softwarePage([]);
env_assert(str_contains($page, 'data-env-system-updates="5"') && str_contains($page, 'data-env-system-security-count="4"') && substr_count($page, 'data-env-system-security ') + substr_count($page, 'data-env-system-security>') === 4
    && str_contains($page, 'data-env-software-op="update-security"') && str_contains($page, 'data-env-software-op="update-all"') && str_contains($page, 'data-env-software-op="refresh"')
    && str_contains($page, 'data-env-software-op="install-redis"') && str_contains($page, 'data-env-software-op="install-node"') && !str_contains($page, 'data-env-software-op="install-nginx"')
    && str_contains($page, 'List checked 3 h ago') && substr_count($page, 'data-env-software-component=') === 16, 'The components and the system\'s updates: '.$page);
// simai-env's and the panel's updates head the same screen (the panel's UX audit): one place for software and updates.
$joined = $softwarePage(['supported' => false, 'plans' => ['env' => null, 'panel' => null], 'canUpdate' => false, 'panelBuild' => null]);
env_assert(str_contains($joined, 'data-env-software-updates') && str_contains($joined, 'data-env-update="unsupported"')
    && strpos($joined, 'data-env-software-updates') < strpos($joined, 'data-env-system-updates='), 'The updates of simai-env and the panel come first: '.$joined);
env_assert(!str_contains($page, 'data-env-software-updates aria'), 'Without the update\'s state, no empty block.');
// One table of programs grouped by what they do, PHP as its row, the updates in one line (the panel's UX audit).
env_assert(str_contains($page, 'data-env-software-table') && substr_count($page, 'data-env-software-group=') === 6 && !str_contains($page, 'data-env-software-php=')
    && str_contains($page, 'data-env-software-op="remove-certbot"') && !str_contains($page, 'data-env-software-op="remove-nginx"') && str_contains($page, 'data-env-software-op="install-memcached"')
    && substr_count($page, 'data-env-software-required') === 4 && str_contains($page, 'data-env-software-filter="updates"')
    && str_contains($page, 'data-env-software-update-badge="system"') && preg_match('/<details\s+open\s+data-env-software-updates-details/', $page) !== 1
    && str_contains($page, 'data-env-software-op="update-nginx"') === ($software->component('nginx')?->update === true), 'The programs\' table: '.$page);
$withPhpRow = $softwarePage(['withPhp' => true, 'phpRow' => \Larena\Env\Software\SoftwareScreen::php($extras->phpVersions($admin))]);
env_assert(preg_match('/data-env-software-php="[1-9]/', $withPhpRow) === 1 && str_contains($withPhpRow, 'data-env-software-php-open')
    && strpos($withPhpRow, 'data-env-software-group="languages"') < strpos($withPhpRow, 'data-env-software-php='), 'PHP is a row among the languages: '.$withPhpRow);
// The filters and the search leave their rows, and say when none is left.
$installedOnly = $softwarePage(['filter' => 'installed']);
env_assert(substr_count($installedOnly, 'data-env-software-component=') === count(array_filter($software->components, static fn ($component): bool => $component->installed && $component->name !== 'php'))
    && !str_contains($installedOnly, 'data-env-software-op="install-') && str_contains($installedOnly, 'aria-current="page"'), 'Only the installed programs: '.$installedOnly);
$found = $softwarePage(['query' => 'Мемк']);
env_assert(str_contains($softwarePage(['query' => 'memc']), 'data-env-software-component="memcached"') && substr_count($softwarePage(['query' => 'memc']), 'data-env-software-component=') === 1
    && str_contains($found, 'data-env-software-none') && str_contains($softwarePage(['query' => 'memc']), 'q=memc'), 'The search finds by name and keeps itself in the filters\' links.');
$quiet = new \Larena\Env\Software\SystemUpdates(0, 0, [], [], false, [], null);
env_assert(preg_match('/<details\s+open\s+data-env-software-updates-details/', $softwarePage(['software' => new \Larena\Env\Software\ServerSoftware($software->components, $quiet), 'system' => $quiet])) !== 1,
    'Nothing waits: the updates stay folded.');
env_assert(\Larena\Env\Software\SoftwareScreen::shortVersion('1.24.0-2ubuntu7.19') === '1.24.0' && \Larena\Env\Software\SoftwareScreen::shortVersion('5:7.0.15-1ubuntu0.24.04.5') === '7.0.15'
    && \Larena\Env\Software\SoftwareScreen::shortVersion('16+257build1.1') === '16' && \Larena\Env\Software\SoftwareScreen::shortVersion(null) === '—', 'Versions said as people say them.');
env_assert(\Larena\Env\Software\SoftwareScreen::updateStates(['env' => null]) === ['env' => 'unknown'], 'An update not read is unknown.');
$readOnlySoftware = $softwarePage(['allows' => ['refresh' => false, 'update' => false, 'install' => false]]);
env_assert(!str_contains($readOnlySoftware, 'data-env-software-op') && str_contains($readOnlySoftware, 'data-env-system-package="apt"'), 'Without the right, the state only.');
env_assert(str_contains($softwarePage(['software' => null, 'system' => null, 'reasonText' => 'No list <b>']), 'No list &lt;b&gt;'), 'A server without it says so.');
$withReboot = new \Larena\Env\Software\SystemUpdates(0, 0, [], ['linux-image'], true, ['linux-image-6.8'], null);
$rebootPage = $softwarePage(['software' => new \Larena\Env\Software\ServerSoftware([], $withReboot), 'system' => $withReboot]);
env_assert(str_contains($rebootPage, 'data-env-system-reboot') && str_contains($rebootPage, 'linux-image-6.8') && str_contains($rebootPage, 'data-env-system-kept-back')
    && !str_contains($rebootPage, 'data-env-software-op="update-all"'), 'A pending reboot and kept-back packages said in words, nothing to install: '.$rebootPage);

foreach (['services', 'firewall', 'panel-access', 'software-php', 'software', 'partials/system-updates', 'partials/cron', 'partials/timed-change', 'panel-address-moving', 'setup', 'setup/plan', 'setup/login', 'setup/address', 'setup/alerts', 'setup/protection', 'setup/backups', 'setup/updates'] as $name) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$name.'.blade.php');
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "The {$name} view prints nothing raw and holds no script.");
}

// An explanation shows one line of sense; the rest folds under «Подробнее» (the panel's UX audit, COPY-01).
[$line, $rest] = \Larena\Env\Admin\Explanation::split('Файлы читаются и меняются от имени пользователя сайта. Ссылки наружу сервер не открывает, а файл больше 1 МБ показывается только для скачивания.');
env_assert($line === 'Файлы читаются и меняются от имени пользователя сайта.' && str_starts_with((string) $rest, 'Ссылки наружу'), 'A long explanation folds after its first sentence.');
env_assert(\Larena\Env\Admin\Explanation::split('Короткое пояснение. Второе.') === ['Короткое пояснение. Второе.', null]
    && \Larena\Env\Admin\Explanation::split(str_repeat('слово ', 30).'конец') [1] === null, 'A short one or one without a sentence stays whole.');
$folded = $factory->make('larena-env::admin.partials.explanation', ['text' => 'Файлы читаются и меняются от имени пользователя сайта. Ссылки наружу сервер не открывает, а файл больше 1 МБ показывается только для скачивания.'])->render();
env_assert(str_contains($folded, 'data-env-explanation-more') && str_contains($folded, '<summary'), 'The rest is under a summary: '.$folded);

// The server's security (simai-env 1.45.0–1.47.0, set 25): read, each change planned and confirmed, the administrator's alone.
foreach (['security:status' => '1.45.0', 'security:scan' => '1.45.0', 'security:fix' => '1.45.0', 'self:firewall-ensure' => '1.45.0', 'security:files' => '1.46.0',
    'security:quarantine' => '1.46.0', 'security:quarantine-restore' => '1.46.0', 'security:level' => '1.47.0', 'security:web-protect' => '1.47.0'] as $command => $since) {
    env_assert(PanelCommands::since($command) === $since, "{$command} from simai-env {$since}.");
}
foreach ([['security:fix', ['check' => 'fail2ban']], ['security:level', ['set' => 'enhanced']], ['security:web-protect', ['enabled' => 'yes', 'rate' => '30', 'burst' => '300']],
    ['security:quarantine', ['domain' => 'demo-php.test', 'path' => 'public/shell.php']], ['security:quarantine-restore', ['id' => '3f2a9c1d4e5b']]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args, true)) === null, "Accepted: {$command} ".json_encode($args));
}
foreach ([['security:fix', ['check' => 'everything']], ['security:level', ['set' => 'paranoid']], ['security:web-protect', ['enabled' => 'yes', 'rate' => '1']],
    ['security:quarantine', ['domain' => 'demo-php.test', 'path' => '../etc/passwd']], ['security:quarantine', ['domain' => 'demo-php.test', 'path' => '/etc/passwd']]] as [$command, $args]) {
    env_assert($refusedWith(static fn () => PanelCommands::check($command, $args, true)) === 'invalid_argument', "Refused: {$command} ".json_encode($args));
}
$security = $extras->security($admin);
env_assert($security !== null && $security->score === 45 && $security->grade === 'risk' && $security->summary === ['risk' => 1, 'warn' => 12, 'ok' => 4, 'unknown' => 1]
    && $security->checks[0]->id === 'security_updates' && $security->checks[0]->status === 'risk' && count($security->problems()) === 13 && count($security->rest()) === 5
    && $security->check('fail2ban')?->fixCommand === 'security:fix' && $security->check('fail2ban')->fixArgs === ['check' => 'fail2ban'] && $security->level === null,
    'The recorded security (simai-security/1): score, grade, problems first.');
env_assert(\Larena\Env\Security\SecurityStatus::fromData(['schema' => 'simai-security/2', 'checks' => []]) === null, 'Another schema is not read.');
$scan = $extras->fileScan($admin);
env_assert($scan !== null && $scan->checkedAt === null && $scan->foundCount() === 0 && $extras->quarantine($admin)?->files === [], 'Nothing checked or kept yet (set 25).');
$found = \Larena\Env\Security\FileScan::fromData(['schema' => 'simai-file-scan/1', 'checked_at' => '2026-11-06T03:00:00Z', 'engines' => ['simai', 'clamav'],
    'sites' => ['demo-php.test' => ['scanned' => 120, 'found' => [['path' => 'public/up.php', 'signature' => 'php.eval-base64', 'engine' => 'simai', 'size' => 512]], 'error' => null]]]);
$kept = \Larena\Env\Security\Quarantine::fromData(['schema' => 'simai-quarantine/1', 'files' => [['id' => 'q1', 'domain' => 'demo-php.test', 'path' => 'public/old.php', 'state' => 'quarantined', 'quarantined_at' => '2026-11-06T03:10:00Z'],
    ['id' => 'q2', 'domain' => 'demo-php.test', 'path' => 'public/ok.php', 'state' => 'restored', 'restored_at' => '2026-11-06T04:00:00Z']]]);
env_assert($found?->foundCount() === 1 && $kept !== null && count($kept->kept()) === 1, 'A suspicious file and the quarantine.');
$translator = new class ($lines) implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines) {}
    public function get($key, array $replace = [], $locale = null): mixed
    {
        $value = data_get($this->lines, substr($key, strlen('larena-env::admin.')));

        return is_string($value) ? $value : $key;
    }
    public function choice($key, $number, array $replace = [], $locale = null): string { return (string) $this->get($key, $replace); }
    public function getLocale(): string { return 'en'; }
    public function setLocale($locale): void {}
};
$securityController = new \Larena\Env\Http\Controllers\SecurityController($operations, new ServerOverviewReader($gateway), $extras, $factory, $translator);
$words = $securityController->words($security);
env_assert($words['security_updates']['title'] === 'Security updates' && $words['fail2ban']['line'] === 'Nobody blocks password guessing.'
    && $words['file_scan']['line'] === 'The sites\' files were not checked yet.' && $words['https:demo-php.test']['how'] !== '', 'Each check in the panel\'s words.');
$securityPage = static fn (array $data): string => $factory->make('larena-env::admin.security', $data + ['source' => 'recorded', 'supported' => true, 'status' => $security,
    'words' => $words, 'engines' => ['simai-env signatures', 'ClamAV antivirus'], 'fixes' => ['security_updates' => true, 'fail2ban' => true, 'https:demo-php.test' => false],
    'allows' => ['fix' => true, 'scan' => true, 'level' => true, 'files-scan' => true, 'quarantine' => true, 'restore' => true, 'geo' => true, 'geo-update' => true,
        'mcp-enable' => true, 'mcp-disable' => true, 'mcp-key-add' => true, 'mcp-key-remove' => true],
    'levels' => \Larena\Env\Security\SecurityStatus::LEVELS, 'files' => $found, 'quarantine' => $kept, 'filesSupported' => true, 'notice' => null,
    'geo' => null, 'geoSupported' => false, 'lookup' => null, 'lookupSupported' => false, 'countries' => [], 'myAddress' => '203.0.113.7',
    'mcp' => null, 'mcpSupported' => false, 'owners' => []])->render();
$page = $securityPage([]);
env_assert(str_contains($page, 'data-env-security="risk"') && str_contains($page, 'data-env-security-score="45"') && str_contains($page, 'data-env-security-problems="13"')
    && str_contains($page, 'data-env-security-fix="security_updates"') && str_contains($page, 'data-env-security-fix="fail2ban"') && !str_contains($page, 'data-env-security-fix="https:demo-php.test"')
    && str_contains($page, 'data-env-security-open="https:demo-php.test"') && str_contains($page, 'data-env-security-level-card="enhanced"') && substr_count($page, 'data-env-security-op="level-') === 3
    && str_contains($page, 'data-env-security-found="demo-php.test/public/up.php"') && str_contains($page, 'data-env-security-quarantine="1"') && substr_count($page, 'data-env-security-op="restore"') === 1
    && str_contains($page, 'Nobody blocks password guessing.') && !str_contains($page, 'nobody blocks password guessing'), 'The security page: '.$page);
$readOnlySecurity = $securityPage(['fixes' => [], 'allows' => ['fix' => false, 'scan' => false, 'level' => false, 'files-scan' => false, 'quarantine' => false, 'restore' => false, 'geo' => false, 'geo-update' => false]]);
env_assert(!str_contains($readOnlySecurity, 'data-env-security-fix=') && !str_contains($readOnlySecurity, 'data-env-security-op=') && str_contains($readOnlySecurity, 'data-env-security-how'),
    'Without the right to change the server: what to do, no buttons.');
env_assert(str_contains($securityPage(['supported' => false, 'status' => null]), 'data-env-security="unsupported"') && str_contains($securityPage(['status' => null]), 'data-env-security="unreadable"'),
    'An older server or no answer says so.');

// Country blocking (simai-env 1.48.0): the recorded server has it off and no database yet; the block shows the form with
// every country to tick, the visitor's own address among those never dropped, and the database licence's line.
$geo = $extras->geo($admin);
env_assert($geo instanceof \Larena\Env\Security\GeoStatus && $geo->mode === 'off' && $geo->countries === [] && !$geo->databasePresent
    && $geo->attribution === 'IP to country data by DB-IP (https://db-ip.com), CC BY 4.0', 'The recorded country blocking.');
env_assert(\Larena\Env\Security\GeoStatus::codes('ru, kz,RU,xyz') === ['RU', 'KZ'] && \Larena\Env\Security\GeoStatus::flag('RU') === "\u{1F1F7}\u{1F1FA}",
    'Country codes and flags.');
$countryNames = array_combine(\Larena\Env\Security\GeoStatus::COUNTRIES, \Larena\Env\Security\GeoStatus::COUNTRIES);
$geoPage = $securityPage(['geo' => $geo, 'geoSupported' => true, 'lookupSupported' => true, 'countries' => $countryNames,
    'lookup' => ['ip' => '8.8.8.8', 'country' => 'US', 'valid' => true]]);
env_assert(str_contains($geoPage, 'data-env-security-geo="off"') && substr_count($geoPage, 'name="countries[]"') === 249 && str_contains($geoPage, 'data-env-security-op="geo"')
    && str_contains($geoPage, 'value="203.0.113.7" inputmode') && str_contains($geoPage, 'data-env-security-geo-attribution>IP to country data by DB-IP')
    && str_contains($geoPage, 'data-env-security-geo-answer="US"') && !str_contains($geoPage, 'data-env-security-op="geo-update"'), 'The countries block: '.$geoPage);
$on = \Larena\Env\Security\GeoStatus::fromData(['schema' => 'simai-geo/1', 'mode' => 'deny', 'countries' => ['CN', 'KP'], 'ports' => 'web', 'active' => true,
    'dropped_packets' => 1520, 'database' => ['present' => true, 'date' => '2026-11'], 'attribution' => '']);
$onPage = $securityPage(['geo' => $on, 'geoSupported' => true, 'countries' => $countryNames]);
env_assert($on->attribution === \Larena\Env\Security\GeoStatus::ATTRIBUTION && str_contains($onPage, 'data-env-security-geo="deny"') && str_contains($onPage, 'value="CN" checked')
    && str_contains($onPage, 'data-env-security-op="geo-update"') && str_contains($onPage, '1 520'), 'Blocking on: its countries ticked, the count, the update: '.$onPage);
env_assert(!str_contains($securityPage(['geo' => $on, 'geoSupported' => true, 'countries' => $countryNames, 'allows' => ['fix' => false, 'scan' => false, 'level' => false,
    'files-scan' => false, 'quarantine' => false, 'restore' => false, 'geo' => false, 'geo-update' => false]]), 'name="countries[]"'), 'Without the right, no form.');
// The form's arguments: the ticked countries joined, off alone, a list asked for without a country refused.
$geoArgs = new \ReflectionMethod(\Larena\Env\Http\Controllers\SecurityController::class, 'geoArgs');
$asked = static fn (array $input): ?array => $geoArgs->invoke(null, \Illuminate\Http\Request::create('/', 'POST', $input));
env_assert($asked(['mode' => 'deny', 'countries' => ['cn', 'KP', 'ZZ'], 'ports' => 'all', 'keep' => "203.0.113.7\n198.51.100.0/24"])
        === ['mode' => 'deny', 'countries' => 'CN,KP', 'ports' => 'all', 'keep' => '203.0.113.7,198.51.100.0/24']
    && $asked(['mode' => 'off', 'countries' => ['CN']]) === ['mode' => 'off'] && $asked(['mode' => 'allow', 'countries' => []]) === null
    && $asked(['mode' => 'allow', 'countries' => 'RU,KZ']) === ['mode' => 'allow', 'countries' => 'RU,KZ', 'ports' => 'web'], 'The country blocking form\'s arguments.');
\Larena\Env\Gateway\PanelCommands::check('security:geo', ['mode' => 'deny', 'countries' => 'CN,KP', 'ports' => 'web', 'keep' => '203.0.113.7'], true);

// Access for AI assistants over MCP (simai-env 1.49.0): off offers to turn it on; on lists the keys with whom each works
// for, removes one by its fingerprint, adds a key for an owner's sites or the whole server, and shows the lines to connect.
$mcpOff = \Larena\Env\Security\McpStatus::fromData(['schema' => 'simai-mcp-status/1', 'enabled' => false, 'account' => 'simai-mcp', 'keys' => [], 'connect' => null, 'clients' => []]);
$mcpOffPage = $securityPage(['mcp' => $mcpOff, 'mcpSupported' => true]);
env_assert(str_contains($mcpOffPage, 'data-env-security-mcp="off"') && str_contains($mcpOffPage, 'data-env-security-op="mcp-enable"') && !str_contains($mcpOffPage, 'name="pubkey"'),
    'MCP off: only turning it on. '.$mcpOffPage);
$mcpOn = \Larena\Env\Security\McpStatus::fromData(['schema' => 'simai-mcp-status/1', 'enabled' => true, 'account' => 'simai-mcp',
    'keys' => [['role' => 'administrator', 'owner' => null, 'fingerprint' => 'SHA256:abcdefgh12345678'], ['role' => 'owner', 'owner' => 'acme', 'fingerprint' => 'SHA256:zyxwvuts87654321'],
        ['role' => 'owner', 'owner' => 'acme', 'fingerprint' => 'not a fingerprint']],
    'connect' => 'ssh -T simai-mcp@server.example.com',
    'clients' => ['claude_code' => 'claude mcp add simai -- ssh -T simai-mcp@server.example.com', 'codex' => 'codex mcp add simai -- ssh -T simai-mcp@server.example.com',
        'json' => ['mcpServers' => ['simai' => ['command' => 'ssh', 'args' => ['-T', 'simai-mcp@server.example.com']]]]]]);
$mcpPage = $securityPage(['mcp' => $mcpOn, 'mcpSupported' => true, 'owners' => ['acme', 'beta']]);
env_assert($mcpOn !== null && count($mcpOn->keys) === 2 && $mcpOn->clients['json'] === '{"mcpServers":{"simai":{"command":"ssh","args":["-T","simai-mcp@server.example.com"]}}}'
    && str_contains($mcpPage, 'data-env-security-mcp="on"') && str_contains($mcpPage, 'data-env-security-mcp-keys="2"') && substr_count($mcpPage, 'data-env-security-op="mcp-key-remove"') === 2
    && str_contains($mcpPage, 'value="SHA256:zyxwvuts87654321"') && str_contains($mcpPage, '<option value="beta">beta</option>') && str_contains($mcpPage, 'data-env-security-mcp-client="codex"')
    && str_contains($mcpPage, 'data-env-security-op="mcp-disable"') && \Larena\Env\Security\McpStatus::fromData(['schema' => 'x', 'enabled' => true]) === null,
    'MCP on: keys, adding, connecting. '.$mcpPage);
$mcpArgs = new \ReflectionMethod(\Larena\Env\Http\Controllers\SecurityController::class, 'mcpKeyArgs');
$mcpAsked = static fn (array $input): ?array => $mcpArgs->invoke(null, \Illuminate\Http\Request::create('/', 'POST', $input));
$pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq0examplekeyexamplekeyexample me@laptop';
env_assert($mcpAsked(['pubkey' => "  $pub\n", 'role' => 'administrator', 'owner' => 'acme']) === ['pubkey' => $pub, 'admin' => 'yes']
    && $mcpAsked(['pubkey' => $pub, 'role' => 'owner', 'owner' => 'Acme']) === ['pubkey' => $pub, 'owner' => 'acme'] && $mcpAsked(['pubkey' => $pub, 'role' => 'owner']) === null,
    'The MCP key form\'s arguments.');
\Larena\Env\Gateway\PanelCommands::check('mcp:key-add', ['pubkey' => $pub, 'owner' => 'acme'], true);
\Larena\Env\Gateway\PanelCommands::check('mcp:key-remove', ['fingerprint' => 'SHA256:zyxwvuts87654321'], true);
try {
    \Larena\Env\Gateway\PanelCommands::check('mcp:key-remove', ['fingerprint' => 'MD5:aa:bb'], true);
    env_assert(false, 'A fingerprint not in SHA256 form is refused.');
} catch (\Larena\Env\Gateway\PanelRefused) {
}
$refusedGeo = false;
try {
    \Larena\Env\Gateway\PanelCommands::check('security:geo', ['mode' => 'deny', 'countries' => 'RUS'], true);
} catch (\Larena\Env\Gateway\PanelRefused) {
    $refusedGeo = true;
}
env_assert($refusedGeo, 'A country code that is none is refused before the server.');



// Storages for backups away from the server (simai-env 1.44.0): the list with each storage's bucket, days and last test,
// the copies of the open one with «Забрать на сервер», the add form; the keys never come back into the page.
$storages = [\Larena\Env\Backup\BackupStorage::fromData(['name' => 'yandex', 'type' => 's3', 'bucket' => 'acme-backups', 'endpoint' => 'https://storage.yandexcloud.net',
    'region' => 'ru-central1', 'prefix' => 'srv1', 'keep' => 30, 'default' => true, 'access_key_hint' => 'YCAJ…x1', 'last_test' => ['at' => '2026-11-06T10:00:00Z', 'ok' => true, 'error' => null]]),
    \Larena\Env\Backup\BackupStorage::fromData(['name' => 'r2', 'bucket' => 'b', 'keep' => 7, 'last_test' => ['at' => '2026-11-06T10:00:00Z', 'ok' => false, 'error' => 'AccessDenied']])];
env_assert(\Larena\Env\Backup\BackupStorage::fromData(['name' => 'Bad Name']) === null && $storages[0]?->default === true && $storages[1]?->lastTest['ok'] === false, 'Storages from the server.');
$copies = \Larena\Env\Backup\BackupStorage::copies(['copies' => [['domain' => 'demo-php.test', 'backup' => '20261105-020000', 'created_at' => '2026-11-05T02:00:00Z'],
    ['domain' => 'demo-php.test', 'backup' => '20261106-020000', 'created_at' => '2026-11-06T02:00:00Z'], ['domain' => 'x', 'backup' => '../etc']]]);
env_assert(array_column($copies, 'backup') === ['20261106-020000', '20261105-020000'], 'Copies newest first, a bad one dropped.');
$storagePage = static fn (array $data): string => $factory->make('larena-env::admin.backup-storages', $data + ['source' => 'recorded', 'supported' => true, 'storages' => $storages,
    'open' => null, 'copies' => null, 'allows' => ['add' => true, 'test' => true, 'remove' => true, 'fetch' => true], 'providers' => array_keys(\Larena\Env\Backup\BackupStorage::PROVIDERS),
    'notice' => null])->render();
$listed = $storagePage(['open' => $storages[0], 'copies' => $copies]);
env_assert(str_contains($listed, 'data-env-storages="2"') && str_contains($listed, 'data-env-storage-test="ok"') && str_contains($listed, 'data-env-storage-test="failed"')
    && str_contains($listed, 'AccessDenied') && str_contains($listed, 'data-env-storage-copies="2"') && substr_count($listed, 'data-env-storage-op="fetch"') === 2
    && str_contains($listed, 'name="secret_key"') && str_contains($listed, 'acme-backups/srv1') && !str_contains($listed, 'name="default" value="1" checked'), 'The storages page: '.$listed);
env_assert(str_contains($storagePage(['storages' => []]), 'name="default" value="1" checked') && str_contains($storagePage(['supported' => false]), 'data-env-storages="unsupported"')
    && !str_contains($storagePage(['allows' => ['add' => false, 'test' => false, 'remove' => false, 'fetch' => false]]), 'data-env-storage-op='), 'First storage takes new copies; older server; read only.');
// Every site's last backup on the backups page, the ones in need first (critics 2026-11-07).
$summary = \Larena\Env\Admin\SiteBackupSummary::of([
    new \Larena\Env\Server\SiteSummary('fresh.ru', 'generic', '8.3', 'none', null, null, null, null, true, lastBackup: '2026-01-05T00:00:00Z'),
    new \Larena\Env\Server\SiteSummary('go.ru', 'redirect', 'none', 'none', null, null, null, null, false),
    new \Larena\Env\Server\SiteSummary('old.ru', 'generic', '8.3', 'none', null, null, null, null, true, lastBackup: '2026-01-01T00:00:00Z'),
    new \Larena\Env\Server\SiteSummary('bare.ru', 'static', 'none', 'none', null, null, null, null, false),
], new DateTimeImmutable('2026-01-05T12:00:00Z'));
env_assert(array_column($summary, 'state', 'domain') === ['bare.ru' => 'none', 'old.ru' => 'stale', 'fresh.ru' => 'fresh'], 'The sites\' backups, those in need first: '.json_encode($summary));
$withSummary = $storagePage(['siteBackups' => $summary]);
env_assert(str_contains($withSummary, 'data-env-site-backup="old.ru" data-env-site-backup-state="stale"') && substr_count($withSummary, 'data-env-site-backup=') === 3, 'The backups page lists every site\'s last backup.');
$storageArgs = new \ReflectionMethod(\Larena\Env\Http\Controllers\BackupStorageController::class, 'addArgs');
$added = $storageArgs->invoke(null, \Illuminate\Http\Request::create('/', 'POST', ['provider' => 'yandex', 'bucket' => 'Acme-Backups ', 'prefix' => '/srv1/', 'keep' => '400',
    'default' => '1', 'access_key' => 'YCAJEexample1', 'secret_key' => 'YCsecretexample123']), 'yandex');
env_assert($added === ['name' => 'yandex', 'bucket' => 'acme-backups', 'endpoint' => 'https://storage.yandexcloud.net', 'region' => 'ru-central1', 'prefix' => 'srv1', 'keep' => '90',
    'default' => 'yes', 'access-key' => 'YCAJEexample1', 'secret-key' => 'YCsecretexample123'], 'A storage from the form: '.json_encode($added));
\Larena\Env\Gateway\PanelCommands::check('backup:storage-add', $added, true);
\Larena\Env\Gateway\PanelCommands::check('backup:storage-fetch', ['name' => 'yandex', 'domain' => 'demo-php.test', 'backup' => '20261106-020000'], true);

$offsite = new \ReflectionMethod(\Larena\Env\Server\SiteDetails::class, 'offsite');
env_assert($offsite->invoke(null, ['at' => '2026-11-06T02:05:00Z', 'ok' => false, 'storage' => 'yandex', 'backup' => '20261106-020000', 'error' => 'timeout'])
    === ['at' => '2026-11-06T02:05:00Z', 'ok' => false, 'storage' => 'yandex', 'backup' => '20261106-020000', 'error' => 'timeout'] && $offsite->invoke(null, null) === null,
    'A site\'s last copy away from the server.');

echo "ServerToolsTest passed.\n";
