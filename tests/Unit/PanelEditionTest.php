<?php

declare(strict_types=1);

// The edition's parts: the .env it edits, its defaults and key, the asset records, the setup link and the first-run guard.

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunCoordinator;
use Larena\Core\FirstRun\FirstRunPayload;
use Larena\Env\Http\Controllers\PanelEntryController;
use Larena\Env\Http\Middleware\GuardFirstRun;
use Larena\Env\Panel\EnvFile;
use Larena\Env\Panel\PanelEnvironment;
use Larena\Env\Panel\ReleaseAssetState;
use Larena\Env\Panel\SetupLink;

require_once dirname(__DIR__).'/Support/env-test.php';

$directory = env_temp_directory();

// EnvFile: a link stays a link, the owner's file is changed in place, a missing file is not created.
$shared = $directory.'/shared.env';
file_put_contents($shared, "DB_DATABASE=panel\nAPP_KEY=\nSIMAI_PANEL_SETUP_TOKEN=abc\nDB_PASSWORD=\"p=1\"\n");
$link = $directory.'/release.env';
symlink($shared, $link);
$env = new EnvFile($link);
env_assert($env->value('SIMAI_PANEL_SETUP_TOKEN') === 'abc' && $env->value('DB_PASSWORD') === 'p=1' && $env->value('APP_KEY') === null, 'Values are read from the file.');
env_assert($env->fill('APP_KEY', 'base64:k') && $env->value('APP_KEY') === 'base64:k', 'An empty key is filled.');
env_assert($env->fill('APP_KEY', 'other') && $env->value('APP_KEY') === 'base64:k', 'A set key is kept.');
env_assert($env->fill('NEW_KEY', 'v') && $env->value('NEW_KEY') === 'v', 'A new key is added.');
env_assert($env->remove('SIMAI_PANEL_SETUP_TOKEN') && $env->value('SIMAI_PANEL_SETUP_TOKEN') === null, 'The line goes.');
env_assert(is_link($link) && str_contains((string) file_get_contents($shared), "DB_PASSWORD=\"p=1\"\n") && !str_contains((string) file_get_contents($shared), 'abc'), 'Everything else stays, the link too.');
env_assert(!(new EnvFile($directory.'/absent'))->fill('A', 'b') && !is_file($directory.'/absent'), 'A release without .env gets none.');

// PanelEnvironment: defaults only where nothing is set, the key is written once.
$panelEnv = $directory.'/panel.env';
file_put_contents($panelEnv, "APP_KEY=\n");
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
putenv('APP_KEY');
PanelEnvironment::apply($panelEnv);
env_assert(getenv('APP_ENV') === 'staging', 'A value the server set wins.');
env_assert(getenv('SESSION_DRIVER') === 'file' && getenv('LARENA_AUTH_ADMIN_ENTRY_ROUTES') === 'true' && getenv('LARENA_ADMIN_INTERNAL_PREVIEW_ROUTES') === 'false', 'The rest are the edition\'s.');
$key = (new EnvFile($panelEnv))->value('APP_KEY');
env_assert(is_string($key) && str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7), true)) === 32, 'A key was written.');
env_assert(getenv('APP_KEY') === $key, 'And is in use at once.');
foreach (array_keys(PanelEnvironment::DEFAULTS) as $name) {
    env_assert(!preg_match('/(PASS|SECRET|TOKEN|KEY)$/', $name), "No secret among the defaults: {$name}.");
}

// ReleaseAssetState: placed when absent or another release's, left alone otherwise.
$release = $directory.'/release';
$storage = $directory.'/storage/core-assets';
mkdir($release);
env_assert(!ReleaseAssetState::sync($release, $storage) && !is_dir($storage), 'A release without records changes nothing.');
file_put_contents($release.'/sf-active.json', 'one');
file_put_contents($release.'/sf-route-receipt.json', 'receipt-one');
env_assert(ReleaseAssetState::sync($release, $storage) && file_get_contents($storage.'/sf-active.json') === 'one' && file_get_contents($storage.'/sf-route-receipt.json') === 'receipt-one', 'Placed on the first start.');
file_put_contents($release.'/sf-active.json', 'two');
file_put_contents($release.'/sf-route-receipt.json', 'receipt-two');
env_assert(ReleaseAssetState::sync($release, $storage) && file_get_contents($storage.'/sf-active.json') === 'two' && file_get_contents($storage.'/sf-route-receipt.json') === 'receipt-two', 'Replaced by another release.');
env_assert(glob($storage.'/*.tmp-*') === [], 'No temporary file stays.');

// The setup link and the guard, over a real coordinator on a memory database.
$connection = new SQLiteConnection(new PDO('sqlite::memory:'));
$connection->getPdo()->exec('CREATE TABLE larena_install_state (state_key TEXT PRIMARY KEY, state_status TEXT, launch_record_id TEXT, evidence_path TEXT, payload TEXT, created_at TEXT, updated_at TEXT)');
$contributor = new class implements FirstRunContributor {
    public string $state = self::STATE_EMPTY;

    public function id(): string
    {
        return 'auth';
    }

    public function priority(): int
    {
        return 100;
    }

    public function validate(FirstRunPayload $payload): array
    {
        return [];
    }

    public function state(): string
    {
        return $this->state;
    }

    public function apply(FirstRunPayload $payload, FirstRunContext $context): FirstRunContext
    {
        return $context;
    }
};
$coordinator = new FirstRunCoordinator($connection, [$contributor], ['auth']);
$translator = new class implements Translator {
    public function get($key, array $replace = [], $locale = null): string
    {
        return $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return $key;
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$token = str_repeat('a1', 32);
$setupEnv = $directory.'/setup.env';
file_put_contents($setupEnv, "DB_DATABASE=panel\nSIMAI_PANEL_SETUP_TOKEN={$token}\n");
$setup = new SetupLink(new EnvFile($setupEnv));
env_assert($setup->accepts($token) && !$setup->accepts('wrong') && !$setup->accepts(''), 'Only the token is accepted.');

$session = new Store('panel', new ArraySessionHandler(10));
$session->start();
$request = static function (string $uri, string $method = 'GET', ?string $route = null) use ($session): Request {
    $request = Request::create($uri, $method);
    $request->setLaravelSession($session);
    if ($route !== null) {
        $request->setRouteResolver(static fn (): Route => (new Route([$method], $uri, []))->name($route));
    }

    return $request;
};
$guard = new GuardFirstRun($setup, $coordinator, $translator);
$passed = 0;
$next = static function () use (&$passed): Response {
    $passed++;

    return new Response('ok');
};

// Other routes are not the guard's concern; the screen is closed without the link.
env_assert($guard->handle($request('/admin/login', 'GET', 'larena.auth.admin.login'), $next)->getStatusCode() === 200 && $passed === 1, 'Other routes pass.');
env_assert($guard->handle($request('/admin/setup', 'GET', 'larena.auth.admin.setup'), $next)->getStatusCode() === 403 && $passed === 1, 'The setup screen needs the link.');
env_assert($guard->handle($request('/admin/setup', 'POST', 'larena.auth.admin.setup.store'), $next)->getStatusCode() === 403 && $passed === 1, 'So does its form.');

// The entry: a wrong link opens nothing, the right one opens this session, and the token is not in the session.
$entry = new PanelEntryController($setup, $coordinator, $translator);
env_assert($entry($request('/'))->headers->get('Location') === '/admin/server', 'The address leads to the screen.');
env_assert($entry($request('/?setup=wrong'))->getStatusCode() === 403 && !$session->has(SetupLink::SESSION_KEY), 'A wrong link opens nothing.');
$opened = $entry($request('/?setup='.$token));
env_assert($opened instanceof RedirectResponse && $opened->headers->get('Location') === '/admin/setup', 'The right link leads to the first run.');
env_assert($session->get(SetupLink::SESSION_KEY) === hash('sha256', $token), 'The session keeps a digest, never the token.');
env_assert($guard->handle($request('/admin/setup', 'GET', 'larena.auth.admin.setup'), $next)->getStatusCode() === 200 && $passed === 2, 'The screen opens.');
env_assert((new EnvFile($setupEnv))->value(SetupLink::TOKEN_KEY) === $token, 'The token stays until the administrator exists.');

// A replaced token (panel setup-link) ends the older session.
$digest = $session->get(SetupLink::SESSION_KEY);
$other = str_repeat('b2', 32);
file_put_contents($setupEnv, "DB_DATABASE=panel\nSIMAI_PANEL_SETUP_TOKEN={$other}\n");
env_assert($guard->handle($request('/admin/setup', 'GET', 'larena.auth.admin.setup'), $next)->getStatusCode() === 403, 'The old session is closed.');
env_assert(!$setup->accepts($token), 'The old link stops working.');
$entry($request('/?setup='.$other));
env_assert($session->get(SetupLink::SESSION_KEY) !== $digest, 'The new link opens again.');

// When the administrator appears during the request, the line goes and everything else stays.
$creating = static function () use ($contributor): Response {
    $contributor->state = FirstRunContributor::STATE_INITIALIZED;

    return new Response('created', 302);
};
env_assert($guard->handle($request('/admin/setup', 'POST', 'larena.auth.admin.setup.store'), $creating)->getStatusCode() === 302, 'The form runs.');
env_assert((new EnvFile($setupEnv))->value(SetupLink::TOKEN_KEY) === null && (new EnvFile($setupEnv))->value('DB_DATABASE') === 'panel', 'The line is removed.');
env_assert(!$session->has(SetupLink::SESSION_KEY), 'The session forgets the link.');

// Afterwards the link does nothing but lead on, and a line that came back is removed.
file_put_contents($setupEnv, "SIMAI_PANEL_SETUP_TOKEN={$other}\n");
env_assert($entry($request('/?setup='.$other))->headers->get('Location') === '/admin/server', 'The link leads on.');
env_assert((new EnvFile($setupEnv))->value(SetupLink::TOKEN_KEY) === null && !$session->has(SetupLink::SESSION_KEY), 'And opens nothing.');
env_assert($guard->handle($request('/admin/setup', 'GET', 'larena.auth.admin.setup'), $next)->getStatusCode() === 200, 'The admin\'s screen answers by itself: it leads to the sign-in.');

echo "PanelEditionTest passed.\n";
