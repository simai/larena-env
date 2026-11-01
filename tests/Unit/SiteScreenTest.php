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
env_assert($site->certificate === null && $site->backupSchedule?->time === '03:30' && $site->backupSchedule->keep === 7 && $site->backupSchedule->verify === false
    && $site->release === null, 'No certificate, a daily backup at 03:30 kept 7, not checked, no release.');
env_assert($site->lastVerify?->ok === true && $site->lastVerify->restoreTest && $site->lastVerify->backup === '20260101-000000', 'The server\'s last check of a copy (simai-env 1.30.0).');
env_assert(count($site->backups) === 1 && $site->backups[0]->name === '20261101-151759' && $site->backups[0]->sizeBytes === 4345, 'A backup is named by its own name, not its path.');
env_assert($site->answersChecked && $site->diskBytes !== null, 'Whether it answers and its disk (simai-env 1.27.0).');
env_assert($site->backups[0]->parts === ['config', 'db', 'files'] && $site->backups[0]->encrypted === false, 'What the backup holds.');
$typed = serialize($site);
foreach (['/home/simai', '/var/backups', 'demo_php_test', 'localhost'] as $left) {
    env_assert(!str_contains($typed, $left), "The typed site must not carry {$left}.");
}

// A site whose recording is not there is said to be missing, never guessed.
env_assert($reader->read(new PanelActor($user), 'nothing.test')->reason === PanelReason::RECORDING_MISSING, 'An unknown site.');

// The log is read with its own command.
$log = $reader->log(new PanelActor($user), 'demo-static.test', 'access');
env_assert($log->log !== null && $log->log->kind === 'access' && count($log->log->lines) === 2, 'The last lines of the log.');
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
    // As the controller: each account action chooses among the accounts it suits.
    'sftpChoices' => array_combine(array_map(static fn (SiteOperation $operation): string => $operation->value, $data['tabOperations'] ?? []),
        array_map(static fn (SiteOperation $operation): array => $operation->accountChoices($data['sftpAccounts'] ?? []), $data['tabOperations'] ?? [])),
    'domain' => 'demo-php.test', 'source' => 'sudo', 'site' => null, 'refused' => false, 'reasonText' => null, 'tab' => 'overview',
    'tabs' => \Larena\Env\Http\Controllers\SiteAdminController::TABS, 'doctor' => null, 'phpVersions' => ['8.3'], 'tabOperations' => [],
    'phpSettings' => null, 'phpExtensions' => null, 'databases' => null, 'sftpAccounts' => null, 'capabilities' => null,
    'logKind' => 'access', 'logKinds' => ['access', 'error'], 'logLines' => 200, 'logLineChoices' => [200, 500, 1000], 'logSearch' => '',
    'logGroup' => '', 'logGroups' => \Larena\Env\Server\SiteLogView::STATUS_GROUPS, 'log' => null, 'logReasonText' => null, 'notice' => null,
])->render();
$capabilities = (new \Larena\Env\Server\ServerOverviewReader($gateway))->read(new PanelActor($user))->overview->capabilities;
$onTab = static fn (string $tab, ?\Larena\Env\Gateway\PanelCapabilities $with = null): array => array_values(array_filter(SiteOperation::offeredFor($site, $with ?? $capabilities), static fn (SiteOperation $operation): bool => $operation->tab() === $tab));

// The overview: the site, simai-env's check of it, its backups and «Back up now».
$doctor = $reader->doctor(new PanelActor($user), 'demo-php.test');
env_assert($doctor !== null && $doctor->status === 'WARN' && $doctor->problems !== [] && $doctor->passed > 0, 'The check reads: what did not pass is named.');
$ok = $screen(['site' => $site, 'doctor' => $doctor, 'tabOperations' => $onTab('overview')]);
env_assert(str_contains($ok, 'data-env-state="ok"') && str_contains($ok, 'data-env-doctor="WARN"') && str_contains($ok, 'data-env-problem="WARN"'), 'The overview shows the check and its problems.');
env_assert(str_contains($ok, 'data-env-answers="') && str_contains($ok, 'data-env-disk') && !str_contains($ok, 'data-env-no-backup'), 'Whether it answers and its disk; it has a backup.');
$warned = $screen(['site' => $site, 'warnings' => [new \Larena\Env\Warnings\PanelWarning('backup_stale', 'warning', 'demo-php.test', ['domain' => 'demo-php.test', 'time' => '2026-01-01'])]]);
env_assert(str_contains($warned, 'data-env-warning="backup_stale"') && str_contains($warned, 'href="?tab=backups"')
    && strpos($warned, 'data-env-warnings') < strpos($warned, 'data-env-tabs'), 'The card says what needs attention above its tabs, with the tab to deal with it.');
env_assert(substr_count($ok, 'data-env-tab=') === 9 && preg_match('/data-env-tab="overview"\s+aria-current="page"/', $ok) === 1, 'The nine tabs, the current one marked.');

// Backups have their tab: the list, «Back up now», and restoring and checking only where the server has them.
$older = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.27.0']]);
$backups = $screen(['site' => $site, 'tab' => 'backups', 'tabOperations' => $onTab('backups', $older)]);
env_assert(str_contains($backups, 'data-env-backup="20261101-151759"') && str_contains($backups, '4.2 KB') && str_contains($backups, 'data-env-operation="backup"'), 'The backups and «Back up now».');
env_assert(!str_contains($backups, 'data-env-operation="backup-restore-database"'), 'No restore while the server\'s gateway has none.');
$more = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.27.0'], 'gate' => ['commands' => ['backup:data', 'backup:data-restore', 'backup:data-schedule', 'site:db-rotate', 'site:php-ini-set', 'access:create-project', 'access:disable']]]);
$restore = $screen(['site' => $site, 'tab' => 'backups', 'tabOperations' => $onTab('backups', $more)]);
env_assert(str_contains($restore, 'data-env-operation="backup-restore-database"') && str_contains($restore, 'data-env-operation="backup-extract-files"')
    && str_contains($restore, '<option value="20261101-151759">') && str_contains($restore, 'data-env-restore-note'), 'Restoring chooses a backup and says a copy is taken first.');
env_assert(str_contains($restore, 'data-env-operation="backup-schedule"') && str_contains($restore, 'type="time"'), 'The daily backup, at a time of day.');
// simai-env 1.30.0: each new copy is checked, asked where the server takes it, on by default; the last check is said.
$checked = \Larena\Env\Server\SiteDetails::fromDescription(['schema' => 'simai-site/1', 'domain' => 'demo-php.test', 'profile' => 'generic', 'php' => '8.3', 'ssl' => 'none',
    'backups' => ['schedule' => ['time' => '03:30', 'keep' => '7', 'verify' => true], 'items' => [], 'last_verify' => ['at' => '2026-01-01T00:00:00Z', 'ok' => false, 'backup' => '20260101-000000', 'restore_test' => true]]]);
env_assert($checked->backupSchedule?->keep === 7 && $checked->backupSchedule->verify === true && $checked->lastVerify?->ok === false, 'The schedule reads its number as digits, and the last check.');
$newer = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.30.0'], 'gate' => ['commands' => ['backup:data-schedule']]]);
$verifying = $screen(['site' => $checked, 'tab' => 'backups', 'tabOperations' => $onTab('backups', $newer), 'capabilities' => $newer]);
env_assert(str_contains($verifying, 'data-env-verify-field') && preg_match('/<option value="yes"\s+selected/', $verifying) === 1
    && str_contains($verifying, 'data-env-backup-verify="yes"') && str_contains($verifying, 'data-env-last-verify="failed"'), 'A checked schedule, its last failed check, and the check asked for.');
$beforeCheck = $screen(['site' => $site, 'tab' => 'backups', 'tabOperations' => $onTab('backups', $more), 'capabilities' => $more]);
env_assert(str_contains($beforeCheck, 'data-env-operation="backup-schedule"') && !str_contains($beforeCheck, 'data-env-verify-field'), 'A server before 1.30.0 is not asked to check copies.');

// The database: its engine, its actions where the server has them, and never a password.
$database = $screen(['site' => $site, 'tab' => 'database', 'tabOperations' => $onTab('database', $more),
    'databases' => [\Larena\Env\Server\SiteDatabase::fromData(['name' => 'demo_php', 'engine' => 'mysql', 'site' => 'demo-php.test', 'size_bytes' => 2048, 'users' => ['demo_php']])]]);
env_assert(str_contains($database, 'data-env-database="mysql"') && str_contains($database, 'data-env-database-item="demo_php"') && str_contains($database, '2.0 KB'), 'The database and its size.');
env_assert(str_contains($database, 'data-env-operation="database-password"') && str_contains($database, 'data-env-operation="database-export"')
    && !str_contains($database, 'data-env-operation="database-create"'), 'A site with a database changes its password and exports it, and is not given another.');

// SFTP: the site's accounts, and an account's actions name it.
$sftp = $screen(['site' => $site, 'tab' => 'access', 'tabOperations' => $onTab('access', $more),
    'sftpAccounts' => [\Larena\Env\Server\SftpAccount::fromData(['login' => 'dev', 'type' => 'project', 'domain' => 'demo-php.test', 'enabled' => true, 'auth' => 'key', 'sftp_path' => '/site'])]]);
env_assert(str_contains($sftp, 'data-env-sftp="dev"') && str_contains($sftp, 'data-env-operation="sftp-create"') && str_contains($sftp, '<textarea'), 'A new access takes a key, never a password.');
env_assert(str_contains($sftp, 'data-env-operation="sftp-disable"') && str_contains($sftp, '<option value="dev">'), 'Turning an access off chooses it.');
env_assert(!str_contains($sftp, 'type="password"') && !str_contains($sftp, 'name="password"'), 'No password field anywhere: SFTP is by key only.');
env_assert(str_contains($sftp, 'data-env-sftp-path="/site"') && str_contains($sftp, 'data-env-sftp-uploads'), 'An access says where it lands, and how uploads the site must rewrite need their mode.');
env_assert(!str_contains($ok, 'data-env-log=') && !str_contains($ok, 'data-env-operation="certificate-issue"'), 'A tab shows only its own part and actions.');

// The other tabs: the certificate with its actions, PHP with the version the server has, the log.
$domains = $screen(['site' => $site, 'tab' => 'domains', 'tabOperations' => $onTab('domains')]);
env_assert(str_contains($domains, 'data-env-certificate="none"') && str_contains($domains, 'data-env-operation="certificate-issue"')
    && !str_contains($domains, 'data-env-operation="certificate-renew"'), 'A site without a certificate says so and offers to get one, not to renew.');
$php = $screen(['site' => $site, 'tab' => 'php', 'tabOperations' => $onTab('php', $older),
    'phpSettings' => [\Larena\Env\Server\PhpSetting::fromData(['key' => 'memory_limit', 'value' => '256M', 'source' => 'site'])],
    'phpExtensions' => [\Larena\Env\Server\PhpExtension::fromData(['name' => 'intl', 'enabled' => true])]]);
env_assert(str_contains($php, 'data-env-php-setting="memory_limit"') && str_contains($php, 'data-env-php-extension="intl"'), 'PHP settings and extensions, when the server reports them.');
env_assert(!str_contains($php, 'data-env-operation="php-setting"'), 'Changing a setting only where the server allows it.');
$phpNow = $screen(['site' => $site, 'tab' => 'php', 'tabOperations' => $onTab('php', $more)]);
env_assert(str_contains($phpNow, 'data-env-operation="php-setting"') && str_contains($phpNow, 'name="name"') && !str_contains($phpNow, 'name="key"'),
    'A php.ini setting is sent as the gateway names it (`name`, simai-env 1.28.0).');
env_assert(SiteOperation::BackupSchedule->arguments('demo-php.test', ['enabled' => 'yes', 'time' => '03:00', 'keep' => '7'])
    === ['domain' => 'demo-php.test', 'enabled' => 'yes', 'time' => '03:00', 'keep' => '7'], 'The daily backup is `enabled`, as the gateway names it.');
env_assert(SiteOperation::BackupRestoreDatabase->arguments('demo-php.test', ['backup' => 'b1', 'files' => 'yes'])
    === ['domain' => 'demo-php.test', 'db' => 'yes', 'files' => 'no', 'backup' => 'b1'], 'Restoring the database never touches the files.');
env_assert(SiteOperation::DatabaseExport->command() === 'backup:data' && SiteOperation::DatabaseExport->arguments('demo-php.test', [])
    === ['domain' => 'demo-php.test', 'db' => 'yes', 'files' => 'no'], 'A database dump is a backup without the files.');
env_assert(str_contains($php, 'data-env-operation="php-version"') && preg_match('/<option value="8\.3"\s+selected\s*>/', $php) === 1, 'PHP offers the server\'s versions, the current one chosen.');
env_assert(!str_contains($php, 'data-env-operation="queue-restart"'), 'And nothing the site cannot be asked for.');
$logged = $screen(['site' => $site, 'tab' => 'log', 'log' => \Larena\Env\Server\SiteLogView::of($log->log)]);
env_assert(str_contains($logged, 'data-env-log="access"') && str_contains($logged, 'data-env-log-filter') && str_contains($logged, 'name="tab" value="log"'), 'The log keeps its tab and can be narrowed.');
env_assert(str_contains($logged, 'data-env-log-row="200"') && str_contains($logged, 'GET /') && !str_contains($logged, '<code>127.0.0.1'), 'A request line is read into its parts, not repeated.');

// The log narrowed: by words, by the answer's group, by an error's level; newest first.
$tail = new \Larena\Env\Server\NginxLogTail('a.test', 'access', [
    '1.2.3.4 - - [01/Jan/2026:00:00:01 +0000] "GET /ok HTTP/1.1" 200 21 "-" "curl"',
    '1.2.3.5 - - [01/Jan/2026:00:00:02 +0000] "POST /form HTTP/1.1" 500 10 "-" "Firefox"',
    'not a request line',
]);
$all = \Larena\Env\Server\SiteLogView::of($tail);
env_assert($all->total === 3 && count($all->rows) === 3 && $all->rows[0]['text'] === 'not a request line' && $all->rows[1]['status'] === '500', 'Newest first; a line in another form stays text.');
env_assert(count(\Larena\Env\Server\SiteLogView::of($tail, '', '5xx')->rows) === 1 && \Larena\Env\Server\SiteLogView::of($tail, '', '5xx')->rows[0]['path'] === '/form', 'Only the 5xx answers.');
env_assert(count(\Larena\Env\Server\SiteLogView::of($tail, 'FIREFOX')->rows) === 1, 'Words in any case.');
$errors = \Larena\Env\Server\SiteLogView::of(new \Larena\Env\Server\NginxLogTail('a.test', 'error', [
    '2026/01/01 00:00:00 [error] 12#12: *1 open() failed', '2026/01/01 00:00:01 [warn] 12#12: something']), '', 'error');
env_assert(count($errors->rows) === 1 && $errors->rows[0]['level'] === 'error' && $errors->rows[0]['time'] === '2026/01/01 00:00:00', 'Errors by level.');
$access = $screen(['site' => $site, 'tab' => 'access']);
env_assert(str_contains($access, 'data-env-access') && str_contains($access, 'site-demo-php-test'), 'Access names the account the site runs as.');
env_assert(str_contains($backups, 'name="_token"') && substr_count($backups, '<form') === 1, 'Each action is a form of its own, with the session\'s token.');

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
env_assert(!str_contains($planned, 'data-env-plan-replaces') && !str_contains($planned, 'data-env-plan-then'), 'A backup replaces nothing and needs nothing after.');
// A restore says what it replaces, and a new site what follows it (Panel plan 4.1, 4.2).
$restoring = $factory->make('larena-env::admin.plan', ['heading' => 'Restore', 'eyebrow' => 'demo-php.test', 'target' => 'demo-php.test',
    'source' => 'sudo', 'plan' => $plan, 'runUrl' => '/run', 'cancelUrl' => '/cancel', 'hidden' => [],
    'replaces' => 'The database of demo-php.test is replaced.', 'then' => [$text('new_site.daily_backup_after')]])->render();
env_assert(str_contains($restoring, 'data-env-plan-replaces') && str_contains($restoring, 'The database of demo-php.test is replaced.')
    && str_contains($restoring, 'data-env-plan-then'), 'What is replaced and what follows, before the confirmation.');

// A new site: the wizard's plan, as the gateway answers it.
$newSite = (new PanelOperations($gateway))->planCommand(new PanelActor($user), 'site:add', ['domain' => 'new.test', 'profile' => 'static']);
env_assert($newSite->plan instanceof PanelPlan && $newSite->plan->command === 'site:add', 'A new site is planned like any action.');
env_assert((new PanelOperations($gateway))->planCommand(new PanelActor($user), 'site:add', ['domain' => 'new.test', 'profile' => 'proxy'])->reason
    === PanelReason::INVALID_ARGUMENT, 'A profile simai-env does not have is refused before the request leaves.');
$wizard = $factory->make('larena-env::admin.new-site', ['profiles' => \Larena\Env\Http\Controllers\NewSiteController::PROFILES,
    'phpProfiles' => ['generic'], 'phpVersions' => ['8.3'], 'owners' => ['demo'], 'old' => [], 'notice' => null, 'source' => 'recorded'])->render();
env_assert(substr_count($wizard, 'data-env-profile=') === 6 && str_contains($wizard, 'data-env-new-owner') && preg_match('/<option value="demo"\s*>/', $wizard) === 1, 'The wizard: the kinds and, for the whole server, whose the site is.');
$kindWizard = static fn (string $kind): string => $factory->make('larena-env::admin.new-site', ['profiles' => \Larena\Env\Http\Controllers\NewSiteController::PROFILES,
    'phpProfiles' => ['generic', 'laravel', 'wordpress'], 'phpVersions' => ['8.3'], 'owners' => null, 'old' => [], 'notice' => null, 'kind' => $kind])->render();
env_assert(substr_count($kindWizard('static'), 'data-env-profile-hint=') === 6 && !str_contains($kindWizard('static'), 'data-env-field='), 'Each kind says what it is; a static site asks nothing more.');
env_assert(str_contains($kindWizard('laravel'), 'data-env-field="php"') && str_contains($kindWizard('laravel'), 'data-env-field="database"')
    && !str_contains($kindWizard('laravel'), 'data-env-field="redirect"') && str_contains($kindWizard('laravel'), 'data-env-new-profile="laravel"'), 'Laravel asks for PHP and a database.');
env_assert(str_contains($kindWizard('redirect'), 'data-env-field="redirect"') && !str_contains($kindWizard('redirect'), 'data-env-field="php"'), 'A redirect asks only where to.');
$ownWizard = $factory->make('larena-env::admin.new-site', ['profiles' => ['static'], 'phpProfiles' => [], 'phpVersions' => [], 'owners' => null,
    'old' => [], 'notice' => null])->render();
env_assert(!str_contains($ownWizard, 'data-env-new-owner') && str_contains($ownWizard, 'data-env-new-own-space'), 'A person\'s site is in their space.');
env_assert(!str_contains($ownWizard, 'data-env-new-daily-backup'), 'No daily backup offered where the server cannot set one.');
$backedUp = $factory->make('larena-env::admin.new-site', ['profiles' => ['static'], 'phpProfiles' => [], 'phpVersions' => [], 'owners' => null,
    'old' => [], 'notice' => null, 'dailyBackup' => true])->render();
env_assert(preg_match('/name="daily-backup" value="yes" data-env-new-daily-backup\s+checked/', $backedUp) === 1
    && str_contains($backedUp, 'name="daily-backup" value="no"'), 'A new site gets its daily backup unless it is turned off.');

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

// The spaces: what each one's sites take, whether it is blocked, and blocking or unblocking where the server has it.
$spaces = static fn (array $data): string => $factory->make('larena-env::admin.people', $data + ['people' => [], 'owners' => ['demo', 'acme'],
    'blockedOwners' => ['acme'], 'ownerDisk' => ['demo' => 1048576], 'canBlock' => true, 'source' => 'recorded', 'reasonText' => null, 'notice' => null])->render();
$blocking = $spaces([]);
env_assert(str_contains($blocking, 'data-env-owner="demo" data-env-owner-blocked="no"') && str_contains($blocking, '1.0 MB'), 'A space and its disk.');
env_assert(str_contains($blocking, 'data-env-owner="acme" data-env-owner-blocked="yes"') && str_contains($blocking, 'name="action" value="unblock"')
    && str_contains($blocking, 'name="action" value="block"'), 'A blocked space offers unblocking, the other blocking.');
env_assert(!str_contains($spaces(['canBlock' => false]), 'name="action" value="block"'), 'No blocking where the server\'s gateway has none.');

// The DNS tab (Panel plan, stage 7): how to connect when nothing is, where the domain points, the records and the plan.
$dnsZone = new \Larena\Env\Dns\DnsZone(str_repeat('a', 32), 'demo-php.test', 'active', ['#dns_records:edit']);
$dnsRecords = [new \Larena\Env\Dns\DnsRecord(str_repeat('1', 32), 'A', 'demo-php.test', '198.51.100.7', 1)];
$noDns = $screen(['site' => $site, 'tab' => 'dns', 'siteDns' => new \Larena\Env\Dns\SiteDns('not_connected'), 'wholeServer' => true]);
env_assert(str_contains($noDns, 'data-env-dns="not_connected"') && str_contains($noDns, 'data-env-dns-connect') && str_contains($noDns, 'larena.env.admin.dns.connection'), 'Not connected: how to connect.');
env_assert(!str_contains($screen(['site' => $site, 'tab' => 'dns', 'siteDns' => new \Larena\Env\Dns\SiteDns('not_connected'), 'wholeServer' => false]), 'larena.env.admin.dns.connection'), 'The user level is not sent to the connection.');
$elsewhere = $screen(['site' => $site, 'tab' => 'dns', 'wholeServer' => false, 'siteDns' => new \Larena\Env\Dns\SiteDns('ok', $dnsZone, $dnsRecords, \Larena\Env\Dns\SiteDns::ELSEWHERE)]);
env_assert(str_contains($elsewhere, 'data-env-dns-points="elsewhere"') && str_contains($elsewhere, 'data-env-dns-record="A"') && str_contains($elsewhere, 'data-env-dns-point'), 'Points elsewhere: the records and «point here».');
$here = $screen(['site' => $site, 'tab' => 'dns', 'wholeServer' => true, 'siteDns' => new \Larena\Env\Dns\SiteDns('ok', $dnsZone, $dnsRecords, \Larena\Env\Dns\SiteDns::HERE)]);
env_assert(str_contains($here, 'data-env-dns-points="here"') && !str_contains($here, 'data-env-dns-point>') && !str_contains($here, 'data-env-dns-point '), 'Points here: nothing to offer.');

foreach (['site', 'plan', 'job', 'new-site', 'people', 'partials/notes', 'partials/warnings', 'partials/history', 'dns/connection', 'dns/index', 'dns/zone', 'dns/record-form', 'dns/plan'] as $view) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view.'.blade.php');
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "The {$view} view prints nothing raw and holds no script.");
}

echo "SiteScreenTest passed.\n";
