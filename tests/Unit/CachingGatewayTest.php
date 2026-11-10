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
use Larena\Env\Gateway\PanelBatchAnswer;
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

    public function askAll(array $requests): PanelBatchAnswer
    {
        if (!$this->knows) {
            return PanelBatchAnswer::unknownToServer('unknown_command');
        }
        $this->batches[] = array_map(static fn (PanelRequest $request): string => $request->command, $requests);

        return PanelBatchAnswer::answered(array_map(static fn (PanelRequest $request): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['status' => 'ok', 'kind' => 'result', 'data' => ['batch' => $request->command]]), $requests));
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

// A site's log opened again within the ttl is not searched again, and is never asked in the background (critics 2026-11-07).
$logCache = new Repository(new ArrayStore());
$logPage = new CachingPanelGateway($batching, $logCache, 30);
$before = count($batching->asked());
$logPage->ask(PanelRequest::read($admin, 'logs:nginx', ['domain' => 'a.test', 'kind' => 'access']));
$logPage->ask(PanelRequest::read($admin, 'logs:nginx', ['domain' => 'a.test', 'kind' => 'access']));
env_assert(count($batching->asked()) === $before + 1 && ($logCache->get('larena-env:gateway:reads') ?? []) === [], 'A log is kept for a second look, never warmed.');

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
$since = CachingPanelGateway::silentSince($hungCache);
env_assert(is_int($since) && abs($since - time()) < 5, 'The moment the server went silent is kept.');
$hungCache->forget('larena-env:gateway:down');
$hungCache->put('larena-env:gateway:silent-since', $since - 300, 900);
(new CachingPanelGateway($hung, $hungCache, 30))->ask(PanelRequest::read($admin, 'db:list'));
env_assert(CachingPanelGateway::silentSince($hungCache) === $since - 300 && $hung->asked() === 2, 'Still silent half a minute later: the first moment stays.');
$hungCache->forget('larena-env:gateway:down');
(new CachingPanelGateway($server, $hungCache, 30))->ask(PanelRequest::read($admin, 'firewall:status'));
env_assert(CachingPanelGateway::silentSince($hungCache) === null, 'The first answer ends the silence.');

// A server that hangs on a batch (critics of 0.4.1): one wait, then every read fails at once; batching stays on.
$hangs = new class () implements PanelGateway, Larena\Env\Contracts\PanelBatchGateway {
    public int $calls = 0;

    public function calls(): int
    {
        return $this->calls;
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->calls++;

        return PanelAnswer::failed($request->requestId, 'gateway_process_failed');
    }

    public function askAll(array $requests): PanelBatchAnswer
    {
        $this->calls++;

        return PanelBatchAnswer::failed('gateway_process_failed');
    }

    public function source(): string
    {
        return 'ssh';
    }
};
$hangCache = new Repository(new ArrayStore());
$overview = [PanelRequest::read($admin, 'self:describe'), PanelRequest::read($admin, 'self:metrics'), PanelRequest::read($admin, 'service:list'),
    PanelRequest::read($admin, 'self:alerts'), PanelRequest::read($admin, 'self:update-plan', ['target' => 'env'])];
$hangPage = new CachingPanelGateway($hangs, $hangCache, 30);
$hangPage->prefetch($overview);
$said = array_map(static fn (PanelRequest $request): ?string => $hangPage->ask($request)->reason, $overview);
env_assert($hangs->calls() === 1 && array_unique($said) === ['gateway_process_failed'], 'A hung server is asked once for the whole page: '.$hangs->calls());
env_assert($hangCache->get('larena-env:gateway:no-batch') === null && $hangCache->get('larena-env:gateway:down') === true,
    'No answer marks the server down and never turns batching off.');

// The warm-up over a hung server asks once, whoever's reads are due.
$hangWarm = new Repository(new ArrayStore());
$seen = new CachingPanelGateway($server, $hangWarm, 30);
foreach ([$admin, $owner] as $who) {
    foreach (['self:describe', 'firewall:status', 'service:list'] as $command) {
        $seen->ask(PanelRequest::read($who, $command));
    }
}
$age($hangWarm, 100);
$hangs->calls = 0;
(new CachingPanelGateway($hangs, $hangWarm, 30))->warm();
env_assert($hangs->calls() === 1, 'The warm-up stops at the first unanswered call: '.$hangs->calls());

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

// The warm-up too keeps a slow read only for the short time while a job may be changing the server.
$changeWarm = new class () extends ArrayStore {
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
$changeWarmCache = new Repository($changeWarm);
(new CachingPanelGateway($batching, $changeWarmCache, 30, 600))->ask(PanelRequest::read($admin, 'php:list'));
$changeWarmCache->put('larena-env:gateway:changing', true, 900);
$age($changeWarmCache, 1000);
$changeWarm->seconds = [];
env_assert((new CachingPanelGateway($batching, $changeWarmCache, 30, 600))->warm() === 1, 'The slow read is due.');
$warmKept = array_filter($changeWarm->seconds(), static fn (string $key): bool => str_starts_with($key, 'larena-env:gateway:') && strlen($key) === strlen('larena-env:gateway:') + 40, ARRAY_FILTER_USE_KEY);
env_assert(array_values($warmKept) === [30], 'A warmed slow read is kept half a minute while a job may be changing the server: '.json_encode($changeWarm->seconds()));

// A server too busy to answer (test server 2026-11-10: a nightly backup made every call take 4-9 s): a page shows what
// the server last said, not «data unavailable»; a read it never answered still fails; a refusal is never covered.
$busy = new class () implements PanelGateway {
    public bool $silent = false;
    public bool $refuse = false;
    public int $asked = 0;

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked++;
        if ($this->refuse) {
            return PanelAnswer::refused($request->requestId, 'not_allowed');
        }

        return $this->silent ? PanelAnswer::failed($request->requestId, 'gateway_process_failed')
            : PanelAnswer::fromDocument($request->requestId, ['status' => 'ok', 'kind' => 'result', 'data' => ['site' => $request->args['domain'] ?? '']]);
    }

    public function source(): string
    {
        return 'ssh';
    }
};
$busyCache = new Repository(new ArrayStore());
// ttl 0: the short-lived answer is gone at once, as after its half minute.
$said = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'storage.test']));
$busy->silent = true;
$last = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'storage.test']));
env_assert($last->status === PanelAnswerStatus::Ok && $last->data === ['site' => 'storage.test'] && $last->receivedAt === $said->receivedAt && $busy->asked === 2,
    'A silent server: the page shows the last answer with its time.');
env_assert(is_int(CachingPanelGateway::silentSince($busyCache)), 'The silence is still noted, so the alert bar says so.');
$down = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'storage.test']));
env_assert($down->status === PanelAnswerStatus::Ok && $busy->asked === 2, 'While the server is marked down, the last answer without a wait.');
$never = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'other.test']));
env_assert($never->reason === 'gateway_process_failed', 'A read the server never answered still says it is unavailable.');
$others = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($owner, 'site:describe', ['domain' => 'storage.test']));
env_assert($others->reason === 'gateway_process_failed', 'Never another person\'s last answer.');
$busyCache->forget('larena-env:gateway:down');
$busy->silent = false;
$busy->refuse = true;
$refused = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'storage.test']));
env_assert($refused->status === PanelAnswerStatus::Refused, 'A refusal is said, never covered by an old answer.');
$busy->refuse = false;
$busy->silent = true;
$busyGateway = new CachingPanelGateway($busy, $busyCache, 0);
$busyGateway->forgetAll();
$busyCache->forget('larena-env:gateway:down');
$afterAction = (new CachingPanelGateway($busy, $busyCache, 0))->ask(PanelRequest::read($admin, 'site:describe', ['domain' => 'storage.test']));
env_assert($afterAction->reason === 'gateway_process_failed', 'After an action the state before it is not shown as the server\'s.');

echo "CachingGatewayTest passed.\n";
