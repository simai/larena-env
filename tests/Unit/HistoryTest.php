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
$source = new EnvHistorySource($resolver, new PanelOperations($gateway), $translator, $labels);
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
$refusing = new EnvHistorySource($resolver, new PanelOperations($gateway), $translator);
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
$jobs = new ServerJobController(new PanelOperations($counting), $views, $translator, router: $router);
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
$without = new ServerJobController(new PanelOperations($counting), $views, $translator);
$before = $counting->asked;
try {
    $without->show($asking('/admin/server/jobs/'.$job), $job);
} catch (Throwable) {
}
env_assert($counting->asked > $before, 'Without the admin\'s «История» the run keeps its own page.');

echo "HistoryTest passed.\n";
