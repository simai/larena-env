<?php

declare(strict_types=1);

// Actions go one way only: a plan, a confirmation, a job the screen follows. The recorded answers of simai-env 1.25.0
// stand for the server, and what this package would never send is refused before it leaves.

use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteDetailsReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$gateway = new RecordedAnswersGateway(env_recorded_path());
$operations = new PanelOperations($gateway);
$user = 'admin@stand.test';

// The table knows each command's kind, and nothing else is allowed at all.
env_assert(PanelCommands::kind('site:describe') === PanelCommands::READ, 'A read.');
env_assert(PanelCommands::kind('backup:data') === PanelCommands::ACTION, 'An action.');
env_assert(PanelCommands::kind('job:status') === PanelCommands::JOB, 'A job command.');
env_assert(PanelCommands::kind('site:remove') === null && PanelCommands::kind('self:bootstrap') === null, 'Commands this package may not send.');
// 23 of the card, a new site and the owners; 1 of a site's nginx directives (1.41.0) and the confirmation of a timed change (stage 6);
// 3 of the server's alerts (simai-env 1.31.0); the safe update (simai-env 1.32.0); 2 of the services, 4 of a site's scheduled
// jobs, 4 of the firewall and the panel's access (stage 5); the DNS token for certificates (simai-env 1.36.0); 4 of the server's
// PHP versions (simai-env 1.37.0); 3 of the server's software (1.38.0); 7 of a site's files (1.39.0–1.40.0); Apache for a PHP site (1.42.0);
// a program removed (1.43.0); 8 of the server's security (1.45.0–1.47.0); the fix of a rule of a site's check (1.55.1).
env_assert(count(PanelCommands::actions()) === 81, 'The card\'s actions, a new site, the owners, the nginx directives, the alerts, the update, the services, the jobs, the firewall, the DNS token, PHP, the server\'s software, the files, the security, the countries, the MCP keys, the backup storages and the panel\'s address.');
// The addresses that reach the panel are the one list an argument may be; nothing else takes a list.
try {
    PanelCommands::check('file:remove', ['domain' => 'demo-php.test', 'path' => ['public']]);
    env_assert(false, 'Only the panel\'s addresses take a list.');
} catch (\Larena\Env\Gateway\PanelRefused) {
}
// A file's text travels in the request line: up to 60 KB; an archive's name says its format.
PanelCommands::check('file:write', ['domain' => 'demo-php.test', 'path' => 'a.txt', 'content' => "line\n", 'expect-sha256' => 'new']);
foreach ([['file:write', ['content' => str_repeat('a', 61_441)]], ['file:write', ['content' => "a\0b"]], ['file:write', ['content' => 'a', 'expect-sha256' => 'old']],
    ['file:archive', ['to' => 'public.rar']], ['file:archive', ['to' => '../public.zip']]] as [$command, $bad]) {
    try {
        PanelCommands::check($command, $bad + ['domain' => 'demo-php.test', 'path' => 'public', 'content' => '', 'to' => 'x.zip']);
        env_assert(false, $command.' takes what it may only: '.json_encode(array_map(static fn ($v) => substr((string) $v, 0, 20), $bad)));
    } catch (\Larena\Env\Gateway\PanelRefused $refused) {
        env_assert($refused->reasonCode === 'invalid_argument', 'A wrong argument is an invalid argument.');
    }
}
// A Telegram bot's token has its shape; a webhook is https only.
PanelCommands::check('self:alerts-channel', ['kind' => 'telegram', 'target' => '-1001234567', 'token' => '123456789:'.str_repeat('A', 35)]);
try {
    PanelCommands::check('self:alerts-channel', ['kind' => 'webhook', 'target' => 'http://hooks.example.com/a']);
    env_assert(false, 'A plain http webhook is refused.');
} catch (\Larena\Env\Gateway\PanelRefused) {
}

// Only an action is ever confirmed.
$refusedConfirm = null;
try {
    PanelRequest::confirmed(new PanelActor($user), 'site:describe', ['domain' => 'demo-php.test']);
} catch (PanelRefused $refused) {
    $refusedConfirm = $refused->reasonCode;
}
env_assert($refusedConfirm === PanelReason::NOT_ALLOWED, 'A confirmed read is refused here, as the gateway would.');
env_assert(PanelRequest::plan(new PanelActor($user), 'backup:data', ['domain' => 'demo-owned.test'])->toDocument()['confirm'] === false, 'A plan is never confirmed.');
env_assert(PanelRequest::confirmed(new PanelActor($user), 'backup:data', ['domain' => 'demo-owned.test'])->toDocument()['confirm'] === true, 'A confirmation says so.');

// What the action would do, in the plan's own words. Nothing has run.
$plan = $operations->plan(new PanelActor($user), SiteOperation::Backup, 'demo-owned.test', ['db' => 'no']);
env_assert($plan->reason === null && $plan->plan !== null, 'The plan reads: '.(string) $plan->reason);
env_assert($plan->plan->command === 'backup:data' && $plan->plan->target === 'demo-owned.test', 'The plan names its command and target.');
env_assert($plan->plan->effects === 'write' && $plan->plan->lock === 'site' && $plan->plan->args['db'] === 'no', 'Effects, lock and arguments.');
env_assert($plan->plan->description !== '', 'The plan says what it would do.');

// A value the gateway would refuse never leaves this package.
$badEmail = $operations->plan(new PanelActor($user), SiteOperation::CertificateIssue, 'demo-owned.test', ['email' => 'not an address']);
env_assert($badEmail->plan === null && $badEmail->refused && $badEmail->reason === PanelReason::INVALID_ARGUMENT, 'A mistyped email is refused in words.');
$badDomain = $operations->plan(new PanelActor($user), SiteOperation::CertificateRenew, 'not a domain', []);
env_assert($badDomain->plan === null && $badDomain->reason === PanelReason::INVALID_ARGUMENT, 'A mistyped address too.');

// Confirmed: the gateway starts it and answers a job.
$started = $operations->start(new PanelActor($user), SiteOperation::Backup, 'demo-owned.test', ['db' => 'no']);
env_assert($started->jobId === '000000000000000000000000' && $started->reason === null, 'The confirmation starts a job.');

// The job's screen: where it stands and what it printed.
$view = $operations->view(new PanelActor($user), $started->jobId);
env_assert($view->status !== null && $view->status->state === JobState::Succeeded && $view->status->state->isOver(), 'The job is over.');
env_assert($view->status->command === 'backup:data' && $view->status->exitCode === 0 && $view->status->actor === 'admin@stand.test', 'What it was and who started it.');
env_assert($view->log !== null && $view->log->next === 19 && count($view->log->lines) === 19, 'The output and the cursor to read on from.');
env_assert(str_contains(implode("\n", $view->log->lines), 'Data backup'), 'The output is the command\'s own.');

// A job the recording does not hold is said to be missing, never invented.
$unknown = $operations->view(new PanelActor($user), 'ffffffffffffffffffffffff');
env_assert($unknown->status === null && $unknown->reason === PanelReason::RECORDING_MISSING, 'An unknown job: '.(string) $unknown->reason);

// Stopping one is asked of the gateway; this recording has no answer for it, and the screen says so.
$cancelled = $operations->cancel(new PanelActor($user), $started->jobId);
env_assert($cancelled->status === null && $cancelled->reason === PanelReason::RECORDING_MISSING, 'Cancelling is honest about what it does not know.');

// The screen offers only what the site can be asked for.
$site = (new SiteDetailsReader($gateway))->read(new PanelActor($user), 'demo-php.test')->site;
env_assert($site instanceof SiteDetails, 'The site reads.');
$capabilities = (new \Larena\Env\Server\ServerOverviewReader($gateway))->read(new PanelActor($user))->overview?->capabilities;
env_assert($capabilities instanceof \Larena\Env\Gateway\PanelCapabilities, 'The server says what its gateway has.');
$offered = array_map(static fn (SiteOperation $operation): string => $operation->value, SiteOperation::offeredFor($site, $capabilities));
env_assert($offered === ['certificate-issue', 'backup', 'backup-restore-database', 'backup-extract-files', 'backup-verify', 'backup-schedule', 'php-version',
    'php-setting', 'php-setting-remove', 'database-password', 'database-export', 'sftp-create', 'sftp-add-key', 'sftp-disable', 'sftp-enable', 'sftp-remove', 'apache-on'],
    'Without a certificate, a previous release, workers or a service; with 1.28.0\'s backups, php.ini and database, 1.30.0\'s SFTP and 1.42.0\'s Apache: '.implode(',', $offered));
// Apache behind nginx (simai-env 1.42.0): a PHP site nginx serves is offered Apache, one Apache serves nginx alone again,
// a site that is not PHP neither; the switch fixes `enable`, and the system level's alone.
env_assert($site->webServer === 'nginx' && SiteOperation::ApacheOn->arguments('demo-php.test', ['enable' => 'no']) === ['domain' => 'demo-php.test', 'enable' => 'yes']
    && SiteOperation::ApacheOff->arguments('demo-php.test', []) === ['domain' => 'demo-php.test', 'enable' => 'no'], 'The switch says which way.');
$onApache = new SiteDetails('a.test', 'laravel', '8.3', 'none', null, null, null, null, [], null, null, null, null, [], false, webServer: 'apache');
$static = new SiteDetails('s.test', 'static', 'none', 'none', null, null, null, null, [], null, null, null, null, [], false, webServer: 'nginx');
env_assert(SiteOperation::ApacheOff->suitsSite($onApache) && !SiteOperation::ApacheOn->suitsSite($onApache)
    && !SiteOperation::ApacheOn->suitsSite($static) && !SiteOperation::ApacheOff->suitsSite($static), 'Apache is for PHP sites, offered the other way from what serves it.');
env_assert(\Larena\Env\Gateway\PanelCommands::since('site:apache') === '1.42.0' && \Larena\Env\Gateway\PanelCommands::kind('site:apache') === \Larena\Env\Gateway\PanelCommands::ACTION,
    'From 1.42.0, an action.');
$ownerApache = $operations->planCommand(new PanelActor($user, 'demo'), 'site:apache', ['domain' => 'demo-owned.test', 'enable' => 'yes']);
env_assert($ownerApache->plan === null && $ownerApache->reason === 'not_allowed', 'The system level\'s alone: an owner is refused.');
$apachePlan = $operations->planCommand(new PanelActor($user), 'site:apache', ['domain' => 'demo-php.test', 'enable' => 'yes']);
env_assert($apachePlan->plan?->command === 'site:apache' && $apachePlan->plan->args['enable'] === 'yes', 'Apache is planned first.');
env_assert(SiteOperation::offeredFor($site) === [], 'Without knowing the server, nothing is offered.');

// What no simai-env release has yet is offered only when the server lists it among its gateway's commands.
$recorded = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.26.1']]);
env_assert($recorded->allows('backup:data') && !$recorded->allows('owner:block') && !$recorded->allows('backup:data-restore'), 'By the release: 1.26 has backups, not blocking or restoring.');
env_assert(\Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.27.0']])->allows('owner:block'), 'Blocking came with 1.27.0.');
$listed = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.27.0'], 'gate' => ['commands' => ['site:describe', 'backup:data', 'backup:data-restore', 'site:db-rotate']]]);
env_assert($listed->allows('backup:data-restore') && !$listed->allows('site:set-php') && !$listed->allows('site:remove'), 'The server\'s own list wins, and only for commands this panel knows.');
$withRestore = array_map(static fn (SiteOperation $operation): string => $operation->value, SiteOperation::offeredFor($site, $listed));
env_assert($withRestore === ['backup', 'backup-restore-database', 'backup-extract-files', 'database-password', 'database-export'], 'A listed command appears where the site suits it: '.implode(',', $withRestore));

// SFTP access names its account; a site's own new access names the site.
env_assert(SiteOperation::SftpDisable->arguments('demo-php.test', ['login' => 'dev']) === ['login' => 'dev'], 'An account\'s own action does not send the site.');
env_assert(SiteOperation::SftpCreate->arguments('demo-php.test', ['login' => 'dev', 'pubkey' => 'ssh-ed25519 AAAA']) === ['domain' => 'demo-php.test', 'login' => 'dev', 'pubkey' => 'ssh-ed25519 AAAA'], 'A new access is the site\'s.');
foreach (SiteOperation::cases() as $operation) {
    env_assert(PanelCommands::kind($operation->command()) === PanelCommands::ACTION, "{$operation->value} is an action of the table.");
}
env_assert(SiteOperation::Backup->arguments('demo-php.test', ['db' => 'yes', 'files' => ''])  === ['domain' => 'demo-php.test', 'db' => 'yes'], 'An empty field is simply not sent.');
env_assert(SiteOperation::CertificateIssue->fields() === ['email', 'redirect', 'hsts', 'staging', 'challenge', 'wildcard'], 'The fields the certificate asks for; the DNS ones only with a token on the server (1.36.0).');
env_assert(SiteOperation::CertificateIssue->arguments('demo-php.test', ['challenge' => 'dns', 'wildcard' => 'yes']) === ['domain' => 'demo-php.test', 'challenge' => 'dns', 'wildcard' => 'yes'], 'A certificate through DNS.');

echo "PanelOperationsTest passed.\n";
