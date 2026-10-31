<?php

declare(strict_types=1);

// Recorded answers replay what the gateway said, and a file that no longer matches its manifest is refused.

use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;

require_once dirname(__DIR__).'/Support/env-test.php';

$gateway = new RecordedAnswersGateway(env_recorded_path());
$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('demo@example.com', 'demo');

env_assert($gateway->source() === 'recorded', 'The driver names its source.');

$answer = $gateway->ask(new PanelRequest('r1', $admin, 'self:describe'));
env_assert($answer->status === PanelAnswerStatus::Ok && $answer->kind === 'result', 'The host description replays.');
env_assert($answer->requestId === 'r1', 'The answer is to this request.');
env_assert(count($answer->data['sites']) === 4 && $answer->commandStatus === 'success', 'The recording has its four sites.');

$own = $gateway->ask(new PanelRequest('r2', $owner, 'self:describe'));
env_assert(array_column($own->data['sites'], 'domain') === ['demo-owned.test'], 'An owner gets the owner\'s recording.');

$site = $gateway->ask(new PanelRequest('r3', $admin, 'site:describe', ['domain' => 'demo-php.test']));
env_assert($site->status === PanelAnswerStatus::Ok && $site->data['domain'] === 'demo-php.test', 'A site description replays.');

$scope = $gateway->ask(new PanelRequest('r4', $owner, 'site:describe', ['domain' => 'demo-php.test']));
env_assert($scope->status === PanelAnswerStatus::Refused && $scope->reason === 'owner_scope', 'Another owner\'s site is the recorded refusal.');

$missing = $gateway->ask(new PanelRequest('r5', $admin, 'site:describe', ['domain' => 'unknown.test']));
env_assert($missing->status === PanelAnswerStatus::Failed && $missing->reason === 'recording_missing', 'No recording is a reason, not an exception.');
$stranger = $gateway->ask(new PanelRequest('r6', new PanelActor('x@example.com', 'stranger'), 'self:describe'));
env_assert($stranger->reason === 'recording_missing', 'An owner without a recording.');

// A copy that drifted from its manifest.
$copy = env_temp_directory();
foreach (glob(env_recorded_path().'/*.json') ?: [] as $file) {
    copy($file, $copy.'/'.basename($file));
}
file_put_contents($copy.'/self-describe.json', str_replace('demo-php.test', 'evil.test', (string) file_get_contents($copy.'/self-describe.json')));
$tampered = (new RecordedAnswersGateway($copy))->ask(new PanelRequest('r7', $admin, 'self:describe'));
env_assert($tampered->status === PanelAnswerStatus::Failed && $tampered->reason === 'recording_tampered', 'A changed recording is refused.');

$manifest = json_decode((string) file_get_contents($copy.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest['answer_schema'] = 'simai-panel-answer/2';
file_put_contents($copy.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
$future = (new RecordedAnswersGateway($copy))->ask(new PanelRequest('r8', $admin, 'site:describe', ['domain' => 'demo-php.test']));
env_assert($future->reason === 'recording_tampered', 'A set in another answer schema is refused.');

$empty = (new RecordedAnswersGateway(env_temp_directory()))->ask(new PanelRequest('r9', $admin, 'self:describe'));
env_assert($empty->reason === 'recording_tampered', 'A folder without a manifest is refused.');

// As the gateway: an owner plans a new site of any domain, and is refused making owners.
$ownNew = $gateway->ask(PanelRequest::plan($owner, 'site:add', ['domain' => 'new.test', 'profile' => 'static']));
env_assert($ownNew->status === PanelAnswerStatus::Ok && $ownNew->kind === 'plan', 'An owner plans a new site.');
$ownerMakesOwner = $gateway->ask(PanelRequest::plan($owner, 'owner:create', ['name' => 'other']));
env_assert($ownerMakesOwner->status === PanelAnswerStatus::Refused && $ownerMakesOwner->reason === 'not_allowed', 'An owner may not make owners.');

echo "RecordedAnswersGatewayTest passed.\n";
