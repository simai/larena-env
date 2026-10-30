<?php

declare(strict_types=1);

// The SSH driver through an injected process executor, then once through the real one with a stand-in for ssh.

use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcessStopReason;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Process\RecordingProcessExecutor;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Gateway\SshGateway;
use Larena\Env\Gateway\SshGatewayAdapter;
use Larena\Env\Gateway\SshGatewayTarget;
use Larena\Env\Gateway\UnconfiguredGateway;

require_once dirname(__DIR__).'/Support/env-test.php';

$secrets = env_temp_directory();
$key = $secrets.'/panel_key';
$knownHosts = $secrets.'/known_hosts';
file_put_contents($key, 'not a real key');
file_put_contents($knownHosts, '');
$target = new SshGatewayTarget('127.0.0.1', 'simai-panel', 12222, $key, $knownHosts);
$actor = new PanelActor('admin@example.com');

/** @return array{PanelAnswer, ProcessSpecification} */
function env_ssh_ask(SshGatewayTarget $target, PanelRequest $request, ProcessResult $result): array
{
    $executor = new RecordingProcessExecutor($result);
    $answer = (new SshGateway($target, $executor))->ask($request);

    return [$answer, $executor->recorded()[0]];
}

function env_answer_to(string $id, string $file): string
{
    $document = json_decode(env_recorded_bytes($file), true, 64, JSON_THROW_ON_ERROR);
    $document['request_id'] = $id;

    return json_encode($document, JSON_THROW_ON_ERROR);
}

// The invocation: no remote command, no shell, nothing of the request in argv.
$request = new PanelRequest('r1', new PanelActor("Rim '; rm -rf / #", 'demo'), 'site:describe', ['domain' => 'demo-owned.test']);
[$answer, $spec] = env_ssh_ask($target, $request, ProcessResult::finished(0, env_answer_to('r1', 'site-describe.demo-php.test.json')));
env_assert($spec->argv === [
    '/usr/bin/ssh', '-F', 'none', '-T', '-i', $key, '-p', '12222',
    '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none', '-o', 'StrictHostKeyChecking=yes',
    '-o', 'UserKnownHostsFile='.$knownHosts, '-o', 'ConnectTimeout=10', '-o', 'ForwardAgent=no', '-o', 'ForwardX11=no',
    '-o', 'ClearAllForwardings=yes', '-o', 'LogLevel=ERROR', '--', 'simai-panel@127.0.0.1',
], 'The argv is exactly the configured target: '.implode(' ', $spec->argv));
foreach (['rm -rf', 'demo-owned', 'site:describe', 'admin@'] as $data) {
    env_assert(!str_contains(implode(' ', $spec->argv), $data), "Request data must not reach argv: {$data}.");
}
env_assert($spec->environment === [], 'The child inherits nothing: no agent socket, no secret.');
env_assert($spec->stdin === $request->toLine(), 'The request travels on stdin as one line.');
env_assert($answer->status === PanelAnswerStatus::Ok && $answer->kind === 'result' && $answer->data['domain'] === 'demo-php.test', 'A result is read.');
env_assert($answer->operationId === 'fixture' && $answer->commandStatus !== null, 'Its fields are kept.');

// The recordings read the same through this driver.
foreach (['refused.owner-scope.json' => 'owner_scope', 'refused.not-allowed.json' => 'not_allowed'] as $file => $reason) {
    [$refusal] = env_ssh_ask($target, new PanelRequest('r2', $actor, 'self:describe'), ProcessResult::finished(0, env_recorded_bytes($file)));
    if ($file === 'refused.owner-scope.json') {
        env_assert($refusal->reason === 'gateway_answer_invalid', 'That recording answers another request id, so it is not taken for this one.');
        [$refusal] = env_ssh_ask($target, new PanelRequest('r2', $actor, 'self:describe'), ProcessResult::finished(0, env_answer_to('r2', $file)));
    }
    env_assert($refusal->status === PanelAnswerStatus::Refused && $refusal->reason === $reason, "The gateway's refusal {$reason} is a reason code.");
}
[$failed] = env_ssh_ask($target, new PanelRequest('r3', $actor, 'self:describe'), ProcessResult::finished(0, json_encode(
    ['schema' => 'simai-panel-answer/1', 'request_id' => 'r3', 'status' => 'failed', 'reason' => 'invalid_output'], JSON_THROW_ON_ERROR)));
env_assert($failed->status === PanelAnswerStatus::Failed && $failed->reason === 'invalid_output', 'A gateway failure keeps its code.');

// What can go wrong on the way; the key's path never comes back.
$ask = static fn (ProcessResult $result): PanelAnswer => env_ssh_ask($target, new PanelRequest('r4', $actor, 'self:describe'), $result)[0];
$noisy = "Load key \"{$key}\": invalid format\nPermission denied (publickey).\n";
$cases = [
    'ssh failed' => [ProcessResult::finished(255, '', $noisy), 'gateway_process_failed'],
    'not json' => [ProcessResult::finished(0, 'Welcome to Ubuntu', $noisy), 'gateway_answer_invalid'],
    'json list' => [ProcessResult::finished(0, '[1,2]'), 'gateway_answer_invalid'],
    'no schema' => [ProcessResult::finished(0, '{"status":"ok"}'), 'gateway_answer_invalid'],
    'another protocol' => [ProcessResult::finished(0, '{"schema":"other/1","status":"ok"}'), 'gateway_answer_invalid'],
    'another version' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/2","request_id":"r4","status":"ok"}'), 'gateway_protocol_mismatch'],
    'another request' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"other","status":"ok","kind":"result","data":{}}'), 'gateway_answer_invalid'],
    'ok without id' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":null,"status":"ok","kind":"result","data":{}}'), 'gateway_answer_invalid'],
    'unknown status' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"r4","status":"maybe"}'), 'gateway_answer_invalid'],
    'unknown kind' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"r4","status":"ok","kind":"odd","data":{}}'), 'gateway_answer_invalid'],
    'result without data' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"r4","status":"ok","kind":"result"}'), 'gateway_answer_invalid'],
    'refusal without reason' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"r4","status":"refused"}'), 'gateway_answer_invalid'],
    'reason with markup' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"r4","status":"refused","reason":"<b>x</b>"}'), 'gateway_answer_invalid'],
    'not started' => [ProcessResult::notStarted(), 'gateway_process_failed'],
    'timed out' => [new ProcessResult(true, 1, null, true, true, ProcessStopReason::Timeout, true, '', $noisy, 0, strlen($noisy), false, false, '', '', 30.0), 'gateway_process_failed'],
    'cut output' => [new ProcessResult(true, 1, null, true, false, ProcessStopReason::StdoutLimit, true, '{', '', 9, 0, true, false, '', '', 1.0), 'gateway_process_failed'],
];
foreach ($cases as $name => [$result, $reason]) {
    $outcome = $ask($result);
    env_assert($outcome->status === PanelAnswerStatus::Failed && $outcome->reason === $reason, "{$name}: expected {$reason}, got {$outcome->reason}.");
    env_assert(!str_contains(serialize($outcome), $key) && !str_contains(serialize($outcome), 'Permission denied'), "{$name}: ssh's stderr must not reach the answer.");
}

// A missing credential is said before anything runs.
$untouched = new RecordingProcessExecutor();
$unreadable = new SshGateway(new SshGatewayTarget('127.0.0.1', 'simai-panel', 22, $secrets.'/absent', $knownHosts), $untouched);
env_assert($unreadable->ask(new PanelRequest('r5', $actor, 'self:describe'))->reason === 'gateway_credentials_unreadable', 'An unreadable key is a reason.');
env_assert($untouched->recorded() === [], 'Nothing ran.');

// The manifest declares reads only.
$manifest = (new SshGatewayAdapter($target))->manifest();
env_assert($manifest->commands === PanelCommands::names() && $manifest->protocol === 'simai-panel' && $manifest->protocolVersion === 1, 'The adapter declares the protocol and the commands.');
env_assert(!$manifest->allows('site:remove') && !$manifest->allows('ssl:renew'), 'No action can pass the runner.');

// The target refuses what could become an option or leave the configured form.
foreach ([
    ['-oProxyCommand=x', 'simai-panel', 22, $key, $knownHosts],
    ['host;id', 'simai-panel', 22, $key, $knownHosts],
    ['127.0.0.1', '-luser', 22, $key, $knownHosts],
    ['127.0.0.1', 'simai-panel', 0, $key, $knownHosts],
    ['127.0.0.1', 'simai-panel', 22, 'relative/key', $knownHosts],
    ['127.0.0.1', 'simai-panel', 22, $key, '/with space'],
] as $bad) {
    try {
        new SshGatewayTarget(...$bad);
        env_assert(false, 'A bad target must be refused: '.implode(' ', array_map('strval', $bad)));
    } catch (InvalidArgumentException $refused) {
        env_assert($refused->getMessage() === 'ssh_gateway_target_invalid', 'The refusal names no value.');
    }
}

// The factory fails closed.
$executorFactory = static fn () => new RecordingProcessExecutor();
$settings = ['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path()], 'ssh' => ['host' => '', 'user' => 'simai-panel', 'key_path' => '', 'known_hosts_path' => '']];
env_assert(PanelGatewayFactory::make($settings, true, $executorFactory) instanceof RecordedAnswersGateway, 'Recorded answers in development.');
env_assert(PanelGatewayFactory::make($settings, false, $executorFactory) instanceof UnconfiguredGateway, 'Never recorded answers elsewhere, production above all.');
env_assert(PanelGatewayFactory::make(['driver' => 'ssh'] + $settings, true, $executorFactory) instanceof UnconfiguredGateway, 'An incomplete target is no gateway.');
env_assert(PanelGatewayFactory::make(['driver' => 'odd'] + $settings, true, $executorFactory) instanceof UnconfiguredGateway, 'An unknown driver is no gateway.');
$live = ['driver' => 'ssh', 'ssh' => ['host' => 'server.example.com', 'user' => 'simai-panel', 'port' => 22, 'key_path' => $key, 'known_hosts_path' => $knownHosts]];
env_assert(PanelGatewayFactory::make($live, false, $executorFactory) instanceof SshGateway, 'A complete target gives the live driver.');
$none = (new UnconfiguredGateway())->ask(new PanelRequest('r6', $actor, 'self:describe'));
env_assert($none->status === PanelAnswerStatus::Failed && $none->reason === 'gateway_not_configured', 'No gateway says so.');

// The real executor with a stand-in for ssh: stdin arrives whole, the answer comes back, the environment stays empty.
$standIn = $secrets.'/ssh';
$received = $secrets.'/received';
file_put_contents($standIn, "#!/bin/sh\n/bin/cat > ".escapeshellarg($received)."\n/usr/bin/env > ".escapeshellarg($received.'.env')."\necho '{\"schema\":\"simai-panel-answer/1\",\"request_id\":null,\"status\":\"refused\",\"reason\":\"not_allowed\"}'\n");
chmod($standIn, 0700);
$realTarget = new SshGatewayTarget('127.0.0.1', 'simai-panel', 12222, $key, $knownHosts, $standIn);
$realRequest = new PanelRequest('r7', $actor, 'self:describe');
$real = (new SshGateway($realTarget, new ProcOpenProcessExecutor()))->ask($realRequest);
env_assert($real->status === PanelAnswerStatus::Refused && $real->reason === 'not_allowed', 'The real executor carries the answer back: '.($real->reason ?? 'ok'));
env_assert(file_get_contents($received) === $realRequest->toLine(), 'The real executor carries the request on stdin.');
env_assert(!str_contains((string) file_get_contents($received.'.env'), 'SSH_AUTH_SOCK'), 'The parent\'s environment is not inherited.');

echo "SshGatewayTest passed.\n";
