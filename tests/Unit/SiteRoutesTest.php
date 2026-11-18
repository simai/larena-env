<?php

declare(strict_types=1);

// «Создать сайт» by the server's routes (simai-env 1.56.1-1.56.2): self:profiles names the ways each kind is made (new, restore,
// repo) with their inputs and steps; the form asks what the chosen way takes, the plan says what the site needs of the
// server (resources), and the site's card follows it with site:status, offering the next step through the plan
// (bitrix:restore-ready, bitrix:finalize, wp:install, wp:restore-ready, wp:finalize, site:deploy from Git, laravel:finalize).
// WordPress's administrator password is shown once and kept nowhere. The answers are ENV's recordings (simai-env v1.56.1 and
// v1.56.2's set 33, testing/fixtures/panel, marked `recorded_from`); a server without routes or site:status shows the
// screens as before.

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Env\Admin\NewSitePropertyViewPort;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\NewSiteController;
use Larena\Env\Http\Controllers\ServerJobController;
use Larena\Env\Http\Controllers\SiteAdminController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\JobSecret;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Server\SiteRoute;
use Larena\Env\Server\SiteRouteReader;
use Larena\Env\Server\SiteStatus;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$russian = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $lines;
    $line = data_get($lines, str_replace('larena-env::admin.', '', $key));
    if (!is_string($line)) {
        return $key;
    }
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, $value, $line);
    }

    return $line;
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name.(($parameters['domain'] ?? $parameters['job'] ?? $parameters['draft'] ?? null) !== null ? '/'.implode('/', $parameters) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

function csrf_token(): string
{
    return 'test';
}

$recorded = new RecordedAnswersGateway(env_recorded_path());
$admin = new PanelActor('admin@stand.test');
$document = static fn (string $file): array => json_decode(env_recorded_bytes($file), true, 64, JSON_THROW_ON_ERROR);

// The answers are ENV's recordings of simai-env v1.56.1 and, for those set 33 changed or added, v1.56.2: the set says where
// each comes from, and each matches its digest.
$manifest = $document('manifest.json');
$from = static fn (string $release): array => array_keys(array_filter($manifest['files'],
    static fn (array $file): bool => str_starts_with((string) ($file['recorded_from'] ?? ''), "simai-env v{$release}, ")));
env_assert(count($from('1.56.1')) === 25 && count($from('1.56.2')) === 23, 'Twenty-five answers of v1.56.1, twenty-three of v1.56.2: '.count($from('1.56.1')).', '.count($from('1.56.2')));
$copied = [...$from('1.56.1'), ...$from('1.56.2')];
foreach (['1.56.1', '1.56.2'] as $release) {
    foreach ($from($release) as $file) {
        env_assert($manifest['files'][$file]['recorded_from'] === "simai-env v{$release}, testing/fixtures/panel/".$file
            && hash_equals((string) $manifest['files'][$file]['sha256'], hash('sha256', env_recorded_bytes($file))), "{$file} is ENV's recording.");
    }
}
foreach (['site-status.demo-wp.test.prepare.json', 'site-status.demo-wp.test.done.json', 'site-status.demo-wp-copy.test.upload.json',
    'site-status.demo-wp-copy.test.restore-install.json', 'site-status.demo-wp-copy.test.restore-done.json', 'site-status.demo-wp-copy.test.finalize.json',
    'site-status.demo-laravel.test.deploy.json', 'site-status.demo-laravel.test.configure.json', 'plan.laravel-finalize.json', 'self-profiles.json'] as $file) {
    env_assert(in_array($file, $from('1.56.2'), true), "{$file} is set 33's.");
}
foreach (['self-profiles.json', 'site-status.demo-php.test.json', 'plan.wp-install.json', 'job-wp-install-status.json', 'refused.not-enough-ram.json',
    'refused.profile-disabled.json', 'refused.source-invalid.json', 'refused.url-invalid.json', 'refused.not-enough-disk.json', 'plan.bitrix-restore-ready-source.json'] as $file) {
    env_assert(in_array($file, $copied, true), "{$file} is in the copy.");
}
env_assert(!str_contains(env_recorded_bytes('job-wp-install-status.json'), '"admin_password": "') || str_contains(env_recorded_bytes('job-wp-install-status.json'), 'fixture-password-not-real'),
    'The recorded password is a placeholder, never a real one.');

// The commands: two reads, seven actions; a value the gateway would refuse never leaves.
foreach (['site:status' => [PanelCommands::READ, '1.56.0'], 'wp:status' => [PanelCommands::READ, '1.56.0'], 'bitrix:restore-ready' => [PanelCommands::ACTION, '1.55.0'],
    'bitrix:finalize' => [PanelCommands::ACTION, '1.55.0'], 'wp:install' => [PanelCommands::ACTION, '1.56.0'], 'wp:restore-ready' => [PanelCommands::ACTION, '1.56.0'],
    'wp:finalize' => [PanelCommands::ACTION, '1.56.0'], 'laravel:finalize' => [PanelCommands::ACTION, '1.56.0'], 'site:deploy' => [PanelCommands::ACTION, '1.22.0']] as $command => [$kind, $since]) {
    env_assert(PanelCommands::kind($command) === $kind && PanelCommands::since($command) === $since, "{$command}: {$kind} since {$since}.");
}
env_assert(PanelCommands::argumentSince('bitrix:restore-ready', 'source') === '1.56.0' && PanelCommands::argumentSince('bitrix:restore-ready', 'url') === '1.56.0'
    && PanelCommands::accepts('site:deploy', 'git') && PanelCommands::accepts('site:deploy', 'ref') && !PanelCommands::accepts('site:deploy', 'archive'),
    'A folder or an address since 1.56.0; a deploy from Git, never an archive.');
PanelCommands::check('site:deploy', ['domain' => 'demo-laravel.test', 'git' => 'https://github.com/example/app', 'ref' => 'main'], true);
PanelCommands::check('site:deploy', ['domain' => 'demo-laravel.test', 'git' => 'git@github.com:example/app.git', 'ref' => 'v1.0.0'], true);
PanelCommands::check('wp:install', ['domain' => 'demo-wp.test', 'title' => 'Demo WordPress', 'admin-user' => 'admin', 'admin-email' => 'admin@stand.test', 'locale' => 'ru_RU'], true);
PanelCommands::check('bitrix:restore-ready', ['domain' => 'demo-bitrix.test', 'source' => 'upload/backup'], true);
PanelCommands::check('bitrix:restore-ready', ['domain' => 'demo-bitrix.test', 'url' => 'https://files.example.com/demo_20260101_full_abc.tar.gz?sig=1'], true);
foreach ([['wp:install', ['title' => '-rf', 'admin-user' => 'a', 'admin-email' => 'a@b.cd']], ['wp:install', ['title' => 'T', 'admin-user' => 'a b', 'admin-email' => 'a@b.cd']],
    ['wp:install', ['title' => 'T']], ['bitrix:restore-ready', ['source' => '../etc']], ['bitrix:restore-ready', ['source' => '/etc']],
    ['bitrix:restore-ready', ['url' => 'http://files.example.com/a.tar.gz']], ['bitrix:restore-ready', ['url' => 'https://files.example.com/a.zip']],
    ['wp:restore-ready', ['files' => '../copy.tar.gz', 'dump' => 'd.sql']], ['laravel:finalize', ['migrate' => 'maybe']],
    ['site:deploy', ['git' => 'https://github.com/example/app']], ['site:deploy', ['ref' => 'main']], ['site:deploy', ['git' => 'ext::sh -c id', 'ref' => 'main']],
    ['site:deploy', ['git' => 'http://github.com/example/app', 'ref' => 'main']], ['site:deploy', ['git' => 'https://github.com/example/app', 'ref' => '-main']]] as [$command, $bad]) {
    try {
        PanelCommands::check($command, $bad + ['domain' => 'demo.test']);
        env_assert(false, "Never leaves: {$command} ".json_encode($bad));
    } catch (PanelRefused $refused) {
        env_assert($refused->reasonCode === PanelReason::INVALID_ARGUMENT, 'Refused as an invalid argument.');
    }
}
env_assert(PanelCommands::accepts('wp:install', 'title') && !PanelCommands::accepts('site:add', 'title') && PanelCommands::valid('wp:install', 'admin-email', 'a@b.cd')
    && !PanelCommands::valid('wp:install', 'title', '-x'), 'One argument checked alone.');

// The kinds and their routes, as the server lists them.
$extras = new SiteExtrasReader($recorded);
$profiles = [];
foreach ($extras->profiles($admin) ?? [] as $profile) {
    $profiles[$profile->name] = $profile;
}
$routesOf = static fn (string $kind): array => array_map(static fn (SiteRoute $route): array => [$route->id, $route->available], $profiles[$kind]->routes);
env_assert($routesOf('bitrix') === [['new', true], ['restore', true]] && $routesOf('generic') === [['new', true], ['restore', false]]
    && $routesOf('laravel') === [['repo', true]] && $routesOf('static') === [['new', true], ['repo', true]] && $routesOf('wordpress') === [['new', true], ['restore', true]],
    'Each kind\'s ways.');
$bitrixRestore = $profiles['bitrix']->route('restore');
env_assert($bitrixRestore instanceof SiteRoute && $bitrixRestore->addArguments() === ['bitrix-files' => 'restore'] && $bitrixRestore->stepIndex('run_restore') === 3
    && array_column($bitrixRestore->steps, 'command') === ['site:add', 'file:upload', 'bitrix:restore-ready', 'open_url', 'bitrix:finalize', 'site:doctor']
    && $bitrixRestore->steps[3]['url_from'] === 'restore_url' && $bitrixRestore->inputs[2]['kind'] === 'file_on_server', 'A Bitrix restore: its steps and its archive.');
$all = static fn (string $command): bool => true;
env_assert($profiles['generic']->route('restore')?->blockedBy($all) === 'planned' && $profiles['static']->route('repo')?->blockedBy($all) === 'upload'
    && $profiles['laravel']->route('repo')?->blockedBy($all) === null && $profiles['wordpress']->route('new')?->blockedBy(static fn (string $c): bool => $c !== 'site:add') === 'command',
    'Planned, an archive the panel does not send, a server that does not let the panel make sites.');
$wordpressNew = $profiles['wordpress']->route('new');
env_assert($wordpressNew instanceof SiteRoute && $wordpressNew->stepTaking('title') === 'wp:install' && $wordpressNew->stepTaking('php') === null
    && SiteRoute::led(['command' => 'site:deploy'], $all) && SiteRoute::led(['command' => 'open_url'], static fn (): bool => false),
    'A later input belongs to the step that takes it: the repository and its ref to the deploy, which the panel leads.');
$laravelRepo = $profiles['laravel']->route('repo');
env_assert($laravelRepo instanceof SiteRoute && array_column($laravelRepo->steps, 'command') === ['site:add', 'site:deploy', 'laravel:finalize', 'site:doctor']
    && $laravelRepo->stepTaking('git') === 'site:deploy' && $laravelRepo->stepTaking('ref') === 'site:deploy' && $laravelRepo->deploysFromGit() && $profiles['static']->route('repo')?->deploysFromGit() === false, 'Laravel from Git: made, deployed, finished, checked; a static release is an archive.');

// A server answering as simai-env 1.56.2: the recorded set (1.49.0) with the new commands listed; the profiles as recorded
// or without their routes (an older server); a site's state on its route chosen per test.
$listed = ['site:status', 'wp:status', 'bitrix:restore-ready', 'bitrix:finalize', 'wp:install', 'wp:restore-ready', 'wp:finalize', 'laravel:finalize'];
final class ServingGateway implements PanelGateway
{
    /** @var array<string, array<array-key, mixed>> answers by command and domain */
    public array $answers = [];

    /** @param list<string>|null $lists */
    public function __construct(private PanelGateway $inner, private ?array $lists, private bool $routes = true, private ?\Closure $profiles = null, private string $version = '1.56.2')
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $domain = is_string($request->args['domain'] ?? null) ? $request->args['domain'] : '';
        if (isset($this->answers[$request->command.' '.$domain])) {
            return PanelAnswer::fromDocument($request->requestId, $this->answers[$request->command.' '.$domain]);
        }
        $kinds = ['demo-bitrix.test' => 'bitrix', 'demo-wp.test' => 'wordpress', 'demo-wp-copy.test' => 'wordpress', 'demo-laravel.test' => 'laravel'];
        if ($request->command === 'site:describe' && isset($kinds[$domain])) {
            $document = json_decode(env_recorded_bytes('site-describe.demo-php.test.json'), true, 64, JSON_THROW_ON_ERROR);
            $document['data']['domain'] = $domain;
            $document['data']['profile'] = $kinds[$domain];

            return PanelAnswer::fromDocument($request->requestId, $document);
        }
        if ($request->command === 'self:profiles' && (!$this->routes || $this->profiles !== null)) {
            $document = json_decode(env_recorded_bytes('self-profiles.json'), true, 64, JSON_THROW_ON_ERROR);
            foreach ($document['data']['profiles'] as $index => $profile) {
                if (!$this->routes) {
                    unset($document['data']['profiles'][$index]['routes']);
                }
            }
            if ($this->profiles !== null) {
                $document = ($this->profiles)($document);
            }

            return PanelAnswer::fromDocument($request->requestId, $document);
        }
        if ($request->command !== 'self:describe' || $this->lists === null || $request->actor->owner !== null) {
            return $this->inner->ask($request);
        }
        $document = json_decode(env_recorded_bytes('self-describe.json'), true, 64, JSON_THROW_ON_ERROR);
        $document['data']['simai_env']['version'] = $this->version;
        $document['data']['gate']['commands'] = array_merge($document['data']['gate']['commands'], $this->lists);

        return PanelAnswer::fromDocument($request->requestId, $document);
    }

    public function source(): string
    {
        return 'recorded';
    }
}

$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, env_temp_directory()), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$translating = static fn (array $lines): \Illuminate\Contracts\Translation\Translator => new class ($lines) implements \Illuminate\Contracts\Translation\Translator {
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

        return is_string($value) || is_array($value) ? $value : $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return (string) $this->get($key, $replace);
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$asking = static function (string $uri, string $method = 'GET', array $input = [], ?Store $session = null): Request {
    $request = Request::create($uri, $method, $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@stand.test'));
    $request->setLaravelSession($session ?? new Store('panel', new ArraySessionHandler(10)));

    return $request;
};
$newSite = static fn (PanelGateway $gateway, ?array $words = null): NewSiteController => new NewSiteController(new ServerOverviewReader($gateway), new PanelOperations($gateway),
    $factory, $translating($words ?? $lines), new SiteExtrasReader($gateway));
$siteCard = static fn (PanelGateway $gateway, ?array $words = null): SiteAdminController => new SiteAdminController(new SiteDetailsReader($gateway), new ServerOverviewReader($gateway),
    new SiteExtrasReader($gateway), new PanelOperations($gateway), $factory, $translating($words ?? $lines), routes: new SiteRouteReader($gateway), profiles: new SiteExtrasReader($gateway));
$current = new ServingGateway($recorded, $listed);
$notice = static fn (Request $request): string => (string) $request->session()->get('larena_env_notice');

// «Новый сайт» on the Framework's wizard: the kind, the address and settings, what will be done, the run, the result.
$wizardSession = static fn (array $values): Store => tap(new Store('panel', new ArraySessionHandler(10)), static fn (Store $one) => $one->put('larena_env.new_site_wizard', ['values' => $values, 'run' => null]));
$address = static fn (PanelGateway $gateway, array $values, string $query = ''): string => (string) $newSite($gateway)->step($asking('/admin/server/sites/new/address'.$query, 'GET', [], $wizardSession($values)), 'address')->render();
$first = (string) $newSite($current)->create($asking('/admin/server/sites/new'))->render();
env_assert(str_contains($first, '<sf-wizard place="pages"') && substr_count($first, '<sf-wizard-step ') === 5 && str_contains($first, 'data-env-new-kinds')
    && str_contains($first, 'value="wordpress"') && strpos($first, '&quot;value&quot;:&quot;wordpress&quot;') < strpos($first, '&quot;value&quot;:&quot;static&quot;')
    && str_contains($first, 'id="env-new-site-form"') && substr_count($first, '<form ') === 1, 'Five steps, the kind first as tiles, WordPress first and chosen: '.$first);
// 1C-Bitrix: the kind's two ways, the chosen one's fields; the installer set by the way.
$page = $address($current, ['profile' => 'bitrix'], '?route=restore');
env_assert(str_contains($page, 'data-env-new-ways') && str_contains($page, 'data-env-new-way="new"') && preg_match('/appearance="default"[^>]*data-env-new-way="restore"/', $page) === 1
    && str_contains($page, 'name="route" value="restore"') && str_contains($page, 'data-env-new-field="php"') && !str_contains($page, 'name="bitrix-files"')
    && str_contains($page, 'Backup archive: put it into the site'),
    'Bitrix: new and restore, the restore chosen, PHP asked: '.$page);
// The database in words where the kind always has one (PX-20).
env_assert(str_contains($page, 'data-env-new-field="db-note"') && !str_contains($page, 'name="create-db"'), 'A kind that needs a database says it is made.');
// WordPress: the title, the administrator and the language by name; whose site only to the whole server.
$wordpress = $address($current, ['profile' => 'wordpress']);
env_assert(str_contains($wordpress, 'data-env-new-field="title"') && str_contains($wordpress, 'data-env-new-field="admin-email"') && str_contains($wordpress, 'input-type="email"')
    && preg_match('/<sf-dropdown name="locale"[^>]*data-env-new-field="locale"/', $wordpress) === 1 && !str_contains($wordpress, '&quot;text&quot;:&quot;ru_RU&quot;'),
    'WordPress\'s new site: the title, the administrator, the language by its name: '.$wordpress);
// An optional database is a switch.
$generic = $address($current, ['profile' => 'generic']);
env_assert(str_contains($generic, '<sf-switch name="create-db"') && !str_contains($generic, 'data-env-new-field="db-note"'), 'A database the kind may have is a switch: '.$generic);
// A kind none of whose ways can be made now is a greyed tile with the reason; the first available one is chosen.
$noRedirect = new ServingGateway($recorded, $listed, true, static function (array $document): array {
    foreach ($document['data']['profiles'] as $index => $profile) {
        if ($profile['id'] === 'redirect') {
            $document['data']['profiles'][$index]['routes'][0]['available'] = false;
        }
    }

    return $document;
});
$kinds = (string) $newSite($noRedirect)->create($asking('/admin/server/sites/new?profile=redirect'))->render();
env_assert(preg_match('/&quot;value&quot;:&quot;redirect&quot;[^}]*&quot;disabled&quot;:true/', $kinds) === 1 && str_contains($kinds, 'None of its ways can be made')
    && str_contains($kinds, 'value="wordpress"'), 'An unavailable kind: its reason; the first available one is chosen: '.$kinds);
// The kind's answer: an unknown kind is refused in the step; a kind leads to the address.
$kindSession = new Store('panel', new ArraySessionHandler(10));
$went = $newSite($current)->submit($asking('/admin/server/sites/new/kind/answer', 'POST', ['profile' => 'wordpress'], $kindSession), 'kind');
env_assert($went->headers->get('Location') === route('larena.env.admin.sites.new.step', ['step' => 'address'], false) && ($kindSession->get('larena_env.new_site_wizard')['values']['profile'] ?? null) === 'wordpress', 'The kind leads to the address.');
$refusedKind = $newSite($current)->submit($asking('/admin/server/sites/new/kind/answer', 'POST', ['profile' => 'mail'], $kindSession), 'kind');
env_assert($refusedKind->headers->get('Location') === route('larena.env.admin.sites.new', [], false) && is_string($kindSession->get('larena_env.new_site_wizard_errors')['profile'] ?? null), 'A kind the server has not is refused.');
// A later step without the earlier ones goes back to the kind.
env_assert($newSite($current)->step($asking('/admin/server/sites/new/review'), 'review') instanceof RedirectResponse, 'What will be done needs the address first.');

// A domain the server already has a site on is said at its field before anything runs.
$takenSession = new Store('panel', new ArraySessionHandler(10));
$takenSession->put('larena_env.new_site_wizard', ['values' => ['profile' => 'static'], 'run' => null]);
$taken = $newSite($current)->submit($asking('/admin/server/sites/new/address/answer', 'POST', ['domain' => 'demo-php.test', 'route' => 'new'], $takenSession), 'address');
env_assert($taken->headers->get('Location') === route('larena.env.admin.sites.new.step', ['step' => 'address'], false)
    && ($takenSession->get('larena_env.new_site_wizard_errors')['domain'] ?? null) === $lines['new_site']['invalid']['domain_taken'], 'A taken domain is refused at its field.');
// The whole way: the address planned by the server, what will be done in words, one run, the result.
$flowSession = new Store('panel', new ArraySessionHandler(10));
$flowSession->put('larena_env.new_site_wizard', ['values' => ['profile' => 'wordpress'], 'run' => null]);
$answered = $newSite($current)->submit($asking('/admin/server/sites/new/address/answer', 'POST', ['domain' => 'demo-wp.test', 'route' => 'new', 'php' => '8.3', 'title' => 'Demo WordPress',
    'admin-user' => 'admin', 'admin-email' => 'admin@stand.test', 'locale' => 'ru_RU', 'daily-backup' => 'yes'], $flowSession), 'address');
env_assert($answered->headers->get('Location') === route('larena.env.admin.sites.new.step', ['step' => 'review'], false), 'The address planned goes to what will be done: '.json_encode($flowSession->get('larena_env.new_site_wizard_errors')));
$review = (string) $newSite($current)->step($asking('/admin/server/sites/new/review', 'GET', [], $flowSession), 'review')->render();
env_assert(str_contains($review, 'data-env-new-plan="site:add"') && substr_count($review, 'data-env-new-fact') >= 4 && str_contains($review, 'action-text=')
    && str_contains($review, 'data-wizard-step="address"'), 'What will be done: the facts in words, each with its change: '.$review);
$started = $newSite($current)->submit($asking('/admin/server/sites/new/review/answer', 'POST', ['wizard-action' => 'run'], $flowSession), 'review');
$run = $flowSession->get('larena_env.new_site_wizard')['run'] ?? null;
env_assert($started->headers->get('Location') === route('larena.env.admin.sites.new.step', ['step' => 'run'], false) && is_array($run) && is_string($run['steps'][0]['job'] ?? null)
    && $run['steps'][0]['command'] === 'site:add' && in_array('wp:install', array_column($run['steps'], 'command'), true), 'One run: the site first, WordPress after it.');
$running = $newSite($current)->step($asking('/admin/server/sites/new/run', 'GET', [], $flowSession), 'run');
$runPage = (string) ($running instanceof \Illuminate\Http\Response ? $running->getContent() : $running->render());
env_assert(str_contains($runPage, '<sf-progress-run') && str_contains($runPage, 'data-env-new-run='), 'The run on the Framework\'s progress: '.$runPage);

// An older server (no routes in self:profiles): the fields by the kind's groups, nothing refused.
$older = new ServingGateway($recorded, null, false);
$plain = $address($older, ['profile' => 'bitrix']);
env_assert(!str_contains($plain, 'data-env-new-ways') && str_contains($plain, 'name="bitrix-files"') && str_contains($plain, 'data-env-new-field="php"') && !str_contains($plain, 'data-env-notice'),
    'Without routes: the fields by the kind: '.$plain);
$plainRun = $newSite($older)->plan($asking('/admin/server/sites/new/plan', 'POST', ['domain' => 'new-bitrix.test', 'profile' => 'bitrix', 'bitrix-files' => 'setup', 'route' => 'new']));
env_assert($plainRun instanceof \Illuminate\View\View && ($plainRun->getData()['hidden']['bitrix-files'] ?? null) === 'setup' && !isset($plainRun->getData()['hidden']['route']),
    'A route a server does not list is not sent.');

// The plan: site:add with what the way takes, then its later steps in words, and what the site needs of the server.
$session = new Store('panel', new ArraySessionHandler(10));
$wpForm = ['domain' => 'demo-wp.test', 'profile' => 'wordpress', 'route' => 'new', 'php' => '8.3', 'title' => 'Demo WordPress', 'admin-user' => 'admin',
    'admin-email' => 'admin@stand.test', 'locale' => 'ru_RU', 'daily-backup' => 'yes'];
$wpPlan = $newSite($current)->plan($asking('/admin/server/sites/new/plan', 'POST', $wpForm, $session));
env_assert($wpPlan instanceof \Illuminate\View\View, 'WordPress is planned.');
$hidden = $wpPlan->getData()['hidden'];
env_assert(($hidden['route'] ?? null) === 'new' && ($hidden['title'] ?? null) === 'Demo WordPress' && ($hidden['admin-user'] ?? null) === 'admin' && !isset($hidden['bitrix-files']),
    'The confirmation carries the way and its later values: '.json_encode($hidden));
$wpPage = (string) $wpPlan->render();
env_assert(str_contains($wpPage, 'data-env-plan-then>Then: Install WordPress → Finish the setup → Check the site.</p>'), 'The later steps, said: '.$wpPage);
$bitrixPlan = (string) $newSite($current)->plan($asking('/admin/server/sites/new/plan', 'POST', ['domain' => 'new-bitrix.test', 'profile' => 'bitrix', 'route' => 'new']))->render();
env_assert(str_contains($bitrixPlan, 'data-env-plan-resources') && preg_match('#data-env-plan-ram><dt[^>]*>Memory</dt><dd[^>]*>15 GB on the server; at least 1\.5 GB, 4\.0 GB recommended</dd>#', $bitrixPlan) === 1
    && str_contains($bitrixPlan, 'data-env-plan-disk') && !str_contains($bitrixPlan, 'data-env-plan-archive'), 'Bitrix: its memory against the minimum and recommendation: '.$bitrixPlan);
$staticPlan = (string) $newSite($current)->plan($asking('/admin/server/sites/new/plan', 'POST', ['domain' => 'new.test', 'profile' => 'static', 'route' => 'new']))->render();
env_assert(str_contains($staticPlan, 'data-env-plan-resources') && !str_contains($staticPlan, 'data-env-plan-ram'), 'A static site asks no memory of its own.');
// A later value its step would refuse is said before site:add is planned.
$badTitle = $asking('/admin/server/sites/new/plan', 'POST', ['title' => '-rf'] + $wpForm);
$back = $newSite($current)->plan($badTitle);
env_assert($back instanceof RedirectResponse && $notice($badTitle) === 'Check the field «Site title».' && str_contains($back->getTargetUrl(), 'route=new'), 'A wrong title: '.$notice($badTitle));
// Confirmed: the site is made and the later values wait on its card; never a password.
$ran = $newSite($current)->run($asking('/admin/server/sites/new/run', 'POST', $hidden, $session));
env_assert(str_contains($ran->getTargetUrl(), 'larena.env.admin.job/') && $session->get(NewSiteController::ROUTE_VALUES.'.demo-wp.test')
    === ['route' => 'new', 'title' => 'Demo WordPress', 'admin-user' => 'admin', 'admin-email' => 'admin@stand.test', 'locale' => 'ru_RU'], 'The later values are kept for their step.');

// Refusals in words, in Russian as in English, never their codes.
final class RefusingGateway implements PanelGateway
{
    public function __construct(private PanelGateway $inner, private string $file)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        return $request->command === 'site:add' ? PanelAnswer::fromDocument($request->requestId, json_decode(env_recorded_bytes($this->file), true, 64, JSON_THROW_ON_ERROR))
            : $this->inner->ask($request);
    }

    public function source(): string
    {
        return 'recorded';
    }
}
foreach (['refused.not-enough-ram.json' => 'not_enough_ram', 'refused.profile-disabled.json' => 'profile_disabled', 'refused.not-enough-disk.json' => 'not_enough_disk'] as $file => $code) {
    foreach ([[$lines, 'en'], [$russian, 'ru']] as [$words, $language]) {
        $request = $asking('/admin/server/sites/new/plan', 'POST', ['domain' => 'new-bitrix.test', 'profile' => 'bitrix', 'route' => 'new']);
        $refused = $newSite(new RefusingGateway($current, $file), $words)->plan($request);
        env_assert($refused instanceof RedirectResponse && $notice($request) === $words['reasons'][$code] && !str_contains($notice($request), $code), "{$code} in {$language}: ".$notice($request));
    }
}
$recordedNode = (new PanelOperations($recorded))->planCommand($admin, 'site:add', ['domain' => 'node.test', 'profile' => 'node']);
env_assert($recordedNode->reason === 'profile_disabled' && $recordedNode->refused, 'A kind the server switched off is refused before a plan.');

// The site's card: where it stands on its route and the next step.
$card = static function (SiteAdminController $controller, string $domain, ?Store $session = null) use ($asking): string {
    $page = (string) $controller->show($asking("/admin/server/sites/{$domain}", 'GET', [], $session), $domain)->render();

    return preg_match('#<section [^>]*data-env-site-route=.*?</section>#s', $page, $found) === 1 ? $found[0] : '';
};
$php = $card($siteCard($current), 'demo-php.test');
env_assert(str_contains($php, 'data-env-site-route="none" data-env-site-step="prepare"') && str_contains($php, 'Now: the site waits for its content.')
    && str_contains($php, 'data-env-site-route-tab="files"') && str_contains($php, '?tab=files'), 'A PHP site waiting for its code: to its files: '.$php);
env_assert($card($siteCard($current), 'demo-redirect.test') === '', 'A redirect is ready from the start: no block.');
$staticCard = $card($siteCard($current), 'demo-static.test');
env_assert(str_contains($staticCard, 'data-env-site-route-done>The site is ready.') && str_contains($staticCard, 'data-env-site-route-steps')
    && str_contains($staticCard, 'current="3"'), 'A release deployed: all its steps done.');
$bitrixAt = static function (string $step) use ($recorded, $listed, $document): ServingGateway {
    $gateway = new ServingGateway($recorded, $listed);
    $gateway->answers['site:status demo-bitrix.test'] = $document("site-status.demo-bitrix.test.{$step}.json");

    return $gateway;
};
$restoring = $card($siteCard($bitrixAt('run-restore')), 'demo-bitrix.test');
env_assert(str_contains($restoring, 'data-env-site-route="restore" data-env-site-step="run_restore"') && str_contains($restoring, 'current="3"')
    && preg_match('#<a href="http://demo-bitrix\.test/restore\.php" target="_blank" rel="noopener noreferrer" class="larena-button larena-button-primary" data-env-site-route-open>Restore the copy in the browser</a>#', $restoring) === 1,
    'restore.php opens in the browser: '.$restoring);
$checking = $card($siteCard($bitrixAt('prepare')), 'demo-bitrix.test');
env_assert(str_contains($checking, 'data-env-site-route-step="bitrix-restore-ready"') && str_contains($checking, 'name="operation" value="bitrix-restore-ready"')
    && str_contains($checking, 'data-env-site-route-field="source"') && str_contains($checking, 'data-env-site-route-field="url"')
    && str_contains($checking, '>Check the Bitrix backup</button>'), 'The backup is checked through its plan, from a folder or an address: '.$checking);
$olderSource = $card($siteCard(new class ($bitrixAt('prepare')) implements PanelGateway {
    public function __construct(private PanelGateway $inner)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $answer = $this->inner->ask($request);
        if ($request->command === 'self:describe') {
            $data = $answer->data;
            $data['simai_env']['version'] = '1.55.1';

            return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => 'x', 'status' => 'ok', 'kind' => 'result', 'data' => $data]);
        }

        return $answer;
    }

    public function source(): string
    {
        return 'recorded';
    }
}), 'demo-bitrix.test');
env_assert(str_contains($olderSource, 'data-env-site-route-step="bitrix-restore-ready"') && !str_contains($olderSource, 'data-env-site-route-field'), 'A 1.55 server: the archive in the root only.');
$finishing = $card($siteCard($bitrixAt('finalize')), 'demo-bitrix.test');
env_assert(str_contains($finishing, 'name="operation" value="bitrix-finalize"') && str_contains($finishing, '>Finish the Bitrix setup</button>'), 'The setup finished through its plan.');
// A site's state on its route as ENV recorded it (set 33), one site:status per step.
$at = static function (string $domain, string $step) use ($recorded, $listed, $document): ServingGateway {
    $gateway = new ServingGateway($recorded, $listed);
    $gateway->answers["site:status {$domain}"] = $document("site-status.{$domain}.{$step}.json");

    return $gateway;
};
// WordPress waiting for its install: the values given at creation offered again; once installed, the site is ready.
$wpCard = $card($siteCard($at('demo-wp.test', 'prepare')), 'demo-wp.test', $session);
env_assert(str_contains($wpCard, 'data-env-site-route="none" data-env-site-step="prepare"') && str_contains($wpCard, 'name="operation" value="wordpress-install"')
    && str_contains($wpCard, 'name="title"') && str_contains($wpCard, 'value="Demo WordPress"') && str_contains($wpCard, 'value="admin@stand.test"'),
    'WordPress\'s install, its values offered again: '.$wpCard);
$wpDone = $card($siteCard($at('demo-wp.test', 'done')), 'demo-wp.test');
env_assert(str_contains($wpDone, 'data-env-site-route-done>The site is ready.') && !str_contains($wpDone, 'name="operation"'), 'WordPress installed: ready: '.$wpDone);
// A WordPress copy: its files first, then the restore from what the server found, the setup finished, and ready.
$wpUpload = $card($siteCard($at('demo-wp-copy.test', 'upload')), 'demo-wp-copy.test');
env_assert(str_contains($wpUpload, 'data-env-site-step="upload"') && str_contains($wpUpload, 'data-env-site-route-tab="files"') && !str_contains($wpUpload, 'data-env-site-route-outside'),
    'The copy\'s files go to the site\'s folder: '.$wpUpload);
$wpRestore = $card($siteCard($at('demo-wp-copy.test', 'restore-install')), 'demo-wp-copy.test');
env_assert(str_contains($wpRestore, 'data-env-site-step="install"') && str_contains($wpRestore, 'name="operation" value="wordpress-restore-ready"')
    && str_contains($wpRestore, 'data-env-site-route-field="files"') && str_contains($wpRestore, 'data-env-site-route-field="dump"'), 'The copy restored through its plan: '.$wpRestore);
$wpFinal = $card($siteCard($at('demo-wp-copy.test', 'finalize')), 'demo-wp-copy.test');
env_assert(str_contains($wpFinal, 'name="operation" value="wordpress-finalize"') && str_contains($wpFinal, 'data-env-site-route-pending>The next step also puts right: Permissions of wp-config.php.')
    && str_contains($wpFinal, 'current="3"'),
    'The copy\'s setup finished, with what is left: '.$wpFinal);
env_assert(str_contains($card($siteCard($at('demo-wp-copy.test', 'restore-done')), 'demo-wp-copy.test'), 'data-env-site-route-done'), 'The copy is ready.');
// Laravel from Git: made with the repository and its ref kept for the deploy, deployed through its plan, then finished.
$appSession = new Store('panel', new ArraySessionHandler(10));
$appForm = ['domain' => 'demo-laravel.test', 'profile' => 'laravel', 'route' => 'repo', 'php' => '8.3', 'git' => 'https://github.com/example/app', 'ref' => 'main'];
$wrongRepo = $asking('/admin/server/sites/new/plan', 'POST', ['git' => 'ext::sh -c id'] + $appForm);
env_assert($newSite($current)->plan($wrongRepo) instanceof RedirectResponse && $notice($wrongRepo) === 'Check the field «Git repository (https)».', 'A repository the gateway would refuse: '.$notice($wrongRepo));
$noRef = $asking('/admin/server/sites/new/plan', 'POST', ['ref' => ''] + $appForm);
env_assert($newSite($current)->plan($noRef) instanceof RedirectResponse && $notice($noRef) === 'Check the field «Branch, tag or commit».', 'A repository without its ref: '.$notice($noRef));
$appPlan = $newSite($current)->plan($asking('/admin/server/sites/new/plan', 'POST', $appForm, $appSession));
env_assert($appPlan instanceof \Illuminate\View\View && ($appPlan->getData()['hidden']['git'] ?? null) === 'https://github.com/example/app'
    && str_contains((string) $appPlan->render(), 'data-env-plan-then>Then: Deploy the application → Finish the setup → Check the site.</p>'), 'Laravel is planned with its later steps.');
$newSite($current)->run($asking('/admin/server/sites/new/run', 'POST', $appPlan->getData()['hidden'], $appSession));
env_assert($appSession->get(NewSiteController::ROUTE_VALUES.'.demo-laravel.test') === ['route' => 'repo', 'git' => 'https://github.com/example/app', 'ref' => 'main'],
    'The repository and its ref wait for the deploy.');
$deploying = $card($siteCard($at('demo-laravel.test', 'deploy')), 'demo-laravel.test', $appSession);
env_assert(str_contains($deploying, 'data-env-site-step="deploy"') && str_contains($deploying, 'name="operation" value="deploy"')
    && str_contains($deploying, 'type="url" id="env-route-git" name="git"') && str_contains($deploying, 'value="https://github.com/example/app"') && str_contains($deploying, 'value="main"')
    && str_contains($deploying, '>Deploy the application</button>') && !str_contains($deploying, 'data-env-site-route-outside'), 'The deploy from Git through its plan, its values offered again: '.$deploying);
$deployPlan = $siteCard($current)->plan($asking('/admin/server/sites/demo-static.test/plan', 'POST', ['operation' => 'deploy', 'git' => 'https://github.com/example/site', 'ref' => 'v1.0.0']), 'demo-static.test');
env_assert($deployPlan instanceof \Illuminate\View\View && $deployPlan->getData()['plan']->command === 'site:deploy'
    && $deployPlan->getData()['plan']->args === ['domain' => 'demo-static.test', 'git' => 'https://github.com/example/site', 'ref' => 'v1.0.0']
    && $deployPlan->getData()['hidden'] === ['operation' => 'deploy', 'git' => 'https://github.com/example/site', 'ref' => 'v1.0.0'], 'The recorded deploy is planned.');
$deployRun = $siteCard($current)->run($asking('/admin/server/sites/demo-static.test/run', 'POST', $deployPlan->getData()['hidden']), 'demo-static.test');
env_assert(str_contains($deployRun->getTargetUrl(), 'larena.env.admin.job/'), 'Confirmed, the deploy is a job: '.$deployRun->getTargetUrl());
$otherRepo = $asking('/admin/server/sites/demo-laravel.test/plan', 'POST', ['operation' => 'deploy', 'git' => 'https://github.com/example/app', 'ref' => 'main']);
env_assert($siteCard($current)->plan($otherRepo, 'demo-laravel.test') instanceof RedirectResponse && $notice($otherRepo) !== '', 'A deploy the set holds no plan of is not answered with another\'s.');
$noArchive = $asking('/admin/server/sites/demo-laravel.test/plan', 'POST', ['operation' => 'deploy', 'git' => 'https://github.com/example/app']);
env_assert($siteCard($current)->plan($noArchive, 'demo-laravel.test') instanceof RedirectResponse && $notice($noArchive) === $lines['reasons']['invalid_argument'],
    'A repository without its ref never leaves: '.$notice($noArchive));
// A static release waits for its archive: the panel sends none, so the step is said as done outside it.
$staticAt = new ServingGateway($recorded, $listed);
$staticAt->answers['site:status demo-static.test'] = $document('site-status.demo-static.test.json');
$staticAt->answers['site:status demo-static.test']['data'] = ['step' => 'prepare', 'next' => 'site:deploy', 'route' => 'none'] + $staticAt->answers['site:status demo-static.test']['data'];
$staticWaits = $card($siteCard($staticAt), 'demo-static.test');
env_assert(str_contains($staticWaits, 'data-env-site-route-outside') && !str_contains($staticWaits, 'value="deploy"'), 'A release archive stays outside the panel: '.$staticWaits);
// Deployed, the application waits for its key: laravel:finalize, with the migrations.
$appCard = $card($siteCard($at('demo-laravel.test', 'configure')), 'demo-laravel.test');
env_assert(str_contains($appCard, 'data-env-site-route="installed" data-env-site-step="configure"') && str_contains($appCard, 'name="operation" value="laravel-finalize"')
    && str_contains($appCard, 'name="migrate" value="yes" checked') && str_contains($appCard, 'Now: the application waits for its key.')
    && str_contains($appCard, 'data-env-site-route-pending>The next step also puts right: The application key (APP_KEY).'),
    'Laravel finished through its plan: '.$appCard);
$finalize = $siteCard($current)->plan($asking('/admin/server/sites/demo-laravel.test/plan', 'POST', ['operation' => 'laravel-finalize', 'migrate' => 'yes']), 'demo-laravel.test');
env_assert($finalize instanceof \Illuminate\View\View && $finalize->getData()['plan']->command === 'laravel:finalize' && $finalize->getData()['plan']->target === 'demo-laravel.test',
    'laravel:finalize is planned as recorded.');
// An older server (the set's own 1.49.0, no site:status): the overview as before.
env_assert($card($siteCard(new ServingGateway($recorded, null)), 'demo-php.test') === '', 'No block, no refusal on an older server.');
$olderPage = (string) $siteCard(new ServingGateway($recorded, null))->show($asking('/admin/server/sites/demo-php.test'), 'demo-php.test')->render();
env_assert(!str_contains($olderPage, 'data-env-site-route') && !str_contains($olderPage, 'data-env-notice'), 'Nothing said either.');

// The next steps go through the plan: the archive the server found, the refusals in words.
$restorePlan = $siteCard($current)->plan($asking('/admin/server/sites/demo-bitrix.test/plan', 'POST', ['operation' => 'bitrix-restore-ready', 'source' => 'upload/backup']), 'demo-bitrix.test');
env_assert($restorePlan instanceof \Illuminate\View\View && $restorePlan->getData()['plan']->args === ['domain' => 'demo-bitrix.test', 'source' => 'upload/backup'], 'The folder\'s backup is planned.');
$restorePage = (string) $restorePlan->render();
env_assert(str_contains($restorePage, 'data-env-plan-archive') && str_contains($restorePage, 'demo_20260102_full_def.tar.gz, parts: 1, 161 B') && str_contains($restorePage, 'data-env-plan-ram'),
    'The plan names the archive and the memory: '.$restorePage);
foreach ([['source' => 'upload/etc-link', 'source_invalid'], ['source' => 'upload/huge', 'not_enough_disk'], ['url' => 'https://127.0.0.1/x.tar.gz', 'url_invalid'],
    ['source' => 'upload/backup', 'url' => 'https://files.example.com/x.tar.gz', 'source_and_url']] as $case) {
    $code = array_pop($case);
    foreach ([[$lines, 'en'], [$russian, 'ru']] as [$words, $language]) {
        $request = $asking('/admin/server/sites/demo-bitrix.test/plan', 'POST', ['operation' => 'bitrix-restore-ready'] + $case);
        $answer = $siteCard($current, $words)->plan($request, 'demo-bitrix.test');
        env_assert($answer instanceof RedirectResponse && $notice($request) === $words['reasons'][$code] && str_ends_with($answer->getTargetUrl(), '?tab=overview'), "{$code} in {$language}: ".$notice($request));
    }
}
$install = $siteCard($current)->plan($asking('/admin/server/sites/demo-wp.test/plan', 'POST', ['operation' => 'wordpress-install', 'title' => 'Demo WordPress',
    'admin-user' => 'admin', 'admin-email' => 'admin@stand.test', 'locale' => 'en_US']), 'demo-wp.test');
env_assert($install instanceof \Illuminate\View\View && $install->getData()['plan']->command === 'wp:install' && str_contains((string) $install->render(), 'password is shown once, on the job'),
    'wp:install is planned, saying the password is shown once.');
foreach (['wordpress-finalize' => 'wp:finalize', 'bitrix-finalize' => 'bitrix:finalize', 'wordpress-restore-ready' => 'wp:restore-ready'] as $operation => $command) {
    $planned = $siteCard($current)->plan($asking('/admin/server/sites/demo-wp-copy.test/plan', 'POST', ['operation' => $operation, 'files' => 'copy.tar.gz', 'dump' => 'public/dump.sql.gz']), 'demo-wp-copy.test');
    env_assert($planned instanceof \Illuminate\View\View && $planned->getData()['plan']->command === $command, "{$operation} is planned.");
}
env_assert(SiteOperation::LaravelFinalize->arguments('demo-app.test', ['migrate' => 'yes']) === ['domain' => 'demo-app.test', 'migrate' => 'yes']
    && !in_array(SiteOperation::WordpressInstall, SiteOperation::offeredFor((new SiteDetailsReader($current))->read($admin, 'demo-php.test')->site, PanelCapabilitiesAll::all()), true),
    'A route\'s step is never among the tab\'s buttons.');

// WordPress's administrator password: shown once on the job's screen, kept nowhere.
final class PanelCapabilitiesAll
{
    public static function all(): \Larena\Env\Gateway\PanelCapabilities
    {
        return new \Larena\Env\Gateway\PanelCapabilities('9.9.9');
    }
}
$store = new ArrayStore();
$caching = new CachingPanelGateway(new RecordedAnswersGateway(env_recorded_path()), new Repository($store));
$operations = new PanelOperations($caching);
$job = $operations->start($admin, SiteOperation::WordpressInstall, 'demo-wp.test', ['title' => 'Demo WordPress', 'admin-user' => 'admin', 'admin-email' => 'admin@stand.test', 'locale' => 'en_US']);
env_assert($job->jobId === '000000000000000000000000', 'The install starts a job.');
$jobs = new ServerJobController($operations, $factory, $translating($lines));
$first = $jobs->show($asking('/admin/server/jobs/'.$job->jobId), $job->jobId);
env_assert($first instanceof \Illuminate\Http\Response && str_contains((string) $first->headers->get('Cache-Control'), 'no-store'), 'The screen with the password is never kept by a browser.');
$firstPage = (string) $first->getContent();
env_assert(str_contains($firstPage, 'data-env-job-secret') && str_contains($firstPage, 'data-env-job-password>fixture-password-not-real</dd>')
    && str_contains($firstPage, 'href="http://demo-wp.test/wp-login.php"') && str_contains($firstPage, 'shown only this once'), 'Shown on the first screen after the job ended: '.$firstPage);
$second = $jobs->show($asking('/admin/server/jobs/'.$job->jobId), $job->jobId);
$secondPage = (string) ($second instanceof \Illuminate\Http\Response ? $second->getContent() : $second->render());
env_assert(!str_contains($secondPage, 'fixture-password-not-real') && str_contains($secondPage, 'data-env-job-secret-shown') && str_contains($secondPage, 'Lost your password?'),
    'Never again: the next screen says it was shown once: '.$secondPage);
$kept = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);
env_assert(!str_contains(serialize($kept), 'fixture-password-not-real'), 'Nothing the cache keeps holds the password.');
// Whatever read would carry such a value, the cache never writes it.
$leaking = new class () implements PanelGateway {
    public function ask(PanelRequest $request): PanelAnswer
    {
        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => 'result',
            'data' => ['schema' => 'simai-site-status/1', 'result' => ['admin_password' => 'never-kept-secret']]]);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
$leakStore = new ArrayStore();
(new CachingPanelGateway($leaking, new Repository($leakStore)))->ask(PanelRequest::read($admin, 'site:status', ['domain' => 'demo-wp.test']));
env_assert(!str_contains(serialize((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($leakStore)), 'never-kept-secret'), 'Not even a kept read.');
$status = PanelJobStatus::fromData($document('job-wp-install-status.json')['data']);
env_assert($status->secret instanceof JobSecret && $status->secret->user === 'admin' && !str_contains(print_r($status->secret, true), 'fixture-password-not-real')
    && PanelJobStatus::fromData($document('job-wp-install-status-again.json')['data'])->shownOnce === ['admin_password']
    && PanelJobStatus::fromData($document('job-wp-install-status-again.json')['data'])->secret === null, 'The password is read once, printed nowhere by accident.');
env_assert(JobSecret::in($document('job-wp-install-status.json')['data']) && !JobSecret::in($document('job-wp-install-status-again.json')['data']), 'Such an answer is told apart.');
env_assert(SiteStatus::fromData($document('site-status.demo-bitrix.test.run-restore.json')['data'])->openUrl() === 'http://demo-bitrix.test/restore.php'
    && (new SiteRouteReader($recorded))->wordpressCopies($admin, 'demo-wp-copy.test') === null, 'A route\'s address; a state the set does not hold is nothing.');

// The property view over a route: its fields, and record.create plans site:add with the later values kept for their step.
$database = tempnam(sys_get_temp_dir(), 'larena-env-routes-');
$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
\Larena\Setting\Persistence\DatabaseViewSettingsStore::createSchema($capsule->getConnection()->getSchemaBuilder());
register_shutdown_function(static fn () => @unlink((string) $database));
$port = (new NewSitePropertyViewPort($newSite($current, $russian), $translating($russian), new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($capsule->getConnection()))))
    ->for($admin, $portSession = new Store('panel', new ArraySessionHandler(10)));
$loaded = $port->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.load', ['mode' => 'create', 'collection' => 'env.new_site.wordpress.new']);
$fields = [];
foreach ($loaded['record']['sections'] ?? [] as $section) {
    foreach ($section['groups'] ?? [] as $group) {
        foreach ($group['fields'] ?? [] as $field) {
            $fields[] = $field['key'];
        }
    }
}
env_assert(($loaded['answer'] ?? null) === 'applied' && $fields === ['domain', 'owner', 'php', 'title', 'admin_user', 'admin_email', 'locale', 'daily_backup'],
    'WordPress\'s way in the property view: '.json_encode($fields));
env_assert(($port->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.load', ['mode' => 'create', 'collection' => 'env.new_site.generic.restore'])['answer'] ?? null) === 'refused',
    'A planned way is no collection.');
$draft = $port->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.wordpress.new',
    'values' => ['domain' => 'demo-wp.test', 'php' => '8.3', 'title' => 'Demo WordPress', 'admin_user' => 'admin', 'admin_email' => 'admin@stand.test']]);
env_assert(($draft['answer'] ?? null) === 'applied', 'Planned from the view: '.json_encode($draft));
$raw = $portSession->get(NewSiteController::DRAFTS.'.'.$draft['record_id']);
env_assert(is_array($raw) && $raw['profile'] === 'wordpress' && $raw['route'] === 'new' && $raw['admin-user'] === 'admin' && $raw['title'] === 'Demo WordPress', 'The draft holds the way: '.json_encode($raw));
$wrongView = $port->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.wordpress.new',
    'values' => ['domain' => 'demo-wp.test', 'title' => 'T', 'admin_user' => 'admin', 'admin_email' => 'not an email']]);
env_assert(($wrongView['answer'] ?? null) === 'refused' && isset($wrongView['errors']['admin_email']), 'A wrong email under its field: '.json_encode($wrongView));

// Every text in Russian as in English; the views print nothing raw and hold no script.
$keys = static function (array $lines, string $prefix = '') use (&$keys): array {
    $all = [];
    foreach ($lines as $key => $value) {
        $all = array_merge($all, is_array($value) ? $keys($value, $prefix.$key.'.') : [$prefix.$key]);
    }

    return $all;
};
env_assert($keys($russian['routes']) === $keys($lines['routes']), 'The routes\' texts in both languages.');
foreach (['profile_disabled', 'not_enough_disk', 'not_enough_ram', 'source_invalid', 'url_invalid', 'source_and_url'] as $code) {
    env_assert(is_string($russian['reasons'][$code] ?? null) && is_string($lines['reasons'][$code] ?? null), "{$code} in words.");
}
foreach (SiteOperation::ROUTE_STEPS as $operation) {
    env_assert(is_string($russian['operations'][$operation->value] ?? null) && is_string($lines['operations'][$operation->value] ?? null), "{$operation->value} has words.");
}
foreach (['new-site.blade.php', 'site.blade.php', 'plan.blade.php', 'job.blade.php'] as $view) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "{$view} prints nothing raw and holds no script.");
}

// «Из архива» (site:restore-ready, simai-env 1.57.1; the stand's 1.63.3 answers recorded): a plain PHP site made from an
// archive of its files and a dump. The wizard offers the kind's restore route under that name; the card leads to the
// restore once an archive is in the site's folder; the plan says what is unpacked, where the dump goes, what is replaced and
// the copy before; each refusal of the server is one sentence of the panel's, the server's line under «Подробнее».
$standRestore = ['id' => 'restore', 'title' => 'Restore from a files archive and an SQL dump', 'available' => true, 'inputs' => [
    ['name' => 'domain', 'required' => true, 'kind' => 'text'], ['name' => 'php', 'required' => false, 'kind' => 'choice', 'values' => ['8.3'], 'default' => '8.3'],
    ['name' => 'create-db', 'required' => false, 'kind' => 'choice', 'values' => ['yes', 'no'], 'default' => 'yes'],
    ['name' => 'files', 'required' => true, 'kind' => 'file_on_server'], ['name' => 'dump', 'required' => false, 'kind' => 'file_on_server']],
    'steps' => [['id' => 'add', 'title' => 'Create the site: PHP and a database', 'command' => 'site:add', 'args' => []],
        ['id' => 'upload', 'title' => 'Upload the files archive and the SQL dump', 'command' => 'file:upload', 'args' => []],
        ['id' => 'restore', 'title' => 'Unpack the files and import the dump', 'command' => 'site:restore-ready', 'args' => []],
        ['id' => 'done', 'title' => 'Check the site', 'command' => 'site:doctor', 'args' => []]]];
$withRestore = static function (array $document) use ($standRestore): array {
    foreach ($document['data']['profiles'] as $index => $profile) {
        if ($profile['id'] === 'generic') {
            $document['data']['profiles'][$index]['routes'][1] = $standRestore;
        }
    }

    return $document;
};
$restoreListed = [...$listed, 'site:restore-ready', 'file:list'];
$restoring = new ServingGateway($recorded, $restoreListed, true, $withRestore, '1.63.3');
$archiveWay = $address($restoring, ['profile' => 'generic'], '?route=restore');
env_assert(str_contains($archiveWay, 'data-env-new-way="restore"') && str_contains($archiveWay, 'From an archive') && !str_contains($archiveWay, 'Restore from a copy')
    && str_contains($archiveWay, 'name="route" value="restore"') && !str_contains($archiveWay, 'Unpack the files and import the dump'),
    'The PHP kind\'s restore is «Из архива», its steps in the panel\'s words: '.$archiveWay);
env_assert($russian['routes']['names_of']['generic_restore'] === 'Из архива' && $russian['routes']['steps']['site_restore_ready'] === 'Восстановить сайт из архива',
    'In Russian: «Из архива», «Восстановить сайт из архива».');
$restoreCard = static fn (PanelGateway $gateway, array $words): SiteAdminController => new SiteAdminController(new SiteDetailsReader($gateway), new ServerOverviewReader($gateway),
    new SiteExtrasReader($gateway), new PanelOperations($gateway), $factory, $translating($words), files: new \Larena\Env\Files\SiteFiles($gateway),
    routes: new SiteRouteReader($gateway), profiles: new SiteExtrasReader($gateway));
$folder = static fn (array $names): array => ['schema' => 'simai-panel-answer/1', 'request_id' => 'r', 'status' => 'ok', 'kind' => 'result', 'command_status' => 'success',
    'data' => ['schema' => 'simai-files/1', 'path' => '', 'truncated' => false, 'entries' => array_map(static fn (string $name): array => ['name' => $name,
        'type' => $name === 'public' ? 'dir' : 'file', 'size' => 337, 'mtime' => 1793926628, 'mode' => '640'], $names)]];
$restoring->answers['file:list demo-php.test'] = $folder(['public']);
$waiting = $card($restoreCard($restoring, $lines), 'demo-php.test');
env_assert(str_contains($waiting, 'data-env-site-route-tab="files"') && !str_contains($waiting, 'value="php-restore"'), 'No archive yet: to the site\'s files: '.$waiting);
$restoring->answers['file:list demo-php.test'] = $folder(['public', 'site.zip', 'db.sql.gz', 'notes.txt']);
$ready = $card($restoreCard($restoring, $lines), 'demo-php.test');
env_assert(str_contains($ready, 'data-env-site-route-step="php-restore"') && str_contains($ready, 'name="operation" value="php-restore"') && str_contains($ready, 'current="2"')
    && str_contains($ready, 'From an archive') && str_contains($ready, 'Next: Restore the site from the archive') && !str_contains($ready, 'data-env-site-route-tab')
    && preg_match('#<option value="site\.zip"\s*>site\.zip</option>#', $ready) === 1 && str_contains($ready, '<option value="">No database</option>') && preg_match('#<option value="db\.sql\.gz"\s+selected\s*>db\.sql\.gz</option>#', $ready) === 1
    && !str_contains($ready, 'notes.txt') && str_contains($ready, 'name="overwrite" value="yes"') && !str_contains($ready, 'name="overwrite" value="yes" checked'),
    'An archive in the folder: the restore is the next step, the archive and the dump chosen, the replacement off: '.$ready);
// The plan in words, over a site with data: the copy first, what is replaced; the plate says it.
$planned = $restoreCard($restoring, $lines)->plan($asking('/admin/server/sites/demo-php.test/plan', 'POST', ['operation' => 'php-restore', 'files' => 'site.zip',
    'dump' => 'db.sql.gz', 'overwrite' => 'yes']), 'demo-php.test');
env_assert($planned instanceof \Illuminate\View\View && $planned->getData()['plan']->args === ['domain' => 'demo-php.test', 'files' => 'site.zip', 'dump' => 'db.sql.gz', 'overwrite' => 'yes'],
    'The restore is planned as the stand answered it.');
$planPage = (string) $planned->render();
foreach (['site.zip — unpacked into the site', 'the dump db.sql.gz is loaded', 'replaced by the archive and the dump', 'first a copy of the files and the database',
    'the archive and the dump to the site', 'The server copies the site first.'] as $said) {
    env_assert(str_contains($planPage, $said), "The plan says «{$said}»: ".$planPage);
}
// Each refusal in one sentence, in both languages; the server's line under «Подробнее»; the way to «Файлы» where a file is the problem.
foreach ([[['files' => 'site.zip', 'dump' => 'db.sql.gz', 'overwrite' => 'no'], 'database_not_empty', ['count' => '1'], false],
    [['files' => 'site.zip', 'overwrite' => 'no'], 'files_present', [], false],
    [['files' => 'notes.txt'], 'files_kind', [], true], [['files' => 'site.zip', 'dump' => 'notes.txt'], 'dump_kind', [], true],
    [['files' => 'gone.zip'], 'missing', ['name' => 'gone.zip'], true]] as [$input, $code, $replace, $toFiles]) {
    foreach ([[$lines, 'en'], [$russian, 'ru']] as [$words, $language]) {
        $refusedRestore = $restoreCard($restoring, $words)->plan($asking('/admin/server/sites/demo-php.test/plan', 'POST', ['operation' => 'php-restore'] + $input), 'demo-php.test');
        env_assert($refusedRestore instanceof \Illuminate\View\View, "{$code} in {$language}: a window, not a notice.");
        $sentence = $words['restore']['refusals'][$code];
        foreach ($replace as $name => $value) {
            $sentence = str_replace(':'.$name, $value, $sentence);
        }
        $window = (string) $refusedRestore->render();
        env_assert($refusedRestore->getData()['refused'] === [$sentence] && str_contains($refusedRestore->getData()['refusedServer'], 'overwrite') === in_array($code, ['database_not_empty', 'files_present'], true)
            && $refusedRestore->getData()['refusedServer'] !== '' && str_contains($window, 'data-env-plan-restore-files') === $toFiles,
            "{$code} in {$language}: ".json_encode($refusedRestore->getData()['refused'], JSON_UNESCAPED_UNICODE));
    }
}

// Every line the server's restore can refuse with (admin/commands/site_restore.sh) has its sentence; an unknown line stays.
$ruText = static function (string $key, array $replace) use ($russian): string {
    $line = data_get($russian['restore'], $key);
    env_assert(is_string($line), "No Russian words for restore.{$key}.");
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, $value, $line);
    }

    return $line;
};
foreach (['Domain is not configured as a site. Create it first: simai-admin.sh site add --domain x.test ...' => 'site_missing',
    'x.test is a Bitrix site: use bitrix restore-ready' => 'bitrix', 'x.test is a WordPress site: use wp restore-ready' => 'wordpress',
    'site restore-ready is for PHP sites (generic profile); demo-static.test is static' => 'not_php',
    'x.test is deployed in releases; restore it with site deploy --archive' => 'releases', 'Site directory /home/simai/www/x.test is missing' => 'root_missing',
    "--dump must not contain '.' or '..' parts" => 'path', '--files: gone.zip not found in /home/simai/www/x.test' => 'missing',
    '--files: link.zip goes through a symlink; refused' => 'link', '--dump: dumps is not a regular file' => 'not_file',
    "--files is the site's files; db.sql.zip is a dump (--dump)" => 'files_is_dump', '--files must be a .tar.gz, .tgz, .tar or .zip archive' => 'files_kind',
    '--dump must be a .sql, .sql.gz or .sql.zip file' => 'dump_kind',
    'x.test has no database for the dump: simai-admin.sh site db-create --domain x.test --confirm yes' => 'no_database',
    'The dump can be imported into MySQL only; x.test uses pgsql' => 'not_mysql', 'Cannot read the database x_test as root' => 'database_unreadable',
    'The database demo_php_test is not empty (12 tables); rerun with --overwrite yes (a backup is taken first)' => 'database_not_empty',
    '/home/simai/www/x.test/public already has files; rerun with --overwrite yes (a backup is taken first)' => 'files_present'] as $line => $code) {
    $said = \Larena\Env\Admin\RestoreWords::refusals([$line], $ruText);
    env_assert($said['codes'] === [$code] && $said['all'] && preg_match('/[А-Яа-я]/u', $said['said'][0]) === 1 && !str_contains($said['said'][0], ':'.'name')
        && !str_contains($said['said'][0], '--') && is_string($lines['restore']['refusals'][$code] ?? null), "«{$line}» is {$code}: ".$said['said'][0]);
}
env_assert(\Larena\Env\Admin\RestoreWords::refusals(['--dump: dumps is not a regular file'], $ruText)['said'] === ['dumps — не файл. Выберите файл архива в папке сайта.']
    && \Larena\Env\Admin\RestoreWords::refusals(['The database demo_php_test is not empty (12 tables); rerun with --overwrite yes (a backup is taken first)'], $ruText)['said'][0]
        === 'В базе данных сайта уже есть данные (таблиц: 12). Чтобы заменить их, отметьте «Заменить файлы и базу, если они уже есть» — сервер сначала сделает копию.',
    'The names and the count said in place.');
$unknown = \Larena\Env\Admin\RestoreWords::refusals(['Something new went wrong'], $ruText);
env_assert($unknown === ['said' => ['Something new went wrong'], 'codes' => [], 'all' => false], 'A line the panel does not know stays as the server wrote it.');
// The plan without a replacement and without a dump says nothing is replaced and the database stays.
$plain = \Larena\Env\Admin\RestoreWords::facts('demo-php.test', ['files' => 'site.zip'], $ruText);
env_assert($plain[2] === ['База данных', 'не меняется: дамп не выбран'] && $plain[3][1] === $russian['restore']['fact']['replaces_no']
    && $plain[4][1] === $russian['restore']['fact']['backup_no'] && $plain[5][1] === 'архив — в корзину сайта'
    && \Larena\Env\Admin\RestoreWords::consequence(['overwrite' => 'no'], $ruText) === $russian['restore']['consequence_empty'], 'Nothing replaced, said so: '.json_encode($plain, JSON_UNESCAPED_UNICODE));
// The recorded gateway answers the restore as the stand did; the job's last step in the panel's words, not half English.
$restoreOps = new PanelOperations($recorded);
$recordedRefusal = $restoreOps->plan($admin, SiteOperation::PhpRestore, 'demo-owned.test', ['files' => 'site.zip', 'dump' => 'db.sql']);
env_assert($recordedRefusal->plan === null && \Larena\Env\Admin\RestoreWords::refusals($recordedRefusal->checks->blockers ?? [], $ruText)['codes'] === ['no_database'],
    'A dump into a site without a database is refused as recorded.');
env_assert($restoreOps->plan($admin, SiteOperation::PhpRestore, 'demo-php.test', ['files' => 'other.zip', 'overwrite' => 'yes'])->reason === PanelReason::RECORDING_MISSING,
    'Another choice is missing, never answered with another\'s.');
$restoreJob = $restoreOps->start($admin, SiteOperation::PhpRestore, 'demo-php.test', ['files' => 'site.zip', 'dump' => 'db.sql.gz', 'overwrite' => 'yes']);
$restoreStatus = PanelJobStatus::fromData($recorded->ask(new PanelRequest('j', $admin, 'job:status', ['job_id' => (string) $restoreJob->jobId]))->data);
$stepTitles = array_column(\Larena\Env\Admin\JobOutcome::steps($restoreStatus), 'title');
env_assert($restoreStatus->command === 'site:restore-ready' && count($stepTitles) === 4 && $stepTitles[0] === 'Копия текущего сайта' && $stepTitles[3] === $lines['restore']['step_trash']
    && !str_contains(implode(' ', $stepTitles), 'переходит к аккаунту'), 'The copy first, then unpacking, the dump, the trash: '.json_encode($stepTitles, JSON_UNESCAPED_UNICODE));

// The card's reads asked ahead in one call (speed review 2026-11-16): what the site is everywhere, its check, route and the
// server's kinds on the overview only; nothing the server does not list.
$all = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.56.4'], 'gate' => ['commands' => ['site:describe', 'site:doctor', 'site:status', 'self:profiles']]]);
$card = static fn (string $tab, $capabilities): array => array_map(static fn ($read): string => $read->command.' '.json_encode($read->args),
    \Larena\Env\Http\Controllers\SiteAdminController::cardReads(new PanelActor('admin@example.com'), 'demo-php.test', $tab, $capabilities));
env_assert($card('overview', $all) === ['site:describe {"domain":"demo-php.test"}', 'site:doctor {"domain":"demo-php.test"}', 'site:status {"domain":"demo-php.test"}', 'self:profiles []'], json_encode($card('overview', $all)));
env_assert($card('files', $all) === ['site:describe {"domain":"demo-php.test"}'], 'Another section asks only what the site is.');
$noRoute = \Larena\Env\Gateway\PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.56.4'], 'gate' => ['commands' => ['site:describe', 'site:doctor']]]);
env_assert($card('overview', $noRoute) === ['site:describe {"domain":"demo-php.test"}', 'site:doctor {"domain":"demo-php.test"}'], 'A server without routes is not asked for them.');

echo "SiteRoutesTest passed.\n";
