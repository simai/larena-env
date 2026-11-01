<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Throwable;

/**
 * A stand-in for the server's stage 5 commands (Panel plan 5.1–5.5) on recorded answers, for tests and the install check
 * only: no simai-env release has them yet, so no recording exists. It answers the services, the kinds of site, a site's
 * scheduled jobs, the firewall and who reaches the panel the way the agreed protocol does, keeps its state in a local
 * folder, and lists the commands in the host description; everything else goes to the gateway it wraps. A firewall or
 * panel change is applied and undone by itself unless change:confirm keeps it in time, as the server does; SSH and the
 * panel's own port can never be closed.
 */
final readonly class FakeServerToolsGateway implements PanelGateway, PanelFileGateway
{
    /** The commands this stand-in answers; an owner is offered the kinds of site and their own sites' jobs only. */
    public const array COMMANDS = ['service:restart', 'service:reload', 'service:log', 'self:profiles', 'cron:list', 'cron:job-add', 'cron:job-remove',
        'cron:job-enable', 'cron:job-disable', 'cron:job-runs', 'firewall:status', 'firewall:rule-add', 'firewall:rule-remove', 'firewall:block',
        'firewall:unblock', 'panel:access', 'change:confirm', 'change:status'];
    private const array OWNER_COMMANDS = ['self:profiles', 'cron:list', 'cron:job-add', 'cron:job-remove', 'cron:job-enable', 'cron:job-disable', 'cron:job-runs'];
    /** The ports no change may close: SSH and the panel's own. */
    private const array PROTECTED_PORTS = ['22', '443'];
    /** How long an applied change waits to be kept before the stand-in puts the previous one back. */
    private const int CHANGE_SECONDS = 120;

    private const array DESCRIPTIONS = [
        'service:restart' => 'Restart a service after checking its settings', 'service:reload' => 'Reload a service\'s settings after checking them',
        'cron:job-add' => 'Add a scheduled job', 'cron:job-remove' => 'Remove a scheduled job', 'cron:job-enable' => 'Turn a scheduled job on',
        'cron:job-disable' => 'Turn a scheduled job off', 'firewall:rule-add' => 'Add a firewall rule, undone unless kept in time',
        'firewall:rule-remove' => 'Remove a firewall rule, undone unless kept in time', 'firewall:block' => 'Block an address, undone unless kept in time',
        'firewall:unblock' => 'Unblock an address, undone unless kept in time', 'panel:access' => 'Change who reaches the panel, undone unless kept in time',
        'change:confirm' => 'Keep the change',
    ];

    public function __construct(private PanelGateway $inner, private string $root)
    {
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        if ($this->inner instanceof PanelFileGateway) {
            return $this->inner->download($request, $into);
        }
        file_put_contents($into, json_encode(['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'refused',
            'reason' => PanelReason::NOT_ALLOWED], JSON_THROW_ON_ERROR)."\n");

        return PanelDownload::read($request->requestId, $into);
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $command = $request->command;
        if (in_array($command, ['job:status', 'job:log'], true) && is_file($this->jobFile($request->args['job_id'] ?? ''))) {
            return $this->job($request);
        }
        $state = $this->state();
        // A change of the files' stand-in (nginx directives) is not this one's.
        if (str_starts_with($command, 'change:') && !isset($state['changes'][$request->args['change_id'] ?? ''])) {
            return $this->inner->ask($request);
        }
        if (!in_array($command, self::COMMANDS, true)) {
            $answer = $this->inner->ask($request);

            return $command === 'self:describe' ? $this->listed($request, $answer) : $answer;
        }
        $refused = $this->scope($request, $state);
        if ($refused !== null) {
            return PanelAnswer::refused($request->requestId, $refused);
        }
        try {
            if (PanelCommands::kind($command) === PanelCommands::READ) {
                return $this->ok($request, 'result', $this->read($command, $request->args, $state));
            }
            $this->check($command, $request->args, $state);
            if (!$request->confirm) {
                return $this->ok($request, 'plan', ['command' => $command, 'args' => $request->args, 'target' => $request->args['domain'] ?? 'server',
                    'description' => self::DESCRIPTIONS[$command] ?? $command, 'effects' => 'write', 'lock' => 'server', 'applied' => false]);
            }
            [$lines, $result] = $this->act($command, $request, $state);
        } catch (FakeRefusal $refusal) {
            return PanelAnswer::refused($request->requestId, $refusal->reason);
        }

        return $this->started($request, $lines, $result);
    }

    /** The host description lists these commands too: the screens offer what a server lists. */
    private function listed(PanelRequest $request, PanelAnswer $answer): PanelAnswer
    {
        if ($answer->status !== PanelAnswerStatus::Ok || !is_array($answer->data['gate']['commands'] ?? null)) {
            return $answer;
        }
        $data = $answer->data;
        $offered = $request->actor->owner === null ? self::COMMANDS : self::OWNER_COMMANDS;
        $data['gate']['commands'] = array_values(array_unique([...$data['gate']['commands'], ...$offered]));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => $data,
            'command_status' => $answer->commandStatus, 'operation_id' => $answer->operationId]);
    }

    /**
     * Services, the firewall and the panel's access are the administrator's; an owner's jobs are their own sites'
     * only, found through the recorded description as the gateway would.
     *
     * @param array<string, mixed> $state
     */
    private function scope(PanelRequest $request, array $state): ?string
    {
        if ($request->actor->owner === null) {
            return null;
        }
        if (!in_array($request->command, self::OWNER_COMMANDS, true)) {
            return 'owner_admin_only';
        }
        if ($request->command === 'self:profiles') {
            return null;
        }
        $domain = $request->args['domain'] ?? $this->jobDomain($state, $request->args['id'] ?? '');
        if ($domain === null) {
            return 'unknown_job';
        }
        $own = $this->inner->ask(PanelRequest::read($request->actor, 'site:describe', ['domain' => $domain]));

        return $own->status === PanelAnswerStatus::Ok ? null : ($own->reason ?? 'owner_scope');
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function read(string $command, array $args, array $state): array
    {
        switch ($command) {
            case 'service:log':
                $name = (string) $args['name'];

                return ['name' => $name, 'lines' => ["{$name}: started", "{$name}: settings checked, running"]];
            case 'self:profiles':
                return ['profiles' => [
                    ['name' => 'static', 'args' => []],
                    ['name' => 'generic', 'args' => [['name' => 'php'], ['name' => 'create-db'], ['name' => 'db-engine']]],
                    ['name' => 'laravel', 'args' => [['name' => 'php'], ['name' => 'create-db', 'default' => 'yes'], ['name' => 'db-engine']]],
                    ['name' => 'wordpress', 'args' => [['name' => 'php'], ['name' => 'create-db', 'default' => 'yes'], ['name' => 'db-engine']]],
                    ['name' => 'bitrix', 'args' => [['name' => 'php'], ['name' => 'create-db', 'default' => 'yes'], ['name' => 'db-engine']]],
                    ['name' => 'node', 'args' => []],
                    ['name' => 'redirect', 'args' => [['name' => 'redirect-to', 'required' => true]]],
                    ['name' => 'proxy', 'args' => [['name' => 'upstream-port', 'required' => true], ['name' => 'upstream-path', 'default' => '/'],
                        ['name' => 'websockets', 'default' => 'no']]],
                ]];
            case 'cron:list':
                $jobs = [];
                foreach ($state['cron'][(string) $args['domain']] ?? [] as $job) {
                    $runs = $state['runs'][$job['id']] ?? [];
                    $jobs[] = $job + ['last_run' => $runs === [] ? null : $runs[array_key_last($runs)]];
                }

                return ['domain' => $args['domain'], 'jobs' => $jobs];
            case 'cron:job-runs':
                return ['id' => $args['id'], 'runs' => $state['runs'][(string) $args['id']] ?? []];
            case 'firewall:status':
                $pending = [];
                foreach ($state['changes'] as $id => $change) {
                    if ($change['state'] === 'pending') {
                        $pending[] = ['change_id' => $id, 'kind' => $change['kind'], 'expires_at' => $change['expires_at'], 'state' => 'pending'];
                    }
                }

                return ['backend' => 'ufw', 'active' => true, 'default_incoming' => 'deny', 'rules' => $state['firewall']['rules'],
                    'blocks' => $state['firewall']['blocks'], 'pending' => $pending, 'panel_access' => ['allow' => $state['panel_access']]];
            case 'change:status':
                $change = $state['changes'][(string) $args['change_id']];

                return ['change_id' => $args['change_id'], 'kind' => $change['kind'], 'domain' => null, 'state' => $change['state'], 'expires_at' => $change['expires_at']];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $state
     *
     * @throws FakeRefusal
     */
    private function check(string $command, array $args, array $state): void
    {
        switch ($command) {
            case 'cron:job-add':
                // No more often than every minute: five fields, the server's own check.
                if (count(explode(' ', (string) $args['schedule'])) !== 5) {
                    throw new FakeRefusal('invalid_argument');
                }
                break;
            case 'cron:job-remove':
            case 'cron:job-enable':
            case 'cron:job-disable':
                if ($this->jobDomain($state, (string) $args['id']) === null) {
                    throw new FakeRefusal('unknown_job');
                }
                break;
            case 'firewall:rule-add':
                if (($args['action'] ?? 'allow') === 'deny' && in_array((string) $args['port'], self::PROTECTED_PORTS, true)) {
                    throw new FakeRefusal('firewall_protected');
                }
                break;
            case 'firewall:rule-remove':
                $rule = $this->rule($state, (string) $args['id']);
                if ($rule === null) {
                    throw new FakeRefusal('not_found');
                }
                if ($rule['protected']) {
                    throw new FakeRefusal('firewall_protected');
                }
                break;
            case 'change:confirm':
                if (($state['changes'][(string) $args['change_id']]['state'] ?? null) !== 'pending') {
                    throw new FakeRefusal('unknown_change');
                }
                break;
        }
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{0: list<string>, 1: array<string, mixed>|null} the job's lines and its result
     *
     * @throws FakeRefusal
     */
    private function act(string $command, PanelRequest $request, array $state): array
    {
        $args = $request->args;
        $timed = null;
        switch ($command) {
            case 'service:restart':
            case 'service:reload':
                $lines = ["Checked the settings of {$args['name']}", ($command === 'service:restart' ? 'Restarted ' : 'Reloaded ').$args['name']];
                break;
            case 'cron:job-add':
                $id = substr(hash('sha256', $args['domain'].$args['schedule'].$args['command'].microtime()), 0, 12);
                $state['cron'][$args['domain']][] = ['id' => $id, 'schedule' => $args['schedule'], 'command' => $args['command'], 'enabled' => true];
                $lines = ["Added job {$id} for {$args['domain']}"];
                break;
            case 'cron:job-remove':
            case 'cron:job-enable':
            case 'cron:job-disable':
                $domain = (string) $this->jobDomain($state, (string) $args['id']);
                foreach ($state['cron'][$domain] as $index => $job) {
                    if ($job['id'] === $args['id']) {
                        if ($command === 'cron:job-remove') {
                            unset($state['cron'][$domain][$index]);
                        } else {
                            $state['cron'][$domain][$index]['enabled'] = $command === 'cron:job-enable';
                        }
                    }
                }
                $state['cron'][$domain] = array_values($state['cron'][$domain]);
                $lines = ["Done: {$command} {$args['id']}"];
                break;
            case 'change:confirm':
                $state['changes'][$args['change_id']]['state'] = 'confirmed';
                $lines = ["Kept change {$args['change_id']}"];
                break;
            default:
                // The firewall and the panel's access: applied now, the previous state kept to put back.
                $kind = $command === 'panel:access' ? 'panel_access' : 'firewall';
                $previous = ['firewall' => $state['firewall'], 'panel_access' => $state['panel_access']];
                $state = $this->applied($command, $args, $state);
                $id = bin2hex(random_bytes(8));
                $expires = gmdate('Y-m-d\TH:i:s\Z', time() + self::CHANGE_SECONDS);
                $state['changes'][$id] = ['kind' => $kind, 'state' => 'pending', 'expires_at' => $expires, 'previous' => $previous];
                $timed = ['change_id' => $id, 'kind' => $kind, 'expires_at' => $expires];
                $lines = ["Applied {$command}", "Waiting for change:confirm {$id} until {$expires}"];
        }
        $this->save($state);

        return [$lines, $timed];
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function applied(string $command, array $args, array $state): array
    {
        switch ($command) {
            case 'firewall:rule-add':
                $next = (string) (1 + max(0, ...array_map(static fn (array $rule): int => (int) $rule['id'], $state['firewall']['rules'])));
                $state['firewall']['rules'][] = ['id' => $next, 'port' => (string) $args['port'], 'proto' => $args['proto'] ?? 'any',
                    'from' => $args['from'] ?? 'any', 'action' => $args['action'], 'protected' => false];
                break;
            case 'firewall:rule-remove':
                $state['firewall']['rules'] = array_values(array_filter($state['firewall']['rules'], static fn (array $rule): bool => $rule['id'] !== $args['id']));
                break;
            case 'firewall:block':
                $state['firewall']['blocks'][] = ['address' => $args['address'],
                    'expires_at' => isset($args['ttl']) ? gmdate('Y-m-d\TH:i:s\Z', time() + (int) $args['ttl']) : null];
                break;
            case 'firewall:unblock':
                $state['firewall']['blocks'] = array_values(array_filter($state['firewall']['blocks'], static fn (array $block): bool => $block['address'] !== $args['address']));
                break;
            case 'panel:access':
                $state['panel_access'] = $args['allow'];
                break;
        }

        return $state;
    }

    /** @return array<string, mixed> the stand-in's state, with every change past its time put back as the server would */
    private function state(): array
    {
        try {
            $state = json_decode((string) @file_get_contents($this->root.'/state.json'), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $state = null;
        }
        if (!is_array($state)) {
            $state = [
                'cron' => ['demo-php.test' => [['id' => 'job-backup', 'schedule' => '30 2 * * *', 'command' => 'php artisan app:clean', 'enabled' => true]],
                    'demo-owned.test' => [['id' => 'job-owned', 'schedule' => '*/15 * * * *', 'command' => 'php worker.php', 'enabled' => false]]],
                'runs' => ['job-backup' => [
                    ['started_at' => '2026-01-01T02:30:00Z', 'finished_at' => '2026-01-01T02:30:04Z', 'exit_code' => 0, 'output' => ['Cleaned 3 files']],
                    ['started_at' => '2026-01-02T02:30:00Z', 'finished_at' => '2026-01-02T02:30:01Z', 'exit_code' => 1, 'output' => ['Disk is full']],
                ]],
                'firewall' => ['rules' => [
                    ['id' => '1', 'port' => '22', 'proto' => 'tcp', 'from' => 'any', 'action' => 'allow', 'protected' => true],
                    ['id' => '2', 'port' => '80', 'proto' => 'tcp', 'from' => 'any', 'action' => 'allow', 'protected' => false],
                    ['id' => '3', 'port' => '443', 'proto' => 'tcp', 'from' => 'any', 'action' => 'allow', 'protected' => true],
                ], 'blocks' => []],
                'panel_access' => 'any',
                'changes' => [],
            ];
        }
        $changed = false;
        foreach ($state['changes'] as $id => $change) {
            if ($change['state'] === 'pending' && strtotime($change['expires_at']) <= time()) {
                $state['firewall'] = $change['previous']['firewall'];
                $state['panel_access'] = $change['previous']['panel_access'];
                $state['changes'][$id]['state'] = 'restored';
                $changed = true;
            }
        }
        if ($changed) {
            $this->save($state);
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        @mkdir($this->root, 0o775, true);
        file_put_contents($this->root.'/state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $state */
    private function jobDomain(array $state, string $id): ?string
    {
        foreach ($state['cron'] as $domain => $jobs) {
            foreach ($jobs as $job) {
                if ($job['id'] === $id) {
                    return (string) $domain;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>|null
     */
    private function rule(array $state, string $id): ?array
    {
        foreach ($state['firewall']['rules'] as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function ok(PanelRequest $request, string $kind, array $data): PanelAnswer
    {
        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => $kind, 'data' => $data, 'command_status' => 'success']);
    }

    /**
     * @param list<string> $lines
     * @param array<string, mixed>|null $result
     */
    private function started(PanelRequest $request, array $lines, ?array $result): PanelAnswer
    {
        $id = bin2hex(random_bytes(12));
        @mkdir($this->root.'/jobs', 0o775, true);
        file_put_contents($this->jobFile($id), json_encode(['job_id' => $id, 'state' => 'succeeded', 'exit_code' => 0, 'command' => $request->command,
            'args' => array_map(static fn (mixed $value): mixed => is_array($value) ? implode(', ', $value) : $value, $request->args),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'request_id' => $request->requestId,
            'actor' => ['user' => $request->actor->user, 'owner' => $request->actor->owner], 'result' => $result, 'lines' => $lines], JSON_THROW_ON_ERROR));

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'job', 'job_id' => $id]);
    }

    private function job(PanelRequest $request): PanelAnswer
    {
        try {
            $job = json_decode((string) file_get_contents($this->jobFile($request->args['job_id'] ?? '')), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return PanelAnswer::failed($request->requestId, PanelReason::RECORDING_MISSING);
        }
        $lines = (array) $job['lines'];
        unset($job['lines']);
        $after = (int) ($request->args['after'] ?? 0);

        return $this->ok($request, 'result', $request->command === 'job:status' ? $job
            : ['job_id' => $job['job_id'], 'state' => $job['state'], 'lines' => array_slice($lines, $after), 'next' => count($lines)]);
    }

    private function jobFile(string $id): string
    {
        return $this->root.'/jobs/'.(preg_match('/\A[a-f0-9]{24}\z/D', $id) === 1 ? $id : 'none').'.json';
    }
}
