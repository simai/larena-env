<?php

declare(strict_types=1);

// The server's answers kept for a short while (owner decision 2026-11-05: a click answers within half a second): a read
// of the server's state is asked once per person within the ttl and once per page, an action or a look at a job makes
// every kept answer stale, logs and refusals are never kept.

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelRequest;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Support/env-test.php';

$server = new class () implements PanelGateway {
    /** @var list<string> */
    public array $asked = [];
    public bool $refuse = false;

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command.($request->confirm ? '!' : '');
        if ($this->refuse) {
            return PanelAnswer::refused($request->requestId, 'not_allowed');
        }

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => ['n' => count($this->asked)]]);
    }

    public function source(): string
    {
        return 'sudo';
    }
};
$cache = new Repository(new ArrayStore());
$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('owner@example.com', 'demo');

$gateway = new CachingPanelGateway($server, $cache, 30);
$first = $gateway->ask(PanelRequest::read($admin, 'self:describe'));
$again = $gateway->ask(PanelRequest::read($admin, 'self:describe'));
env_assert($first->status === PanelAnswerStatus::Ok && $again->data === $first->data && $server->asked === ['self:describe'], 'The same read in one page is asked once.');

// The next page (a new gateway, the same cache) is answered from the cache.
$next = new CachingPanelGateway($server, $cache, 30);
env_assert($next->ask(PanelRequest::read($admin, 'self:describe'))->data === $first->data && count($server->asked) === 1, 'The next screen within the ttl does not ask the server.');
env_assert($next->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'a.test']))->status === PanelAnswerStatus::Ok
    && $next->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'b.test']))->data !== $next->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'a.test']))->data
    && count($server->asked) === 3, 'Each site\'s answer is its own.');

// An owner sees other answers than the admin: never the admin's kept answer.
$next->ask(PanelRequest::read($owner, 'self:describe'));
env_assert(count($server->asked) === 4, 'An owner is asked for their own answer.');

// Logs and job runs are asked every time.
$next->ask(PanelRequest::read($admin, 'service:log', ['name' => 'nginx']));
$next->ask(PanelRequest::read($admin, 'service:log', ['name' => 'nginx']));
env_assert(count($server->asked) === 6, 'A log is read from the server every time.');

// A confirmed action makes every kept answer stale, for every page after it.
$next->ask(PanelRequest::plan($admin, 'service:restart', ['name' => 'nginx']));
$planned = count($server->asked);
$kept = $next->ask(PanelRequest::read($admin, 'self:describe'));
env_assert($planned === 7 && $kept->data === $first->data && $server->asked[array_key_last($server->asked)] === 'service:restart', 'A plan changes nothing and keeps the answers.');
$next->ask(PanelRequest::confirmed($admin, 'service:restart', ['name' => 'nginx']));
$after = new CachingPanelGateway($server, $cache, 30);
$fresh = $after->ask(PanelRequest::read($admin, 'self:describe'));
env_assert($fresh->data !== $first->data && end($server->asked) === 'self:describe', 'After an action the server is asked again.');

// A look at a job does the same: the job may have changed the server.
$before = count($server->asked);
$after->ask(PanelRequest::read($admin, 'job:status', ['job_id' => str_repeat('a', 24)]));
$after->ask(PanelRequest::read($admin, 'self:describe'));
env_assert(count($server->asked) === $before + 2, 'After a look at a job the server is asked again.');

// A refusal is said again on the next screen, never kept.
$server->refuse = true;
$refused = new CachingPanelGateway($server, new Repository(new ArrayStore()), 30);
$refused->ask(PanelRequest::read($admin, 'firewall:status'));
$server->refuse = false;
env_assert($refused->ask(PanelRequest::read($admin, 'firewall:status'))->status === PanelAnswerStatus::Ok, 'A refusal is not kept.');

// The slow reads (the programs, PHP, the update's plan) are kept longer than the rest, and an action still forgets them.
$slowStore = new class () extends ArrayStore {
    /** @var array<string, int> */
    public array $seconds = [];

    public function put($key, $value, $seconds)
    {
        $this->seconds[$key] = $seconds;

        return parent::put($key, $value, $seconds);
    }
};
$slow = new CachingPanelGateway($server, new Repository($slowStore), 30, 600);
$slow->ask(PanelRequest::read($admin, 'software:list'));
$slow->ask(PanelRequest::read($admin, 'self:alerts'));
env_assert(in_array(600, $slowStore->seconds, true) && in_array(30, $slowStore->seconds, true),
    'The programs are kept for ten minutes, the alerts for half a minute.');

// A cache that holds something else is passed by.
$odd = new Repository(new ArrayStore());
$probe = new CachingPanelGateway($server, $odd, 30);
$probe->ask(PanelRequest::read($admin, 'php:list'));
env_assert(CachingPanelGateway::keeps(PanelRequest::read($admin, 'php:list')) && CachingPanelGateway::keeps(PanelRequest::read($admin, 'site:doctor', ['domain' => 'a.test'])) && !CachingPanelGateway::keeps(PanelRequest::read($admin, 'file:read', ['domain' => 'a.test', 'path' => 'x']))
    && $probe->source() === 'sudo', 'Only the reads of the server\'s state are kept.');

// Several reads in one call (simai-env 1.52.0 batch): the overview's reads are asked together, then found kept.
$batching = new class () implements PanelGateway, Larena\Env\Contracts\PanelBatchGateway {
    /** @var list<string> */
    public array $asked = [];
    /** @var list<list<string>> */
    public array $batches = [];
    public bool $knows = true;

    /** Read through methods, so the checks see what the calls did. */
    public function clear(): void
    {
        $this->asked = [];
        $this->batches = [];
    }

    /** @return list<string> */
    public function asked(): array
    {
        return $this->asked;
    }

    /** @return list<list<string>> */
    public function batches(): array
    {
        return $this->batches;
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command;

        return PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => ['one' => $request->command]]);
    }

    public function askAll(array $requests): ?array
    {
        if (!$this->knows) {
            return null;
        }
        $this->batches[] = array_map(static fn (PanelRequest $request): string => $request->command, $requests);

        return array_map(static fn (PanelRequest $request): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['status' => 'ok', 'kind' => 'result', 'data' => ['batch' => $request->command]]), $requests);
    }

    public function source(): string
    {
        return 'sudo';
    }
};
$batchCache = new Repository(new ArrayStore());
$page = new CachingPanelGateway($batching, $batchCache, 30);
$page->prefetch([PanelRequest::read($admin, 'self:metrics'), PanelRequest::read($admin, 'service:list'), PanelRequest::read($admin, 'self:update-plan', ['target' => 'env']),
    PanelRequest::read($admin, 'service:log', ['name' => 'nginx'])]);
env_assert($batching->batches() === [['self:metrics', 'service:list', 'self:update-plan']] && $batching->asked() === [], 'The page\'s reads go in one call; a log is never among them.');
env_assert($page->ask(PanelRequest::read($admin, 'service:list'))->data === ['batch' => 'service:list'] && $batching->asked() === [], 'The page then finds the answers.');
$page->prefetch([PanelRequest::read($admin, 'self:metrics'), PanelRequest::read($admin, 'service:list')]);
env_assert(count($batching->batches()) === 1, 'What is kept is not asked again.');

// The warm-up: what pages asked lately is asked again once it gets old, in one call, and kept longer than a minute.
$warmStore = new class () extends ArrayStore {
    /** @var array<string, int> */
    public array $seconds = [];

    /** @return array<string, int> */
    public function seconds(): array
    {
        return $this->seconds;
    }

    public function put($key, $value, $seconds)
    {
        $this->seconds[$key] = $seconds;

        return parent::put($key, $value, $seconds);
    }
};
$warmCache = new Repository($warmStore);
$visit = new CachingPanelGateway($batching, $warmCache, 30);
$visit->ask(PanelRequest::read($admin, 'self:describe'));
$visit->ask(PanelRequest::read($admin, 'firewall:status'));
$visit->ask(PanelRequest::read($admin, 'service:log', ['name' => 'nginx']));
$visit->ask(PanelRequest::read($owner, 'self:describe'));
$visit->ask(PanelRequest::read(new PanelActor('admin@example.com', null, 'root@example.com'), 'self:describe'));
$batching->clear();
env_assert((new CachingPanelGateway($batching, $warmCache, 30))->warm() === 0, 'Fresh answers are not asked again.');
$age = static function (Repository $cache, int $seconds): void {
    $reads = $cache->get('larena-env:gateway:reads');
    foreach ($reads as $id => $read) {
        $reads[$id]['warmed'] -= $seconds;
    }
    $cache->forever('larena-env:gateway:reads', $reads);
};
$age($warmCache, 100);
$warmed = (new CachingPanelGateway($batching, $warmCache, 30))->warm();
env_assert($warmed === 3 && count($batching->batches()) === 1 && in_array($batching->batches()[0], [['self:describe', 'firewall:status'], ['firewall:status', 'self:describe']], true),
    'The admin\'s two reads in one call, the owner\'s alone; no log, no read seen as someone else: '.json_encode($batching->batches()));
env_assert(in_array(CachingPanelGateway::WARM_SECONDS, $warmStore->seconds(), true), 'A warmed answer outlives the minute between warm-ups.');
$batching->clear();
$read = (new CachingPanelGateway($batching, $warmCache, 30))->ask(PanelRequest::read($admin, 'firewall:status'));
env_assert($read->data === ['batch' => 'firewall:status'] && $batching->asked() === [], 'The next page reads the warmed answer.');
$reads = $warmCache->get('larena-env:gateway:reads');
foreach ($reads as $id => $one) {
    $reads[$id]['used'] -= 3600;
}
$warmCache->forever('larena-env:gateway:reads', $reads);
$age($warmCache, 100);
env_assert((new CachingPanelGateway($batching, $warmCache, 30))->warm() === 0 && $warmCache->get('larena-env:gateway:reads') === [], 'Nobody looked for an hour: nothing is asked, the list is emptied.');

// A server before 1.52.0 refuses a batch: the reads are asked one by one, and no batch is tried for an hour.
$batching->knows = false;
$batching->clear();
$old = new Repository(new ArrayStore());
(new CachingPanelGateway($batching, $old, 30))->prefetch([PanelRequest::read($admin, 'self:metrics'), PanelRequest::read($admin, 'service:list')]);
$batching->knows = true;
(new CachingPanelGateway($batching, $old, 30))->prefetch([PanelRequest::read($admin, 'self:alerts'), PanelRequest::read($admin, 'db:list')]);
env_assert($batching->asked() === ['self:metrics', 'service:list', 'self:alerts', 'db:list'] && $batching->batches() === [], 'Without batch, one by one.');

// A gateway that does not answer at all: the reads after it fail at once for half a minute instead of waiting each.
$hung = new class () implements PanelGateway {
    public int $asked = 0;

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked++;

        return PanelAnswer::failed($request->requestId, 'gateway_process_failed');
    }

    public function source(): string
    {
        return 'ssh';
    }

    public function asked(): int
    {
        return $this->asked;
    }
};
$hungCache = new Repository(new ArrayStore());
$first = (new CachingPanelGateway($hung, $hungCache, 30))->ask(PanelRequest::read($admin, 'self:describe'));
$then = (new CachingPanelGateway($hung, $hungCache, 30))->ask(PanelRequest::read($admin, 'service:list'));
env_assert($first->reason === 'gateway_process_failed' && $then->reason === 'gateway_process_failed' && $hung->asked() === 1, 'After no answer the next reads do not wait.');

// After a confirmed action the slow reads are kept only for the short time: the job may still be changing the server.
$changeStore = new class () extends ArrayStore {
    /** @var array<string, int> */
    public array $seconds = [];

    /** @return array<string, int> */
    public function seconds(): array
    {
        return $this->seconds;
    }

    public function put($key, $value, $seconds)
    {
        $this->seconds[$key] = $seconds;

        return parent::put($key, $value, $seconds);
    }
};
$changing = new CachingPanelGateway($server, new Repository($changeStore), 30, 600);
$changing->ask(PanelRequest::confirmed($admin, 'service:restart', ['name' => 'nginx']));
$changing->ask(PanelRequest::read($admin, 'php:list'));
$kept = array_filter($changeStore->seconds(), static fn (string $key): bool => str_starts_with($key, 'larena-env:gateway:') && strlen($key) === strlen('larena-env:gateway:') + 40, ARRAY_FILTER_USE_KEY);
env_assert(array_values($kept) === [30], 'While a job may be changing the server, the programs are kept half a minute: '.json_encode($changeStore->seconds()));

echo "CachingGatewayTest passed.\n";
