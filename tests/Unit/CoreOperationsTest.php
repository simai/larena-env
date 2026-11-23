<?php

declare(strict_types=1);

// The panel's changes as declared Core operations (auditor msg-3ee29ace, point 4): every one registered through Core's
// policy, planned and started by its Larena name through Core's runtime, refused without its right or without the audit
// log before the server is asked, and written with its name and reversibility in env.server.change_started.

use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Operations\EnvOperationCall;
use Larena\Env\Operations\EnvOperationCatalog;
use Larena\Env\Operations\EnvOperationProvider;
use Larena\Env\Operations\EnvOperations;
use Larena\Env\Operations\PanelOperations;

require_once dirname(__DIR__).'/Support/env-test.php';

// Registration: Core's policy takes all 122, each by the right of its group; with env.dns off its 5 are absent.
$registry = DeclaredOperationRegistry::fromProviders([new EnvOperationProvider()]);
$listed = $registry->list();
env_assert(count($listed) === 122 && count(EnvOperationCatalog::names()) === 122, 'Core registers the 122 operations: '.count($listed));
$withoutDns = DeclaredOperationRegistry::fromProviders([new EnvOperationProvider(static fn (string $feature): bool => $feature !== 'env.dns')]);
env_assert(count($withoutDns->list()) === 117, 'Without env.dns its operations (the four of DNS and a site pointed by DNS) are absent: '.count($withoutDns->list()));
$carried = [];
foreach (EnvOperationCatalog::names() as $name) {
    $command = EnvOperationCatalog::command($name);
    if ($command !== null) {
        $carried[$command] = true;
    }
}
$missing = array_diff(PanelCommands::actions(), array_keys($carried));
env_assert($missing === [] && isset($carried['job:cancel']), 'Every action and the stop are carried: '.implode(', ', $missing));

// A server that answers plans and jobs and counts what it was asked.
$server = new class implements PanelGateway {
    /** @var list<string> */
    public array $asked = [];

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command.($request->confirm ? ':confirm' : '');
        $answer = match (true) {
            $request->command === 'job:status' => ['kind' => 'result', 'data' => ['job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'state' => 'cancelled', 'exit_code' => null,
                'command' => 'backup:data', 'args' => ['domain' => 'demo.test'], 'created_at' => '2026-11-23T10:00:00Z', 'request_id' => 'start-request-id',
                'operation_id' => '20261123100000000009', 'actor' => ['user' => 'user:admin_identity:1', 'owner' => null], 'result' => null]],
            $request->command === 'job:log' => ['kind' => 'result', 'data' => ['job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'lines' => [], 'next' => 0]],
            $request->command === 'job:cancel', $request->confirm => ['kind' => 'job', 'job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa'],
            default => ['kind' => 'plan', 'data' => ['command' => $request->command, 'args' => $request->args, 'target' => 'demo.test',
                'description' => 'Back up the site', 'effects' => 'write', 'lock' => 'site']],
        };

        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok',
            'operation_id' => '20261123100000000001'] + $answer);
    }

    /** @return list<string> what was asked since the last call, which forgets it */
    public function taken(): array
    {
        [$asked, $this->asked] = [$this->asked, []];

        return $asked;
    }

    public function source(): string
    {
        return 'test';
    }
};
$admin = new PanelActor('user:admin_identity:1');
$args = ['domain' => 'demo.test', 'db' => 'no'];

// Planned and started by name: Core asks the operation's right, writes its decision and result, the job starts.
$sink = new InMemoryAuditSink();
$gate = new EnvTestAccessGate();
$audit = new EnvTestOperationAudit();
$operations = env_facade($server, $sink, gate: $gate, audit: $audit);
$planned = $operations->plan($admin, 'env.backup.make', $args);
env_assert($planned->plan !== null && $server->taken() === ['backup:data'], 'The plan is the operation\'s proposal.');
$started = $operations->start($admin, 'env.backup.make', $args);
env_assert($started->jobId === 'aaaaaaaaaaaaaaaaaaaaaaaa' && $started->reason === null, 'The job starts.');
env_assert($started->chain()['operation'] === 'env.backup.make' && $started->chain()['reversible'] === 'true' && $started->chain()['command'] === 'backup:data',
    'The receipt names the operation and its reversibility: '.json_encode($started->chain()));
env_assert(array_column($gate->asked, 'right') === ['env.sites.backup', 'env.sites.backup'], 'Core asks the operation\'s right for the plan and for the start: '.json_encode($gate->asked));
env_assert(in_array('env.server.change_started.result', array_column($audit->events, 'type'), true), 'Core writes its result: '.json_encode($audit->events));
$events = $sink->events();
env_assert(count($events) === 1 && $events[0]->type === PanelOperations::CHANGE_STARTED && $events[0]->payload['operation'] === 'env.backup.make'
    && $events[0]->payload['reversible'] === 'true', 'One env.server.change_started with the operation and its reversibility: '.json_encode($events[0]->payload ?? null));

// An irreversible one says so.
$emptied = $operations->start($admin, 'env.file.trash_empty', ['domain' => 'demo.test']);
env_assert($emptied->reason === null && ($emptied->chain()['reversible'] ?? null) === 'false' && array_slice($sink->events(), -1)[0]->payload['reversible'] === 'false',
    'Emptying the trash is not reversible, and says so: '.json_encode($emptied->chain()));

// The right not held: refused not_allowed, the server not asked.
$server->taken();
$denied = env_facade($server, $sink, gate: new EnvTestAccessGate(static fn (string $actor, string $right): bool => $right !== 'env.sites.backup'));
env_assert($denied->plan($admin, 'env.backup.make', $args)->reason === PanelReason::NOT_ALLOWED
    && $denied->start($admin, 'env.backup.make', $args)->reason === PanelReason::NOT_ALLOWED && $server->taken() === [], 'Without the right nothing is asked.');

// Core's audit failing: refused audit_unavailable before the job.
$failing = env_facade($server, $sink, audit: new EnvTestOperationAudit(true));
env_assert($failing->start($admin, 'env.backup.make', $args)->reason === PanelReason::AUDIT_UNAVAILABLE && !in_array('backup:data:confirm', $server->taken(), true),
    'Core\'s audit log down: the start is refused.');

// No runtime (no audit log in the application): nothing is planned or started; an unknown name is not allowed.
$none = new EnvOperations(env_operations($server, $sink), null, new EnvOperationCall());
env_assert($none->plan($admin, 'env.backup.make', $args)->reason === PanelReason::AUDIT_UNAVAILABLE
    && $none->start($admin, 'env.backup.make', $args)->reason === PanelReason::AUDIT_UNAVAILABLE, 'No runtime: refused.');
env_assert($operations->start($admin, 'env.backup.explode', $args)->reason === PanelReason::NOT_ALLOWED, 'An unknown operation is not allowed.');
env_assert($operations->start($admin, 'env.backup.make', ['domain' => 'demo.test', 'db' => 'no', 'extra' => 'x'])->refused, 'Input outside the contract is refused.');

// Stopping a job: env.job.cancel, written with the operation and reversible false.
$stopSink = new InMemoryAuditSink();
$stopped = env_facade($server, $stopSink)->cancel($admin, 'aaaaaaaaaaaaaaaaaaaaaaaa');
$stop = $stopSink->events()[0] ?? null;
env_assert($stopped->reason === null && $stop !== null && $stop->type === PanelOperations::JOB_CANCELLED && $stop->payload['operation'] === 'env.job.cancel'
    && $stop->payload['reversible'] === 'false', 'The stop is written with its operation: '.json_encode($stop?->payload));

echo "CoreOperationsTest passed.\n";
