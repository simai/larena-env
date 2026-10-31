<?php

declare(strict_types=1);

// One site's data, its log, and the three screens that belong to the actions: the site, the plan and the job, drawn
// on a stub of the admin's layout. The real admin shell is proved in an assembly.

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobLog;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\PanelPlan;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteDetailsReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$text = static function (string $key) use ($lines): string {
    $value = data_get($lines, $key);
    env_assert(is_string($value), "No English text for {$key}.");

    return $value;
};

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
    return '/admin/server/'.$name.(($parameters['domain'] ?? $parameters['job'] ?? null) !== null ? '/'.implode('/', $parameters) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

$gateway = new RecordedAnswersGateway(env_recorded_path());
$reader = new SiteDetailsReader($gateway);
$user = 'admin@stand.test';

// The site, typed, without the host's paths, database names and users.
$result = $reader->read(new PanelActor($user), 'demo-php.test');
$site = $result->site;
env_assert($site instanceof SiteDetails && $result->reason === null, 'The site reads.');
env_assert($site->profile === 'generic' && $site->php === '8.3' && $site->ssl === 'none' && $site->databaseEngine === 'mysql', 'What it runs on.');
env_assert($site->certificate === null && $site->backupSchedule === null && $site->release === null, 'No certificate, no daily backup, no release.');
env_assert(count($site->backups) === 1 && $site->backups[0]->name === '20261031-092444', 'A backup is named by its own name, not its path.');
env_assert($site->backups[0]->parts === ['config', 'db', 'files'] && $site->backups[0]->encrypted === false, 'What the backup holds.');
$typed = serialize($site);
foreach (['/home/simai', '/var/backups', 'demo_php_test', 'localhost'] as $left) {
    env_assert(!str_contains($typed, $left), "The typed site must not carry {$left}.");
}

// A site whose recording is not there is said to be missing, never guessed.
env_assert($reader->read(new PanelActor($user), 'nothing.test')->reason === PanelReason::RECORDING_MISSING, 'An unknown site.');

// The log is read with its own command.
$log = $reader->log(new PanelActor($user), 'demo-static.test', 'access');
env_assert($log->log !== null && $log->log->kind === 'access' && count($log->log->lines) === 1, 'The last lines of the log.');
env_assert($reader->log(new PanelActor($user), 'demo-static.test', 'everything')->reason === PanelReason::INVALID_ARGUMENT, 'Only access or error.');

// The views on stubs.
$files = new Filesystem();
$compiled = env_temp_directory();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, $compiled), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');

$screen = static fn (array $data): string => $factory->make('larena-env::admin.site', $data + [
    'domain' => 'demo-php.test', 'source' => 'sudo', 'site' => null, 'refused' => false, 'reasonText' => null, 'tab' => 'overview',
    'tabs' => ['overview', 'domains', 'php', 'releases', 'log', 'access'], 'doctor' => null, 'phpVersions' => ['8.3'], 'tabOperations' => [],
    'logKind' => 'access', 'logKinds' => ['access', 'error'], 'log' => null, 'logReasonText' => null, 'notice' => null,
])->render();
$onTab = static fn (string $tab): array => array_values(array_filter(SiteOperation::offeredFor($site), static fn (SiteOperation $operation): bool => $operation->tab() === $tab));

// The overview: the site, simai-env's check of it, its backups and «Back up now».
$doctor = $reader->doctor(new PanelActor($user), 'demo-php.test');
env_assert($doctor !== null && $doctor->status === 'WARN' && $doctor->problems !== [] && $doctor->passed > 0, 'The check reads: what did not pass is named.');
$ok = $screen(['site' => $site, 'doctor' => $doctor, 'tabOperations' => $onTab('overview')]);
env_assert(str_contains($ok, 'data-env-state="ok"') && str_contains($ok, 'data-env-doctor="WARN"') && str_contains($ok, 'data-env-problem="WARN"'), 'The overview shows the check and its problems.');
env_assert(str_contains($ok, 'data-env-backup="20261031-092444"') && str_contains($ok, 'data-env-operation="backup"'), 'Backups and «Back up now» are on the overview.');
env_assert(substr_count($ok, 'data-env-tab=') === 6 && preg_match('/data-env-tab="overview"\s+aria-current="page"/', $ok) === 1, 'The six tabs, the current one marked.');
env_assert(!str_contains($ok, 'data-env-log=') && !str_contains($ok, 'data-env-operation="certificate-issue"'), 'A tab shows only its own part and actions.');

// The other tabs: the certificate with its actions, PHP with the version the server has, the log.
$domains = $screen(['site' => $site, 'tab' => 'domains', 'tabOperations' => $onTab('domains')]);
env_assert(str_contains($domains, 'data-env-certificate="none"') && str_contains($domains, 'data-env-operation="certificate-issue"')
    && !str_contains($domains, 'data-env-operation="certificate-renew"'), 'A site without a certificate says so and offers to get one, not to renew.');
$php = $screen(['site' => $site, 'tab' => 'php', 'tabOperations' => $onTab('php')]);
env_assert(str_contains($php, 'data-env-operation="php-version"') && preg_match('/<option value="8\.3"\s+selected\s*>/', $php) === 1, 'PHP offers the server\'s versions, the current one chosen.');
env_assert(!str_contains($php, 'data-env-operation="queue-restart"'), 'And nothing the site cannot be asked for.');
$logged = $screen(['site' => $site, 'tab' => 'log', 'log' => $log->log]);
env_assert(str_contains($logged, 'data-env-log="access"') && str_contains($logged, 'tab=log&amp;log=error'), 'The log keeps its tab.');
$access = $screen(['site' => $site, 'tab' => 'access']);
env_assert(str_contains($access, 'data-env-access') && str_contains($access, 'site-demo-php-test'), 'Access names the account the site runs as.');
env_assert(str_contains($ok, 'name="_token"') && substr_count($ok, '<form') === 1, 'Each action is a form of its own, with the session\'s token.');

$refused = $screen(['refused' => true, 'reasonText' => 'Another owner.']);
env_assert(str_contains($refused, 'data-env-state="refused"') && str_contains($refused, 'Another owner.') && !str_contains($refused, 'data-env-operations')
    && !str_contains($refused, 'data-env-tabs'), 'A refusal shows its reason and offers nothing.');

// Acting for a person: every screen says so and offers to stop.
$acting = $screen(['site' => $site, 'acting' => ['label' => 'Anna', 'owner' => 'demo']]);
env_assert(str_contains($acting, 'data-env-acting="demo"') && str_contains($acting, 'Anna'), 'Looking as a person is always said.');

// The plan: what would happen, and one confirmation that starts it.
$plan = (new PanelOperations($gateway))->plan(new PanelActor($user), SiteOperation::Backup, 'demo-owned.test', ['db' => 'no'])->plan;
env_assert($plan instanceof PanelPlan, 'The plan reads.');
$planned = $factory->make('larena-env::admin.plan', ['heading' => 'Back up now', 'eyebrow' => 'demo-owned.test', 'target' => 'demo-owned.test',
    'source' => 'recorded', 'plan' => $plan, 'runUrl' => '/admin/server/sites/demo-owned.test/run', 'cancelUrl' => '/admin/server/sites/demo-owned.test',
    'hidden' => ['operation' => 'backup', 'db' => 'no']])->render();
env_assert(str_contains($planned, 'data-env-plan="backup:data"') && str_contains($planned, 'data-env-plan-argument="db"'), 'The plan is shown in full.');
env_assert(str_contains($planned, 'data-env-confirm="backup:data"') && str_contains($planned, 'name="db" value="no"')
    && str_contains($planned, 'name="operation" value="backup"'), 'The confirmation carries the same values.');
env_assert(str_contains($planned, 'data-env-source="recorded"'), 'Recorded answers are marked as recorded.');

// A new site: the wizard's plan, as the gateway answers it.
$newSite = (new PanelOperations($gateway))->planCommand(new PanelActor($user), 'site:add', ['domain' => 'new.test', 'profile' => 'static']);
env_assert($newSite->plan instanceof PanelPlan && $newSite->plan->command === 'site:add', 'A new site is planned like any action.');
env_assert((new PanelOperations($gateway))->planCommand(new PanelActor($user), 'site:add', ['domain' => 'new.test', 'profile' => 'proxy'])->reason
    === PanelReason::INVALID_ARGUMENT, 'A profile simai-env does not have is refused before the request leaves.');
$wizard = $factory->make('larena-env::admin.new-site', ['profiles' => \Larena\Env\Http\Controllers\NewSiteController::PROFILES,
    'phpProfiles' => ['generic'], 'phpVersions' => ['8.3'], 'owners' => ['demo'], 'old' => [], 'notice' => null, 'source' => 'recorded'])->render();
env_assert(substr_count($wizard, 'data-env-profile=') === 6 && str_contains($wizard, 'data-env-new-owner') && preg_match('/<option value="demo"\s*>/', $wizard) === 1, 'The wizard: the kinds and, for the whole server, whose the site is.');
$ownWizard = $factory->make('larena-env::admin.new-site', ['profiles' => ['static'], 'phpProfiles' => [], 'phpVersions' => [], 'owners' => null,
    'old' => [], 'notice' => null])->render();
env_assert(!str_contains($ownWizard, 'data-env-new-owner') && str_contains($ownWizard, 'data-env-new-own-space'), 'A person\'s site is in their space.');

// The job: where it stands, what it printed, and a way to stop it while it runs.
$view = (new PanelOperations($gateway))->view(new PanelActor($user), '000000000000000000000000');
env_assert($view->status instanceof PanelJobStatus && $view->log instanceof PanelJobLog, 'The job reads.');
$job = static fn (array $data): string => $factory->make('larena-env::admin.job', $data + ['jobId' => '000000000000000000000000',
    'source' => 'sudo', 'status' => null, 'log' => null, 'refused' => false, 'reasonText' => null, 'notice' => null])->render();

$done = $job(['status' => $view->status, 'log' => $view->log]);
env_assert(str_contains($done, 'data-env-job-state="succeeded"') && str_contains($done, 'Data backup'), 'A finished job shows its output.');
env_assert(!str_contains($done, 'data-env-job-cancel') && !str_contains($done, 'Read on'), 'A finished job is neither stopped nor read on.');

$running = new PanelJobStatus('000000000000000000000000', JobState::Running, 'ssl:renew', ['domain' => 'demo-php.test'], null, '2026-01-01T00:00:00Z', $user);
$live = $job(['status' => $running, 'log' => new PanelJobLog('000000000000000000000000', JobState::Running, ['working'], 1)]);
env_assert(str_contains($live, 'data-env-job-cancel') && str_contains($live, 'after=1'), 'A running job can be stopped and read on.');

$hostile = new PanelJobStatus('000000000000000000000000', JobState::Failed, '<img src=x onerror=alert(1)>', [], 1, null, null);
$escaped = $job(['status' => $hostile, 'log' => new PanelJobLog('000000000000000000000000', JobState::Failed, ['<script>alert(1)</script>'], 1)]);
env_assert(!str_contains($escaped, '<img') && !str_contains($escaped, '<script>alert'), 'What the server says is escaped.');

foreach (['site', 'plan', 'job', 'new-site', 'people', 'partials/notes'] as $view) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view.'.blade.php');
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "The {$view} view prints nothing raw and holds no script.");
}

echo "SiteScreenTest passed.\n";
