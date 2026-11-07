<?php

declare(strict_types=1);

// The server's setup on the Framework's wizard (owner 2026-11-06), on recorded answers: a step's fields become the server's
// plan shown in the step, «Применить» runs it there and the step says how it went; a step is put off or read; the
// arguments wait encrypted in the session, never in the page.

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\ServerSetupController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Setup\ServerSetup;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__).'/Support/env-test.php';
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

/** @param array<string, string> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    $query = array_diff_key($parameters, ['step' => true]);

    return match ($name) {
        'larena.env.admin.overview' => '/admin/server/overview',
        'larena.env.admin.setup' => '/admin/server/setup/'.($parameters['step'] ?? ''),
        default => throw new RuntimeException($name),
    }.($query === [] ? '' : '?'.http_build_query($query));
}

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$container = new AdminPresenceHost();
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
    (require $migration)->up();
}

$translator = new class (require dirname(__DIR__, 2).'/resources/lang/en/admin.php') implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines) {}
    public function get($key, array $replace = [], $locale = null): mixed
    {
        $value = data_get($this->lines, substr($key, strlen('larena-env::admin.')));

        return is_string($value) ? $value : $key;
    }
    public function choice($key, $number, array $replace = [], $locale = null): string { return (string) $this->get($key, $replace); }
    public function getLocale(): string { return 'en'; }
    public function setLocale($locale): void {}
};
$encrypter = new class implements Illuminate\Contracts\Encryption\StringEncrypter {
    public function encryptString(#[\SensitiveParameter] $value): string { return 'sealed:'.base64_encode(strrev((string) $value)); }
    public function decryptString($payload): string { return strrev((string) base64_decode(substr((string) $payload, 7))); }
};
// The page's data, kept to look at (the views are the ServerToolsTest's).
$views = new class implements Illuminate\Contracts\View\Factory {
    /** @var array<string, mixed> */
    public array $data = [];
    public function exists($view): bool { return true; }
    public function file($path, $data = [], $mergeData = []): Illuminate\Contracts\View\View { throw new LogicException(); }
    public function make($view, $data = [], $mergeData = []): Illuminate\Contracts\View\View
    {
        $this->data = $data;

        return new class implements Illuminate\Contracts\View\View {
            public function name(): string { return 'setup'; }
            public function with($key, $value = null): static { return $this; }
            public function getData(): array { return []; }
            public function render(): string { return 'page'; }
        };
    }
    public function share($key, $value = null): mixed { return null; }
    public function composer($views, $callback): array { return []; }
    public function creator($views, $callback): array { return []; }
    public function addNamespace($namespace, $hints): static { return $this; }
    public function replaceNamespace($namespace, $hints): static { return $this; }
};
$config = new class implements Illuminate\Contracts\Config\Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-access.operations' => [
        'env.server.operate' => ['target' => 'server:host', 'required_grants' => ['operate'], 'test_grants' => ['test:system' => ['host' => ['operate']], 'test:staff' => ['host' => []]]],
    ]];
    public function has($key): bool { return array_key_exists($key, $this->items); }
    public function get($key, $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set($key, $value = null): void { $this->items[$key] = $value; }
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};

$gateway = new RecordedAnswersGateway(env_recorded_path());
$setup = new ServerSetup($capsule->getConnection());
$controller = new ServerSetupController($setup, new ServerOverviewReader($gateway), new SiteExtrasReader($gateway), new PanelOperations($gateway),
    $encrypter, $translator, $views, new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher()),
    new Larena\Access\Runtime\AccessOperationAuthorizer($config, 'testing'));
$session = new Store('panel', new ArraySessionHandler(10));
$request = static function (string $method = 'GET', array $input = [], string $subject = 'test:system', array $query = []) use ($session): Request {
    $request = Request::create('/admin/server/setup', $method, $method === 'GET' ? $query : $input);
    $request->setLaravelSession($session);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@stand.test'));
    $request->attributes->set('larena_access_actor', $subject);

    return $request;
};
$location = static fn (mixed $response): string => $response instanceof RedirectResponse ? (string) $response->headers->get('Location') : '';

// The first step not done opens; a step's fields are in it.
$controller->show($request(), 'protection');
env_assert($views->data['step'] === 'protection' && $views->data['phase'] === 'form' && $views->data['wizard']['protection']['type'] === 'form'
    && count($views->data['wizard']) === 6 && array_key_exists('level', $views->data['data']), 'The level step takes its choice in the wizard.');

// The fields: the server's plan, shown in the same step; the arguments wait in the session, sealed.
$planned = $controller->submit($request('POST', ['phase' => 'form', 'wizard-action' => 'next', 'set' => 'enhanced']), 'protection');
env_assert($location($planned) === '/admin/server/setup/protection', 'The plan stays in the step: '.$location($planned));
$kept = $session->get('larena_env_setup')['protection'] ?? null;
env_assert(is_array($kept) && $kept['command'] === 'security:level' && str_starts_with($kept['args'], 'sealed:') && !str_contains(json_encode($kept), 'enhanced'),
    'The arguments wait sealed in the session.');
$controller->show($request(), 'protection');
env_assert($views->data['phase'] === 'review' && $views->data['plan']?->command === 'security:level' && $views->data['wizard']['protection']['type'] === 'review',
    'The step shows the server\'s plan to apply.');

// A wrong value is refused in the step, nothing is kept.
$session->forget('larena_env_setup');
$refused = $controller->submit($request('POST', ['phase' => 'form', 'wizard-action' => 'next', 'set' => 'paranoid']), 'protection');
env_assert(str_starts_with($location($refused), '/admin/server/setup/protection') && $session->get('larena_env_setup_error') !== null
    && !isset($session->get('larena_env_setup', [])['protection']), 'A wrong level is said in the step.');

// «Применить»: the change runs from the step; the recorded job is over at once, so the step is done and says so.
$controller->submit($request('POST', ['phase' => 'form', 'wizard-action' => 'next', 'set' => 'enhanced']), 'protection');
$applied = $controller->submit($request('POST', ['phase' => 'review', 'wizard-action' => 'apply']), 'protection');
env_assert($location($applied) === '/admin/server/setup/protection' && is_string($session->get('larena_env_setup')['protection']['job'] ?? null), 'The run starts from the step.');
$controller->show($request(), 'protection');
env_assert($views->data['phase'] === 'result' && $views->data['wizard']['protection']['state'] === 'done' && $setup->marks()['protection'] === ServerSetup::DONE
    && !isset($session->get('larena_env_setup', [])['protection']), 'The run went through: the step is done, nothing waits.');
$on = $controller->submit($request('POST', ['phase' => 'result', 'wizard-action' => 'next']), 'protection');
env_assert($location($on) === '/admin/server/setup/backups', 'Next goes to the following step: '.$location($on));

// Put off; read; the last step leads to the result, the result to the overview.
env_assert($location($controller->submit($request('POST', ['phase' => 'form', 'wizard-action' => 'skip']), 'backups')) === '/admin/server/setup/updates'
    && $setup->marks()['backups'] === ServerSetup::SKIPPED, 'A step is put off.');
env_assert($location($controller->submit($request('POST', ['phase' => 'info', 'wizard-action' => 'next']), 'login')) === '/admin/server/setup/address'
    && $setup->marks()['login'] === ServerSetup::DONE, 'The sign-in step is read and done.');
env_assert($location($controller->submit($request('POST', ['phase' => 'info', 'wizard-action' => 'next']), 'updates')) === '/admin/server/setup/finish', 'Then the result.');
env_assert($location($controller->submit($request('POST', ['wizard-action' => 'done']), 'finish')) === '/admin/server/overview', 'The result leads to the overview.');

// Only who may change the server changes it.
$session->forget('larena_env_setup');
$staff = $controller->submit($request('POST', ['phase' => 'form', 'wizard-action' => 'next', 'set' => 'strict'], 'test:staff'), 'protection');
env_assert(str_starts_with($location($staff), '/admin/server/setup/protection') && !isset($session->get('larena_env_setup', [])['protection']), 'Without the right nothing is planned.');

// «Изменить» in the plan goes back to the fields.
$controller->submit($request('POST', ['phase' => 'form', 'wizard-action' => 'next', 'set' => 'strict']), 'protection');
$edit = $controller->submit($request('POST', ['phase' => 'review', 'wizard-action' => 'edit']), 'protection');
env_assert($location($edit) === '/admin/server/setup/protection?change=1' && !isset($session->get('larena_env_setup', [])['protection']), 'Change goes back to the fields.');

echo "ServerSetupTest passed.\n";
