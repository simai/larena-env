<?php

declare(strict_types=1);

// «История» of the panel (owner decision dec-d9f352569fd1, PX-47): the server's runs (job:list) as entries of the admin's
// history — what in words and over what, who, how it ended, the run's own page; a run's address opened as a page leads
// to «История», which opens it in the side panel, before the server is asked anything.

use Illuminate\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Env\Admin\EnvHistorySource;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Controllers\ServerJobController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Operations\PanelOperations;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return match ($name) {
        'larena.admin.history.index' => '/admin/history',
        'larena.env.admin.job' => '/admin/server/jobs/'.$parameters['job'],
        'larena.env.admin.job.again.run' => '/admin/server/jobs/'.$parameters['job'].'/again/run',
        default => '/'.$name,
    };
}

$lines = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
$translator = new class ($lines) implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines)
    {
    }

    public function get($key, array $replace = [], $locale = null): mixed
    {
        $value = data_get($this->lines, substr($key, strlen('larena-env::admin.')));
        foreach (is_string($value) ? $replace : [] as $name => $one) {
            $value = str_replace(':'.$name, (string) $one, $value);
        }

        return is_string($value) ? $value : $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return (string) $this->get($key, $replace);
    }

    public function getLocale(): string
    {
        return 'ru';
    }

    public function setLocale($locale): void
    {
    }
};

// The server's list as simai-env 1.59.0 gives it (panel-jobs, the last 100): a backup that runs, a site made, a failed
// removal, a stopped install, a run the server itself started, and one locked by another run.
$list = [
    ['job_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'state' => 'running', 'command' => 'backup:data', 'args' => ['domain' => 'demo.test'], 'created_at' => '2026-11-18T10:00:00Z',
        'actor' => ['user' => 'admin@stand.test', 'owner' => null], 'exit_code' => null],
    ['job_id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'state' => 'succeeded', 'command' => 'site:add', 'args' => ['domain' => 'new.test', 'php' => '8.3', 'db-pass' => 'never-shown'],
        'created_at' => '2026-11-18T09:00:00Z', 'actor' => ['user' => 'user:7', 'owner' => 'demo'], 'exit_code' => 0],
    ['job_id' => 'cccccccccccccccccccccccc', 'state' => 'failed', 'command' => 'site:remove', 'args' => ['domain' => 'old.test'], 'created_at' => '2026-11-17T09:00:00Z',
        'actor' => ['user' => 'admin@stand.test'], 'exit_code' => 3],
    ['job_id' => 'dddddddddddddddddddddddd', 'state' => 'cancelled', 'command' => 'wp:install', 'args' => ['domain' => 'wp.test'], 'created_at' => '2026-11-17T08:00:00Z',
        'actor' => ['user' => 'admin@stand.test'], 'exit_code' => null],
    ['job_id' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'state' => 'succeeded', 'command' => 'self:update', 'args' => ['target' => 'env'], 'created_at' => '2026-11-16T08:00:00Z',
        'actor' => ['user' => 'simai-env'], 'exit_code' => 0],
    ['job_id' => 'ffffffffffffffffffffffff', 'state' => 'busy', 'command' => 'mystery:thing', 'args' => [], 'created_at' => '2026-11-16T07:00:00Z', 'actor' => null, 'exit_code' => 1],
];
$gateway = new class ($list) implements PanelGateway {
    /** @var list<string> */
    public array $asked = [];

    /** @param list<array<string, mixed>> $jobs */
    public function __construct(private array $jobs)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command;

        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => 'result',
            'data' => ['schema' => 'simai-job-list/1', 'jobs' => $this->jobs]]);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
$resolver = (new ReflectionClass(PanelActorResolver::class))->newInstanceWithoutConstructor();
$labels = new class () implements \Larena\Admin\Contracts\AdminIdentityLabelResolver {
    public function resolve(string $subjectRef): ?string
    {
        return $subjectRef === 'user:7' ? 'Анна' : null;
    }
};
$source = new EnvHistorySource($resolver, env_operations($gateway), $translator, $labels);
$request = Request::create('/admin/history');
$request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@stand.test'));
$entries = $source->entries($request, 200);
env_assert(count($entries) === 6 && $gateway->asked === ['job:list'],
    'Every run of the server\'s list is an entry, read with one job:list.');
[$backup, $site, $removal, $install, $update, $locked] = $entries;
env_assert($backup->running() && $backup->title === 'Копия сайта demo.test' && $backup->actor === 'Вы' && $backup->object === 'demo.test'
    && $backup->at === '2026-11-18T10:00:00+00:00' && $backup->href === '/admin/server/jobs/aaaaaaaaaaaaaaaaaaaaaaaa' && $backup->key === 'job-aaaaaaaaaaaaaaaaaaaaaaaa',
    'A running backup: what, over what, by whom, its run\'s page.');
env_assert($site->outcome === 'done' && $site->title === 'Новый сайт new.test' && $site->actor === 'Анна', 'Another person of the panel by name.');
$details = json_encode($site->details, JSON_UNESCAPED_UNICODE);
env_assert(str_contains($details, 'site:add') && str_contains($details, '8.3') && !str_contains($details, 'never-shown') && !str_contains($details, 'db-pass'),
    'The details name the command and its safe arguments, never a password: '.$details);
env_assert($removal->outcome === 'failed' && $removal->title === 'Удаление сайта old.test' && str_contains(json_encode($removal->details), '"3"'), 'A failed run, with its exit code.');
env_assert($install->outcome === 'cancelled' && $install->title === 'Установка WordPress на wp.test', 'A stopped run.');
env_assert($update->actor === 'Сервер' && $update->object === 'Сервер' && $update->objectKey === 'server' && $update->title === 'Обновление сервера', 'A run of the server itself.');
env_assert($locked->outcome === 'failed' && $locked->title === 'Действие на сервере' && $locked->note !== '' && $locked->actor === '', 'A command without words, locked by another run.');
env_assert($source->entry($request, 'job-aaaaaaaaaaaaaaaaaaaaaaaa') === null, 'A run opens its own page, not a card.');
$stranger = Request::create('/admin/history');
$refusing = new EnvHistorySource($resolver, env_operations($gateway), $translator);
$stranger->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('owner@stand.test', 'demo'));
env_assert(count($refusing->entries($stranger, 2)) === 2, 'The limit is kept.');
foreach (['ru', 'en'] as $language) {
    $words = require dirname(__DIR__, 2).'/resources/lang/'.$language.'/admin.php';
    env_assert(is_array($words['runs']['commands'] ?? null) && array_keys($words['runs']['commands']) === array_keys($lines['runs']['commands']), "{$language}: the runs' words.");
    foreach ($lines['runs']['commands'] as $group => $commands) {
        env_assert(array_keys($words['runs']['commands'][$group]) === array_keys($commands), "{$language}: {$group}'s commands.");
    }
}

// A run's address opened as a page leads to «История», which opens it in the side panel — before the server is asked.
$router = new Router(new Dispatcher());
$router->get('/admin/history', static fn (): string => '')->name('larena.admin.history.index');
$router->getRoutes()->refreshNameLookups();
$counting = new class () implements PanelGateway {
    public int $asked = 0;

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked++;

        return PanelAnswer::failed($request->requestId, 'not_asked_in_this_test');
    }

    public function source(): string
    {
        return 'recorded';
    }
};
$views = new \Illuminate\View\Factory(new \Illuminate\View\Engines\EngineResolver(), new \Illuminate\View\FileViewFinder(new \Illuminate\Filesystem\Filesystem(), []), new Dispatcher());
$jobs = new ServerJobController(env_operations($counting), $views, $translator, router: $router);
$asking = static function (string $uri, array $headers = [], array $session = []): Request {
    $request = Request::create($uri);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@stand.test'));
    $store = new Store('panel', new ArraySessionHandler(10));
    foreach ($session as $key => $value) {
        $store->put($key, $value);
    }
    $request->setLaravelSession($store);

    return $request;
};
$job = 'aaaaaaaaaaaaaaaaaaaaaaaa';
$redirect = $jobs->show($asking('/admin/server/jobs/'.$job), $job);
env_assert($redirect instanceof RedirectResponse && $redirect->getTargetUrl() === '/admin/history?open='.rawurlencode('/admin/server/jobs/'.$job) && $counting->asked === 0,
    'The run\'s page leads to «История» with the run open, the server not asked: '.($redirect instanceof RedirectResponse ? $redirect->getTargetUrl() : get_debug_type($redirect)));
$stays = static function (Request $request) use ($jobs, $job, $counting): bool {
    $before = $counting->asked;
    try {
        $answer = $jobs->show($request, $job);
    } catch (Throwable) {
        // The view is not drawn in this test: being asked is what shows the page stayed.
        return $counting->asked > $before;
    }

    return !$answer instanceof RedirectResponse || !str_starts_with($answer->getTargetUrl(), '/admin/history');
};
env_assert($stays($asking('/admin/server/jobs/'.$job, ['X-Larena-Fragment' => 'action'])), 'The side panel\'s ask is answered by the run itself.');
env_assert($stays($asking('/admin/server/jobs/'.$job, [], ['larena_env_notice' => 'Начато.'])), 'A page with a notice just left for it stays.');
env_assert($stays($asking('/admin/server/jobs/'.$job.'?after=20')), 'A later page of its output stays.');
env_assert($stays($asking('/admin/server/jobs/'.$job, [], ['larena_env_update_job.'.$job => 'env'])), 'An update of the panel stays on its own page.');
$without = new ServerJobController(env_operations($counting), $views, $translator);
$before = $counting->asked;
try {
    $without->show($asking('/admin/server/jobs/'.$job), $job);
} catch (Throwable) {
}
env_assert($counting->asked > $before, 'Without the admin\'s «История» the run keeps its own page.');

// «Повторить» (owner 2026-11-18): the same command with the same values, planned again (the plan in «Действие с планом»),
// started only on its confirmation; refused without the right, for what succeeded, for what carried a secret.
$again = new class () implements PanelGateway {
    /** @var list<PanelRequest> */
    public array $asked = [];
    public string $state = 'failed';
    public string $command = 'backup:data';

    public function forget(): void
    {
        $this->asked = [];
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request;
        $ok = static fn (string $kind, array $data, ?string $job = null): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => $kind, 'data' => $data] + ($job !== null ? ['job_id' => $job] : []));

        return match ($request->command) {
            'job:status' => $ok('result', ['job_id' => $request->args['job_id'], 'state' => $this->state, 'command' => $this->command, 'args' => ['domain' => 'demo-php.test'],
                'created_at' => '2026-11-18T10:00:00Z', 'finished_at' => '2026-11-18T10:01:00Z', 'title' => 'Backup of demo-php.test']),
            'job:log' => $ok('result', ['job_id' => $request->args['job_id'], 'lines' => [], 'next' => 0, 'done' => true]),
            'backup:data' => $request->confirm ? $ok('job', [], 'bbbbbbbbbbbbbbbbbbbbbbbb')
                : $ok('plan', ['command' => 'backup:data', 'args' => $request->args, 'target' => 'demo-php.test', 'description' => 'Back up the site', 'effects' => 'write', 'lock' => 'site', 'applied' => false]),
            default => PanelAnswer::refused($request->requestId, 'not_allowed'),
        };
    }

    public function source(): string
    {
        return 'stand-in';
    }
};
$rights = new class implements \Illuminate\Contracts\Config\Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-access.operations' => [
        'env.sites.operate' => ['target' => 'server:host', 'required_grants' => ['operate'], 'test_grants' => ['test:system' => ['host' => ['operate']], 'test:staff' => ['host' => []]]],
        'env.server.operate' => ['target' => 'server:host', 'required_grants' => ['operate'], 'test_grants' => ['test:system' => ['host' => ['operate']], 'test:staff' => ['host' => []]]],
    ]];

    public function has($key): bool { return array_key_exists($key, $this->items); }
    public function get($key, $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set($key, $value = null): void { $this->items[$key] = $value; }
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$planViews = new class implements \Illuminate\Contracts\View\Factory {
    /** @var array<string, mixed> */
    public array $data = [];
    public string $name = '';

    public function exists($view): bool { return true; }
    public function file($path, $data = [], $mergeData = []): \Illuminate\Contracts\View\View { throw new LogicException('file'); }
    public function make($view, $data = [], $mergeData = []): \Illuminate\Contracts\View\View
    {
        $this->name = $view;
        $this->data = $data;

        return new class implements \Illuminate\Contracts\View\View {
            public function name(): string { return 'plan'; }
            public function with($key, $value = null): static { return $this; }
            public function getData(): array { return []; }
            public function render(): string { return 'plan'; }
        };
    }
    public function share($key, $value = null): mixed { return null; }
    public function composer($views, $callback): array { return []; }
    public function creator($views, $callback): array { return []; }
    public function addNamespace($namespace, $hints): static { return $this; }
    public function replaceNamespace($namespace, $hints): static { return $this; }
};
$clock = 1_800_000_000;
$againJobs = new ServerJobController(env_operations($again), $planViews, $translator, null, new \Larena\Access\Runtime\AccessOperationAuthorizer($rights, 'testing'),
    receipts: env_plan_receipts($clock));
// One person's session through the plan and its confirmation: the receipt the plan gives comes back with it.
$againSession = new Store('panel', new ArraySessionHandler(10));
$by = static function (string $who, array $input = []) use ($againSession): Request {
    $request = Request::create('/admin/server/jobs/'.str_repeat('a', 24).'/again', 'POST', $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@stand.test'));
    $request->setLaravelSession($againSession);
    $request->attributes->set('larena_access_actor', $who);

    return $request;
};
$receipt = static fn (): string => (string) ($planViews->data['hidden'][\Larena\Env\Http\Support\PlanReceipts::FIELD] ?? '');
$planned = $againJobs->again($by('test:system'), str_repeat('a', 24));
$plans = array_values(array_filter($again->asked, static fn (PanelRequest $asked): bool => $asked->command === 'backup:data'));
env_assert(!$planned instanceof RedirectResponse && $planViews->name === 'larena-env::admin.plan' && count($plans) === 1 && !$plans[0]->confirm
    && $plans[0]->args === ['domain' => 'demo-php.test'] && $planViews->data['runUrl'] === '/admin/server/jobs/'.str_repeat('a', 24).'/again/run',
    'The same command and values, planned, its confirmation going to «again/run».');
$again->forget();
$started = static fn (): array => array_values(array_filter($again->asked, static fn (PanelRequest $asked): bool => $asked->command === 'backup:data' && $asked->confirm));
// The plan's receipt: without it, with another job's, used once already or past its 15 minutes, nothing starts.
$withoutReceipt = $againJobs->againRun($by('test:system'), str_repeat('a', 24));
env_assert($started() === [] && $againSession->get('larena_env_notice') === $lines['reasons']['plan_receipt'], 'Without the plan\'s receipt: refused in words, nothing started.');
$againJobs->again($by('test:system'), str_repeat('a', 24));
$againJobs->againRun($by('test:system', ['receipt' => $receipt()]), str_repeat('c', 24));
env_assert($started() === [], 'The receipt of one job does not confirm another.');
$againJobs->again($by('test:system'), str_repeat('a', 24));
$late = $receipt();
$clock += 901;
$againJobs->againRun($by('test:system', ['receipt' => $late]), str_repeat('a', 24));
env_assert($started() === [], 'A receipt older than 15 minutes is refused.');
$againJobs->again($by('test:system'), str_repeat('a', 24));
$given = $receipt();
$again->forget();
$run = $againJobs->againRun($by('test:system', ['receipt' => $given]), str_repeat('a', 24));
$starts = $started();
env_assert($run->getTargetUrl() === '/admin/server/jobs/bbbbbbbbbbbbbbbbbbbbbbbb' && count($starts) === 1 && $starts[0]->confirm, 'Confirmed: started, its new run opened.');
$again->forget();
$againJobs->againRun($by('test:system', ['receipt' => $given]), str_repeat('a', 24));
env_assert($started() === [], 'A receipt confirms once.');
$again->forget();
$refusedAgain = $againJobs->again($by('test:staff'), str_repeat('a', 24));
env_assert($refusedAgain instanceof RedirectResponse && array_filter($again->asked, static fn (PanelRequest $asked): bool => $asked->command === 'backup:data') === [],
    'Without the right to operate: refused, nothing planned.');
$again->state = 'succeeded';
env_assert($againJobs->againRun($by('test:system'), str_repeat('a', 24))->getTargetUrl() === '/admin/server/jobs/'.str_repeat('a', 24), 'What succeeded is not repeated.');
$again->state = 'failed';
$again->command = 'file:write';
env_assert($againJobs->againRun($by('test:system'), str_repeat('a', 24))->getTargetUrl() === '/admin/server/jobs/'.str_repeat('a', 24), 'What carried a file is not repeated.');

echo "HistoryTest passed.\n";
