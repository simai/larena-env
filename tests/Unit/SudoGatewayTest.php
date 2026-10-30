<?php

declare(strict_types=1);

// The local sudo driver through an injected process executor, then once through the real one with a stand-in for sudo.

use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Process\RecordingProcessExecutor;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelGatewayFactory;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\SudoGateway;
use Larena\Env\Gateway\SudoGatewayTarget;
use Larena\Env\Gateway\UnconfiguredGateway;

require_once dirname(__DIR__).'/Support/env-test.php';

$actor = new PanelActor('admin@example.com');
$answerFor = static function (string $id): string {
    $document = json_decode(env_recorded_bytes('site-describe.demo-php.test.json'), true, 64, JSON_THROW_ON_ERROR);
    $document['request_id'] = $id;

    return json_encode($document, JSON_THROW_ON_ERROR);
};

// The invocation: exactly `sudo -n <gate>`, because the sudo rule allows the gate with no arguments at all.
$request = new PanelRequest('r1', new PanelActor("Rim '; rm -rf / #", 'demo'), 'site:describe', ['domain' => 'demo-owned.test']);
$executor = new RecordingProcessExecutor(ProcessResult::finished(0, $answerFor('r1')));
$gateway = new SudoGateway(new SudoGatewayTarget(), $executor);
$answer = $gateway->ask($request);
$spec = $executor->recorded()[0];
env_assert($spec->argv === ['/usr/bin/sudo', '-n', '/usr/local/sbin/simai-panel-gate'], 'The argv is fixed: '.implode(' ', $spec->argv));
env_assert($spec->environment === [], 'The child inherits nothing.');
env_assert($spec->stdin === $request->toLine(), 'The request travels on stdin as one line.');
env_assert($gateway->source() === 'sudo', 'The driver names its source.');
env_assert($answer->status === PanelAnswerStatus::Ok && $answer->data['domain'] === 'demo-php.test', 'A result is read.');

// What can go wrong: sudo's stderr never comes back.
$noisy = "sudo: a password is required\n";
foreach ([
    'no sudo rule' => [ProcessResult::finished(1, '', $noisy), 'gateway_process_failed'],
    'not started' => [ProcessResult::notStarted(), 'gateway_process_failed'],
    'not json' => [ProcessResult::finished(0, 'hello', $noisy), 'gateway_answer_invalid'],
    'another request' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":"other","status":"ok","kind":"result","data":{}}'), 'gateway_answer_invalid'],
    'another version' => [ProcessResult::finished(0, '{"schema":"simai-panel-answer/2","request_id":"r2","status":"ok"}'), 'gateway_protocol_mismatch'],
] as $name => [$result, $reason]) {
    $outcome = (new SudoGateway(new SudoGatewayTarget(), new RecordingProcessExecutor($result)))->ask(new PanelRequest('r2', $actor, 'self:describe'));
    env_assert($outcome->status === PanelAnswerStatus::Failed && $outcome->reason === $reason, "{$name}: expected {$reason}, got {$outcome->reason}.");
    env_assert(!str_contains(serialize($outcome), 'password is required'), "{$name}: sudo's stderr must not reach the answer.");
}
$refused = (new SudoGateway(new SudoGatewayTarget(), new RecordingProcessExecutor(ProcessResult::finished(0, '{"schema":"simai-panel-answer/1","request_id":null,"status":"refused","reason":"bad_actor"}'))))
    ->ask(new PanelRequest('r3', $actor, 'self:describe'));
env_assert($refused->status === PanelAnswerStatus::Refused && $refused->reason === 'bad_actor', "The gateway's refusal is a reason code.");

// A target cannot become an option or carry arguments.
foreach ([['sudo'], ['/usr/bin/sudo', 'gate'], ['/usr/bin/sudo', '/gate --evil'], ['/usr/bin/sudo', '/gate', 0.0]] as $bad) {
    try {
        new SudoGatewayTarget(...$bad);
        env_assert(false, 'A bad target must be refused: '.implode(' ', array_map('strval', $bad)));
    } catch (InvalidArgumentException $invalid) {
        env_assert($invalid->getMessage() === 'sudo_gateway_target_invalid', 'The refusal names no value.');
    }
}

// The factory: sudo works in production, a bad target is no gateway.
$factory = static fn () => new RecordingProcessExecutor();
env_assert(PanelGatewayFactory::make(['driver' => 'sudo', 'sudo' => []], false, $factory) instanceof SudoGateway, 'The driver with its defaults.');
env_assert(PanelGatewayFactory::make(['driver' => 'sudo', 'sudo' => ['gate' => 'relative']], false, $factory) instanceof UnconfiguredGateway, 'A bad target is no gateway.');
$config = require dirname(__DIR__, 2).'/config/env.php';
putenv('LARENA_ENV_GATEWAY');
$config = require dirname(__DIR__, 2).'/config/env.php';
env_assert($config['gateway']['driver'] === 'sudo', 'sudo is the default on a server.');
env_assert(PanelGatewayFactory::make($config['gateway'], false, $factory) instanceof SudoGateway, 'The default configuration gives the sudo driver.');

// The real executor with a stand-in for sudo: arguments, stdin and the answer.
$directory = env_temp_directory();
$standIn = $directory.'/sudo';
file_put_contents($standIn, "#!/bin/sh\necho \"\$@\" > ".escapeshellarg($directory.'/argv')."\n/bin/cat > ".escapeshellarg($directory.'/stdin')."\necho '{\"schema\":\"simai-panel-answer/1\",\"request_id\":null,\"status\":\"refused\",\"reason\":\"not_allowed\"}'\n");
chmod($standIn, 0700);
$realRequest = new PanelRequest('r4', $actor, 'self:describe');
$real = (new SudoGateway(new SudoGatewayTarget($standIn, '/usr/local/sbin/simai-panel-gate'), new ProcOpenProcessExecutor()))->ask($realRequest);
env_assert($real->reason === 'not_allowed', 'The real executor carries the answer back: '.($real->reason ?? 'ok'));
env_assert(trim((string) file_get_contents($directory.'/argv')) === '-n /usr/local/sbin/simai-panel-gate', 'The real executor passes only the gate.');
env_assert(file_get_contents($directory.'/stdin') === $realRequest->toLine(), 'The real executor carries the request on stdin.');

echo "SudoGatewayTest passed.\n";
