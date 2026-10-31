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
env_assert(count(PanelCommands::actions()) === 9, 'The six actions of the site\'s card, its PHP version, a new site and a new owner.');

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
env_assert($view->log !== null && $view->log->next === 18 && count($view->log->lines) === 18, 'The output and the cursor to read on from.');
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
$offered = array_map(static fn (SiteOperation $operation): string => $operation->value, SiteOperation::offeredFor($site));
env_assert($offered === ['certificate-issue', 'backup', 'php-version'], 'Without a certificate, a previous release, workers or a service: '.implode(',', $offered));
env_assert(SiteOperation::Backup->arguments('demo-php.test', ['db' => 'yes', 'files' => ''])  === ['domain' => 'demo-php.test', 'db' => 'yes'], 'An empty field is simply not sent.');
env_assert(SiteOperation::CertificateIssue->fields() === ['email', 'redirect', 'hsts', 'staging'], 'The fields the certificate asks for.');

echo "PanelOperationsTest passed.\n";
