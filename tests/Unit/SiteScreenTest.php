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
$result = $reader->read($user, null, 'demo-php.test');
$site = $result->site;
env_assert($site instanceof SiteDetails && $result->reason === null, 'The site reads.');
env_assert($site->profile === 'generic' && $site->php === '8.3' && $site->ssl === 'none' && $site->databaseEngine === 'mysql', 'What it runs on.');
env_assert($site->certificate === null && $site->backupSchedule === null && $site->release === null, 'No certificate, no daily backup, no release.');
env_assert(count($site->backups) === 1 && $site->backups[0]->name === '20261030-174316', 'A backup is named by its own name, not its path.');
env_assert($site->backups[0]->parts === ['config', 'db', 'files'] && $site->backups[0]->encrypted === false, 'What the backup holds.');
$typed = serialize($site);
foreach (['/home/simai', '/var/backups', 'demo_php_test', 'localhost'] as $left) {
    env_assert(!str_contains($typed, $left), "The typed site must not carry {$left}.");
}

// A site whose recording is not there is said to be missing, never guessed.
env_assert($reader->read($user, null, 'nothing.test')->reason === PanelReason::RECORDING_MISSING, 'An unknown site.');

// The log is read with its own command.
$log = $reader->log($user, null, 'demo-static.test', 'access');
env_assert($log->log !== null && $log->log->kind === 'access' && count($log->log->lines) === 1, 'The last lines of the log.');
env_assert($reader->log($user, null, 'demo-static.test', 'everything')->reason === PanelReason::INVALID_ARGUMENT, 'Only access or error.');

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
    'domain' => 'demo-php.test', 'source' => 'sudo', 'site' => null, 'refused' => false, 'reasonText' => null,
    'operations' => [], 'logKind' => 'access', 'logKinds' => ['access', 'error'], 'log' => null, 'logReasonText' => null, 'notice' => null,
])->render();

$ok = $screen(['site' => $site, 'operations' => SiteOperation::offeredFor($site), 'log' => $log->log]);
env_assert(str_contains($ok, 'data-env-state="ok"') && str_contains($ok, 'data-env-certificate="none"'), 'A site without a certificate says so.');
env_assert(str_contains($ok, 'data-env-backup="20261030-174316"') && str_contains($ok, 'data-env-log="access"'), 'Backups and the log are shown.');
env_assert(str_contains($ok, 'data-env-operation="certificate-issue"') && str_contains($ok, 'data-env-operation="backup"'), 'The actions it can be asked for.');
env_assert(!str_contains($ok, 'data-env-operation="rollback"') && !str_contains($ok, 'data-env-operation="queue-restart"'), 'And nothing it cannot.');
env_assert(str_contains($ok, 'name="_token"') && substr_count($ok, '<form') === 2, 'Each action is a form of its own, with the session\'s token.');

$refused = $screen(['refused' => true, 'reasonText' => 'Another owner.']);
env_assert(str_contains($refused, 'data-env-state="refused"') && str_contains($refused, 'Another owner.') && !str_contains($refused, 'data-env-operations'), 'A refusal shows its reason and offers nothing.');

// The plan: what would happen, and one confirmation that starts it.
$plan = (new PanelOperations($gateway))->plan($user, null, SiteOperation::Backup, 'demo-owned.test', ['db' => 'no'])->plan;
env_assert($plan instanceof PanelPlan, 'The plan reads.');
$planned = $factory->make('larena-env::admin.plan', ['domain' => 'demo-owned.test', 'source' => 'recorded',
    'operation' => SiteOperation::Backup, 'input' => ['db' => 'no'], 'plan' => $plan])->render();
env_assert(str_contains($planned, 'data-env-plan="backup:data"') && str_contains($planned, 'data-env-plan-argument="db"'), 'The plan is shown in full.');
env_assert(str_contains($planned, 'data-env-confirm="backup"') && str_contains($planned, 'name="db" value="no"'), 'The confirmation carries the same values.');
env_assert(str_contains($planned, 'data-env-source="recorded"'), 'Recorded answers are marked as recorded.');

// The job: where it stands, what it printed, and a way to stop it while it runs.
$view = (new PanelOperations($gateway))->view($user, null, '000000000000000000000000');
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

foreach (['site', 'plan', 'job'] as $view) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view.'.blade.php');
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "The {$view} view prints nothing raw and holds no script.");
}

echo "SiteScreenTest passed.\n";
