<?php

declare(strict_types=1);

// The linked audit chain of a change of the server (auditor msg-3d61bd36, point 2): the plan's receipt and the job's
// receipt keep who, the request id, the plan id, the job id and the server's operation id, and the same set is the audit
// event env.server.change_started. Without an audit sink a start is refused before the server is asked; reads go on.

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\PlanTrail;

require_once dirname(__DIR__).'/Support/env-test.php';

// A server that names its operation of every answer, keeps a job's starting request, and counts what it was asked.
$server = new class implements PanelGateway {
    /** @var list<string> */
    private array $asked = [];
    private int $operations = 0;

    /** @return list<string> what was asked since the last call, which forgets it */
    public function asked(): array
    {
        [$asked, $this->asked] = [$this->asked, []];

        return $asked;
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command.($request->confirm ? ':confirm' : '');
        $operation = '2026112310000000000'.(++$this->operations);
        $answer = match (true) {
            $request->command === 'job:status' => ['kind' => 'result', 'data' => ['job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'state' => 'running', 'exit_code' => null,
                'command' => 'backup:data', 'args' => ['domain' => 'demo.test'], 'created_at' => '2026-11-23T10:00:00Z', 'request_id' => 'start-request-id',
                'operation_id' => '20261123100000000009', 'actor' => ['user' => 'user:admin_identity:1', 'owner' => null], 'result' => null]],
            $request->command === 'job:log' => ['kind' => 'result', 'data' => ['job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'lines' => [], 'next' => 0]],
            $request->confirm => ['kind' => 'job', 'job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa'],
            default => ['kind' => 'plan', 'data' => ['command' => $request->command, 'args' => $request->args, 'target' => 'demo.test',
                'description' => 'Back up the site', 'effects' => 'write', 'lock' => 'site']],
        };

        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok',
            'operation_id' => $operation] + $answer);
    }

    public function source(): string
    {
        return 'test';
    }
};

$session = new Store('panel', new ArraySessionHandler(10));
$asking = static function (array $input, PanelActor $actor) use ($session): Request {
    $request = Request::create('/admin/server/x', 'POST', $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, $actor);
    $request->attributes->set('larena_access_actor', $actor->impersonatedBy ?? $actor->user);
    $request->setLaravelSession($session);

    return $request;
};
$admin = new PanelActor('user:admin_identity:1');
$args = ['domain' => 'demo.test', 'db' => 'no'];

// The plan's screen: its own request — the plan answer's ids go into the receipt it gives.
$sink = new InMemoryAuditSink();
$planTrail = new PlanTrail();
$planned = env_operations($server, $sink, $planTrail)->planCommand($admin, 'backup:data', $args);
env_assert($planned->plan !== null && $planned->requestId !== null && $planned->operationId === '20261123100000000001', 'The plan carries its request id and the server\'s operation id.');
$receipt = (new PlanReceipts(900, null, $planTrail))->issue($asking([], $admin), 'backup:data', $args);
$kept = $session->get('larena_env_plan_receipts')[$receipt]['plan'] ?? null;
env_assert($kept === ['command' => 'backup:data', 'plan_id' => $planned->requestId, 'plan_operation_id' => '20261123100000000001'],
    'The plan\'s receipt keeps the plan id and its operation id: '.json_encode($kept));

// The confirmation: another request, another trail; the receipt hands the plan to the start.
$runTrail = new PlanTrail();
env_assert((new PlanReceipts(900, null, $runTrail))->confirms($asking([PlanReceipts::FIELD => $receipt], $admin), 'backup:data', $args), 'The receipt confirms.');
$started = env_operations($server, $sink, $runTrail)->startCommand($admin, 'backup:data', $args);
env_assert($started->jobId === 'aaaaaaaaaaaaaaaaaaaaaaaa' && $started->reason === null, 'The job starts.');
$chain = $started->chain();
env_assert(array_keys($chain) === ['who', 'command', 'request_id', 'plan_id', 'plan_operation_id', 'job_id', 'operation_id'], 'The job\'s receipt holds the whole chain: '.json_encode($chain));
env_assert($chain['who'] === 'user:admin_identity:1' && $chain['plan_id'] === $planned->requestId && $chain['plan_operation_id'] === '20261123100000000001'
    && $chain['job_id'] === 'aaaaaaaaaaaaaaaaaaaaaaaa' && $chain['operation_id'] === '20261123100000000002' && $chain['request_id'] !== $chain['plan_id'], 'Who, the plan, the job and the operation.');
$events = $sink->events();
env_assert(count($events) === 1 && $events[0]->type === PanelOperations::CHANGE_STARTED, 'One audit event of the change.');
env_assert($events[0]->payload === $chain && $events[0]->actor === 'user:admin_identity:1' && $events[0]->subject === 'demo.test'
    && $events[0]->correlationId === $planned->requestId, 'The audit event has the same set, correlated by the plan id: '.json_encode($events[0]->payload));

// The job's screen: the request that started it and the server's operation id of the job.
$view = env_operations($server, $sink)->view($admin, 'aaaaaaaaaaaaaaaaaaaaaaaa');
env_assert($view->status !== null && $view->requestId === 'start-request-id' && $view->operationId === '20261123100000000009', 'The job\'s view carries the chain.');

// Acting as someone: who did it, and for whom.
$acting = new PanelActor('user:admin_identity:5', 'demo', 'user:admin_identity:1');
$forThem = env_operations($server, $sink)->startCommand($acting, 'backup:data', $args);
env_assert($forThem->chain()['who'] === 'user:admin_identity:1' && $forThem->chain()['on_behalf_of'] === 'user:admin_identity:5'
    && !isset($forThem->chain()['plan_id']), 'Acting as someone: the person who did it and the one it was for; a start of no plan names none.');
env_assert($sink->events()[1]->actor === 'user:admin_identity:1', 'The audit event\'s actor is the person who did it.');

// Without an audit sink: a start is refused in words, before the server is asked; plans and reads go on.
$server->asked();
$unaudited = new PanelOperations($server);
$refused = $unaudited->startCommand($admin, 'backup:data', $args);
env_assert($refused->jobId === null && $refused->refused && $refused->reason === PanelReason::AUDIT_UNAVAILABLE, 'No audit log: the start is refused.');
env_assert($server->asked() === [], 'Nothing was asked of the server.');
$words = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
env_assert(is_string($words['reasons'][PanelReason::AUDIT_UNAVAILABLE] ?? null), 'The refusal is said in words.');
env_assert($unaudited->planCommand($admin, 'backup:data', $args)->plan !== null && $unaudited->view($admin, 'aaaaaaaaaaaaaaaaaaaaaaaa')->status !== null,
    'A plan and a read still work.');
$reads = $server->asked();
env_assert($reads === ['backup:data', 'job:status', 'job:log'], 'Only reads went to the server: '.json_encode($reads));
// A container with no sink bound is the same as none.
$noSink = new PanelOperations($server, new \Larena\Env\People\PanelAudit(new \Illuminate\Container\Container()));
env_assert($noSink->startCommand($admin, 'backup:data', $args)->reason === PanelReason::AUDIT_UNAVAILABLE, 'An unbound sink refuses the start too.');

// Stopping a job changes the server too (wave A's addition c): refused without the audit log before the server is asked,
// and a stop the server took is written with who, for whom, the stop's request and operation, and the job with the request
// that started it, correlated by the job — its env.server.change_started names the same job.
$server->asked();
env_assert($unaudited->cancel($admin, 'aaaaaaaaaaaaaaaaaaaaaaaa')->reason === PanelReason::AUDIT_UNAVAILABLE && $server->asked() === [],
    'No audit log: stopping a job is refused before the server is asked.');
$stopSink = new InMemoryAuditSink();
$stopped = env_operations($server, $stopSink)->cancel($acting, 'aaaaaaaaaaaaaaaaaaaaaaaa');
env_assert($stopped->status !== null && $stopped->reason === null && $server->asked() === ['job:cancel', 'job:status', 'job:log'], 'The job is stopped and shown.');
$stops = $stopSink->events();
$stop = $stops[0]->payload ?? [];
env_assert(count($stops) === 1 && $stops[0]->type === PanelOperations::JOB_CANCELLED && $stops[0]->actor === 'user:admin_identity:1'
    && $stops[0]->subject === 'env.job:aaaaaaaaaaaaaaaaaaaaaaaa' && $stops[0]->correlationId === 'aaaaaaaaaaaaaaaaaaaaaaaa'
    && $stop['who'] === 'user:admin_identity:1' && $stop['on_behalf_of'] === 'user:admin_identity:5' && $stop['command'] === 'job:cancel'
    && $stop['job_id'] === 'aaaaaaaaaaaaaaaaaaaaaaaa' && $stop['job_request_id'] === 'start-request-id' && $stop['job_operation_id'] === '20261123100000000009'
    && is_string($stop['request_id'] ?? null) && is_string($stop['operation_id'] ?? null), 'The stop is in the audit log with its chain: '.json_encode($stop));
env_assert($stop['job_id'] === $chain['job_id'], 'The same job as the start\'s event names.');
// A stop the server refused (a finished job) writes nothing.
$finished = new class implements PanelGateway {
    public function ask(PanelRequest $request): PanelAnswer
    {
        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'refused',
            'reason' => 'not_running']);
    }
    public function source(): string { return 'test'; }
};
$quiet = new InMemoryAuditSink();
env_assert(env_operations($finished, $quiet)->cancel($admin, 'aaaaaaaaaaaaaaaaaaaaaaaa')->reason === 'not_running' && $quiet->events() === [], 'A refused stop is not a change.');

echo "AuditChainTest passed.\n";
