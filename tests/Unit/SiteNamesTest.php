<?php

declare(strict_types=1);

// A site's names (owner decision 2026-11-07, simai-env 1.53.0): read, added and removed through the server's plan, in the
// «Имена сайта» block of «Домены и HTTPS». The answers of site:names and the two plans are written from ENV's contract
// (marked `written_from` in the recorded set) until ENV records them; a server that does not list site:names shows the
// tab as before.

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
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\SiteAdminController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Server\SiteName;
use Larena\Env\Server\SiteNames;
use Larena\Env\Server\SiteNamesReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$russian = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
$text = static function (string $key) use ($lines): string {
    $value = data_get($lines, $key);
    env_assert(is_string($value), "No English text for {$key}.");

    return $value;
};

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $text;
    $line = $text(str_replace('larena-env::admin.', '', $key));
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, $value, $line);
    }

    return $line;
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name.(($parameters['domain'] ?? $parameters['job'] ?? null) !== null ? '/'.implode('/', $parameters) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

$recorded = new RecordedAnswersGateway(env_recorded_path());
$admin = new PanelActor('admin@stand.test');
$owner = new PanelActor('demo@stand.test', 'demo');

// The three commands: a read kept like the site's other reads, and two actions planned before they run.
env_assert(PanelCommands::kind('site:names') === PanelCommands::READ && PanelCommands::kind('site:name-add') === PanelCommands::ACTION
    && PanelCommands::kind('site:name-remove') === PanelCommands::ACTION && PanelCommands::since('site:names') === '1.53.0', 'Three commands of simai-env 1.53.0.');
env_assert(CachingPanelGateway::keeps(PanelRequest::read($admin, 'site:names', ['domain' => 'demo-php.test'])), 'The names are kept like the site\'s other reads.');
PanelCommands::check('site:name-add', ['domain' => 'demo-php.test', 'name' => 'www.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'no']);
foreach ([['name' => 'www demo'], ['name' => '*.demo-php.test'], ['mode' => 'proxy'], ['check-dns' => 'maybe']] as $bad) {
    try {
        PanelCommands::check('site:name-add', $bad + ['domain' => 'demo-php.test', 'name' => 'www.demo-php.test']);
        env_assert(false, 'A name the gateway would refuse never leaves: '.json_encode($bad));
    } catch (PanelRefused $refused) {
        env_assert($refused->reasonCode === PanelReason::INVALID_ARGUMENT, 'Refused as an invalid argument.');
    }
}

// A server that does not list site:names (simai-env before 1.53.0, the recorded set of 1.49.0) has no names; one that
// lists them has.
$older = (new ServerOverviewReader($recorded))->read($admin)->overview?->capabilities;
env_assert($older instanceof PanelCapabilities && !$older->allows('site:names') && !$older->allows('site:name-add'), 'The recorded server has no names.');
$listing = static fn (array $commands): PanelCapabilities => PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.53.0'], 'gate' => ['commands' => $commands]]);
$newer = $listing(['site:describe', 'site:names', 'site:name-add', 'site:name-remove']);
env_assert($newer->allows('site:names') && $newer->takes('site:name-add', 'check-dns'), 'A server listing them has them, with their arguments.');
env_assert(!$listing(['site:describe'])->allows('site:names') && !PanelCapabilities::fromHostDescription(['simai_env' => ['version' => '1.52.1'], 'gate' => ['commands' => ['site:describe']]])->allows('site:names'),
    'A server listing its commands without site:names has none, whatever its release.');

// The reader: the contract's answer into rows; an owner reads their own site, another's is refused.
$reader = new SiteNamesReader($recorded);
$read = $reader->read($admin, 'demo-php.test');
env_assert($read->names instanceof SiteNames && $read->reason === null, 'The names read: '.(string) $read->reason);
env_assert(array_map(static fn (SiteName $name): string => $name->name, $read->names->names) === ['demo-php.test', 'www.demo-php.test', 'old-demo.test'], 'One row per name, in the server\'s order.');
[$main, $www, $old] = $read->names->names;
env_assert($main->kind === SiteName::PRIMARY && $main->mode === SiteName::SERVE && !$main->removable(), 'The main domain shows the site and is not removed here.');
env_assert($www->kind === SiteName::EXTRA && $www->mode === SiteName::REDIRECT && $www->pointsHere === true && $www->removable(), 'www redirects to the main domain and points here.');
env_assert($old->mode === SiteName::SERVE && $old->pointsHere === false && $old->addresses === ['198.51.100.7'] && !$old->https, 'An old domain shows the site and points elsewhere.');
env_assert($read->names->certificate === null && $read->names->suggest === [] && !$read->names->full(), 'No certificate, www is a name already: nothing suggested.');
$own = $reader->read($owner, 'demo-owned.test');
env_assert($own->names?->suggest === ['www.demo-owned.test'] && $own->names->names[0]->pointsHere === null, 'An owner\'s own site, www suggested, DNS not checked.');
$theirs = $reader->read($owner, 'demo-php.test');
env_assert($theirs->names === null && $theirs->refused && $theirs->reason === 'owner_scope', 'An owner does not read another owner\'s site.');

// Strict about the shape: an answer the contract does not have shows nothing rather than half of it.
$good = ['schema' => 'simai-site-names/1', 'domain' => 'shop.test',
    'names' => [
        ['name' => 'shop.test', 'kind' => 'primary', 'mode' => 'serve', 'dns' => ['points_here' => true, 'addresses' => ['203.0.113.5']], 'https' => true],
        ['name' => 'www.shop.test', 'kind' => 'extra', 'mode' => 'redirect', 'dns' => ['points_here' => true, 'addresses' => ['203.0.113.5']], 'https' => true],
        ['name' => 'shop.example', 'kind' => 'extra', 'mode' => 'serve', 'dns' => ['points_here' => null, 'addresses' => []], 'https' => false],
        ['name' => '*.shop.test', 'kind' => 'wildcard', 'mode' => 'serve', 'dns' => ['points_here' => true, 'addresses' => ['203.0.113.5']], 'https' => true]],
    'certificate' => ['names' => ['shop.test', 'www.shop.test', '*.shop.test'], 'issuer' => "Let's Encrypt", 'auto_renew' => true, 'not_after' => '2027-02-01T00:00:00Z'],
    'suggest' => ['www.shop.test']];
$shop = SiteNames::fromData($good);
env_assert(count($shop->names) === 4 && $shop->certificate?->names === ['shop.test', 'www.shop.test', '*.shop.test'] && $shop->certificate->autoRenew
    && $shop->suggest === [], 'The contract\'s whole shape reads; a suggested name the site has is not offered again.');
$broken = [
    'another schema' => ['schema' => 'simai-site-names/2'] + $good,
    'no names' => ['names' => []] + $good,
    'names not a list' => ['names' => ['a' => $good['names'][0]]] + $good,
    'no main domain' => ['names' => [$good['names'][1]]] + $good,
    'two main domains' => ['names' => [$good['names'][0], $good['names'][0]]] + $good,
    'a main domain not the site\'s' => ['domain' => 'other.test'] + $good,
    'a kind it has not' => ['names' => [$good['names'][0], ['kind' => 'alias'] + $good['names'][1]]] + $good,
    'a mode it has not' => ['names' => [$good['names'][0], ['mode' => 'proxy'] + $good['names'][1]]] + $good,
    'https not yes or no' => ['names' => [$good['names'][0], ['https' => 'yes'] + $good['names'][1]]] + $good,
    'DNS of another shape' => ['names' => [$good['names'][0], ['dns' => ['points_here' => 'yes', 'addresses' => []]] + $good['names'][1]]] + $good,
    'addresses not text' => ['names' => [$good['names'][0], ['dns' => ['points_here' => false, 'addresses' => [7]]] + $good['names'][1]]] + $good,
    'no DNS' => ['names' => [$good['names'][0], array_diff_key($good['names'][1], ['dns' => true])]] + $good,
    'a wildcard without its star' => ['names' => [$good['names'][0], ['name' => 'shop.test.example'] + $good['names'][3]]] + $good,
    'a star on an extra name' => ['names' => [$good['names'][0], ['name' => '*.shop.test'] + $good['names'][1]]] + $good,
    'a name twice' => ['names' => [$good['names'][0], $good['names'][1], $good['names'][1]]] + $good,
    'a suggestion not a name' => ['suggest' => ['not a name']] + $good,
    'a certificate of text' => ['certificate' => 'letsencrypt'] + $good,
];
foreach ($broken as $what => $data) {
    try {
        SiteNames::fromData($data);
        env_assert(false, "Refused: {$what}.");
    } catch (UnexpectedValueException) {
    }
}
// Another site's names answered for this one are not shown as this one's.
$answering = static fn (array $data): PanelGateway => new class ($data) implements PanelGateway {
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'status' => 'ok', 'kind' => 'result', 'data' => $this->data]);
    }

    public function source(): string
    {
        return 'test';
    }
};
env_assert((new SiteNamesReader($answering($good)))->read($admin, 'other.test')->reason === PanelReason::ANSWER_SHAPE_INVALID, 'The names of another site are refused.');
env_assert((new SiteNamesReader($answering(['schema' => 'simai-site-names/2'])))->read($admin, 'shop.test')->reason === PanelReason::ANSWER_SHAPE_INVALID, 'A broken answer is a reason, not an exception.');
$many = $good;
for ($i = 1; count($many['names']) <= SiteNames::MOST; $i++) {
    $many['names'][] = ['name' => "n{$i}.shop.test", 'kind' => 'extra', 'mode' => 'serve', 'dns' => ['points_here' => true, 'addresses' => []], 'https' => false];
}
env_assert(SiteNames::fromData($many)->full() && !$shop->full(), 'Twenty names besides every subdomain: the site is full.');

// The names are a read kept like the site's description, and forgotten once an action is confirmed.
$counting = new class ($recorded) implements PanelGateway {
    public int $asked = 0;

    public function __construct(private RecordedAnswersGateway $inner)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked += $request->command === 'site:names' ? 1 : 0;

        return $this->inner->ask($request);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
$caching = new CachingPanelGateway($counting, new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
(new SiteNamesReader($caching))->read($admin, 'demo-php.test');
(new SiteNamesReader($caching))->read($admin, 'demo-php.test');
env_assert($counting->asked === 1, 'Read twice, asked once.');
$started = (new PanelOperations($caching))->start($admin, SiteOperation::NameAdd, 'demo-php.test', ['name' => 'shop.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'yes']);
env_assert($started->jobId !== null, 'The name\'s job starts.');
(new SiteNamesReader($caching))->read($admin, 'demo-php.test');
env_assert($counting->asked === 2, 'After the job the names are read anew.');

// Adding and removing are the card's actions of «Домены и HTTPS», planned first as every action.
env_assert(SiteOperation::NameAdd->tab() === 'domains' && SiteOperation::NameRemove->tab() === 'domains', 'The names\' actions.');
env_assert(SiteOperation::NameAdd->arguments('demo-php.test', ['name' => 'www.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'no'])
    === ['domain' => 'demo-php.test', 'name' => 'www.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'no'], 'A name is sent as the gateway names it.');
env_assert(SiteOperation::switchedOn('check-dns', null), 'The DNS check is a tick, on unless unticked.');
$operations = new PanelOperations($recorded);
$addPlan = $operations->plan($admin, SiteOperation::NameAdd, 'demo-php.test', ['name' => 'shop.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'yes']);
env_assert($addPlan->plan?->command === 'site:name-add' && $addPlan->plan->args['name'] === 'shop.demo-php.test' && $addPlan->plan->effects === 'write', 'Adding a name is planned: '.(string) $addPlan->reason);
$removePlan = $operations->plan($admin, SiteOperation::NameRemove, 'demo-php.test', ['name' => 'old-demo.test']);
env_assert($removePlan->plan?->command === 'site:name-remove' && $removePlan->plan->args === ['domain' => 'demo-php.test', 'name' => 'old-demo.test'], 'Removing one too.');
env_assert($operations->start($admin, SiteOperation::NameRemove, 'demo-php.test', ['name' => 'old-demo.test'])->jobId === '000000000000000000000000', 'Confirmed, it starts a job.');
env_assert($operations->plan($owner, SiteOperation::NameAdd, 'demo-php.test', ['name' => 'www.demo-php.test'])->reason === 'owner_scope', 'An owner adds names to their own sites only.');
env_assert($operations->plan($admin, SiteOperation::NameAdd, 'demo-php.test', ['name' => 'not a name'])->reason === PanelReason::INVALID_ARGUMENT, 'A mistyped name never leaves.');

// The block on stubs of the admin's layout.
$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, env_temp_directory()), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$site = (new SiteDetailsReader($recorded))->read($admin, 'demo-php.test')->site;
env_assert($site !== null, 'The site reads.');
$screen = static fn (array $data): string => $factory->make('larena-env::admin.site', $data + [
    'domain' => 'demo-php.test', 'source' => 'recorded', 'site' => $site, 'refused' => false, 'reasonText' => null, 'tab' => 'domains',
    'tabs' => SiteAdminController::TABS, 'doctor' => null, 'phpVersions' => [], 'tabOperations' => [], 'sftpChoices' => [],
    'phpSettings' => null, 'phpExtensions' => null, 'databases' => null, 'sftpAccounts' => null, 'capabilities' => null,
    'logKind' => 'access', 'logKinds' => ['access', 'error'], 'logLines' => 200, 'logLineChoices' => [200, 500, 1000], 'logSearch' => '',
    'logGroup' => '', 'logGroups' => [], 'log' => null, 'logReasonText' => null, 'notice' => null,
    'namesAllows' => ['add' => true, 'remove' => true], 'namesReasonText' => null,
])->render();

$block = $screen(['siteNames' => $read]);
env_assert(str_contains($block, 'data-env-names="ok"') && substr_count($block, 'data-env-name="') === 3, 'One row per name: '.$block);
env_assert(str_contains($block, 'data-env-name="www.demo-php.test" data-env-name-kind="extra" data-env-name-mode="redirect"')
    && str_contains($block, 'data-env-name-dns="here" data-env-name-https="no"') && str_contains($block, 'data-env-name-dns="elsewhere"')
    && str_contains($block, 'data-env-name-addresses') && str_contains($block, '198.51.100.7'), 'Kind, mode, DNS and HTTPS of each row, and where an elsewhere name points.');
foreach (['names.kinds.primary', 'names.kinds.extra', 'names.modes.serve', 'names.modes.redirect', 'names.dns_states.here', 'names.dns_states.elsewhere', 'names.https_states.no'] as $key) {
    env_assert(str_contains($block, $text($key)), "The block says «{$text($key)}».");
}
env_assert(str_contains($block, 'data-env-name-remove="www.demo-php.test"') && str_contains($block, 'data-env-name-remove="old-demo.test"')
    && !str_contains($block, 'data-env-name-remove="demo-php.test"'), 'An extra name is removed, the main domain is not.');
env_assert(preg_match('#<form method="post" action="/admin/server/larena.env.admin.site.plan/demo-php.test" class="m-0" data-env-name-remove="old-demo.test">\s*<input type="hidden" name="_token" value="test">\s*'
    .'<input type="hidden" name="operation" value="name-remove">\s*<input type="hidden" name="name" value="old-demo.test">#', $block) === 1, 'Removing opens the server\'s plan.');
env_assert(str_contains($block, 'data-sf-modal-open="env-name-add"') && str_contains($block, 'data-env-name-add>') && str_contains($block, '<sf-input name="name"')
    && str_contains($block, '<sf-dropdown name="mode"') && str_contains($block, 'value="redirect" required') && preg_match('/name="check-dns" value="yes" checked/', $block) === 1
    && str_contains($block, 'name="operation" value="name-add"'), 'Adding a name: the name, redirect chosen, the DNS check ticked, through the plan.');
env_assert(!str_contains($block, 'data-env-name-suggest'), 'Nothing suggested when www is a name already.');
env_assert(strpos($block, 'data-env-names=') < strpos($block, 'data-env-certificate='), 'The names come first in «Домены и HTTPS».');

$ownBlock = $screen(['siteNames' => $own, 'domain' => 'demo-owned.test']);
env_assert(preg_match('#data-env-name-suggest="www.demo-owned.test">\s*<input type="hidden" name="_token" value="test">\s*<input type="hidden" name="operation" value="name-add">\s*'
    .'<input type="hidden" name="name" value="www.demo-owned.test">\s*<input type="hidden" name="mode" value="redirect">\s*<input type="hidden" name="check-dns" value="yes">#', $ownBlock) === 1
    && str_contains($ownBlock, 'text="Add www.demo-owned.test"') && str_contains($ownBlock, 'data-env-name-dns="unknown"'), 'www suggested in one click, sent to the main domain: '.$ownBlock);

$shopBlock = $screen(['siteNames' => new \Larena\Env\Server\SiteNamesResult($shop), 'domain' => 'shop.test']);
env_assert(str_contains($shopBlock, 'data-env-name="*.shop.test" data-env-name-kind="wildcard"') && str_contains($shopBlock, 'data-env-name-wildcard')
    && !str_contains($shopBlock, 'data-env-name-remove="*.shop.test"') && str_contains($shopBlock, $text('names.kinds.wildcard')), 'Every subdomain is read only.');
env_assert(substr_count($shopBlock, 'data-env-name-https="yes"') === 3 && str_contains($shopBlock, $text('names.https_states.yes')), 'The names the certificate covers are marked.');

$fullBlock = $screen(['siteNames' => new \Larena\Env\Server\SiteNamesResult(SiteNames::fromData($many)), 'domain' => 'shop.test']);
env_assert(str_contains($fullBlock, 'data-env-names-full') && !str_contains($fullBlock, 'data-env-name-add>') && !str_contains($fullBlock, 'data-env-name-suggest'), 'Twenty names: nothing more is offered.');
$readOnly = $screen(['siteNames' => $read, 'namesAllows' => ['add' => false, 'remove' => false]]);
env_assert(!str_contains($readOnly, 'data-env-name-remove') && !str_contains($readOnly, 'data-env-name-add>') && substr_count($readOnly, 'data-env-name="') === 3, 'Without the actions only the names are shown.');
$failed = $screen(['siteNames' => $theirs, 'namesReasonText' => 'This site belongs to another owner.']);
env_assert(str_contains($failed, 'data-env-names="failed"') && str_contains($failed, 'data-env-names-problem') && !str_contains($failed, 'data-env-names-rows'), 'A failed read is said, nothing guessed.');
env_assert(!str_contains($screen(['siteNames' => null]), 'data-env-names'), 'No block where the server has no names.');

// The controller: on a server without site:names the block is not there (no refusal); with them it is, and the add
// and remove forms post to the plan; a refusal comes back in the names' own words.
$describing = static fn (bool $names): PanelGateway => new class ($recorded, $names) implements PanelGateway {
    public function __construct(private RecordedAnswersGateway $inner, private bool $names)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        if ($request->command !== 'self:describe' || !$this->names) {
            return $this->inner->ask($request);
        }
        $document = json_decode(env_recorded_bytes($request->actor->owner === null ? 'self-describe.json' : 'self-describe.owner-'.$request->actor->owner.'.json'), true, 64, JSON_THROW_ON_ERROR);
        // A simai-env 1.53.0 server, as ENV's contract says it lists the new commands.
        $document['data']['simai_env']['version'] = '1.53.0';
        $document['data']['gate']['commands'] = array_merge($document['data']['gate']['commands'], ['site:names', 'site:name-add', 'site:name-remove']);

        return PanelAnswer::fromDocument($request->requestId, $document);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
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
        return 'en';
    }

    public function setLocale($locale): void
    {
    }
};
$controller = static fn (PanelGateway $gateway): SiteAdminController => new SiteAdminController(new SiteDetailsReader($gateway), new ServerOverviewReader($gateway),
    new SiteExtrasReader($gateway), new PanelOperations($gateway), $factory, $translator, names: new SiteNamesReader($gateway));
$asking = static function (string $uri, string $method = 'GET', array $input = [], ?PanelActor $as = null): Request {
    $request = Request::create($uri, $method, $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, $as ?? new PanelActor('admin@stand.test'));
    $request->setLaravelSession(new Store('panel', new ArraySessionHandler(10)));

    return $request;
};
$withNames = $controller($describing(true));
$page = (string) $withNames->show($asking('/admin/server/sites/demo-php.test?tab=domains'), 'demo-php.test')->render();
env_assert(str_contains($page, 'data-env-names="ok"') && str_contains($page, 'data-env-name-remove="old-demo.test"') && str_contains($page, 'data-env-name-add>')
    && !str_contains($page, 'data-env-operation-open="name-add"') && !str_contains($page, 'data-env-operation="name-remove"'), 'On 1.53.0 the block is there, its actions not repeated among the tab\'s buttons.');
$withoutNames = (string) $controller($describing(false))->show($asking('/admin/server/sites/demo-php.test?tab=domains'), 'demo-php.test')->render();
env_assert(str_contains($withoutNames, 'data-env-certificate=') && !str_contains($withoutNames, 'data-env-names') && !str_contains($withoutNames, 'role="alert"'),
    'An older server: the tab as before, no block and no refusal.');
$ownerPage = (string) $withNames->show($asking('/admin/server/sites/demo-owned.test?tab=domains', as: $owner), 'demo-owned.test')->render();
env_assert(str_contains($ownerPage, 'data-env-name-suggest="www.demo-owned.test"'), 'An owner sees their own site\'s names.');
$planned = $withNames->plan($asking('/admin/server/sites/demo-php.test/plan', 'POST', ['operation' => 'name-add', 'name' => 'shop.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'yes']), 'demo-php.test');
env_assert($planned instanceof \Illuminate\View\View && ($planned->getData()['hidden'] ?? null) === ['operation' => 'name-add', 'name' => 'shop.demo-php.test', 'mode' => 'redirect', 'check-dns' => 'yes']
    && $planned->getData()['plan']->command === 'site:name-add', 'The form opens the server\'s plan, the confirmation carrying the same fields.');
$ran = $withNames->run($asking('/admin/server/sites/demo-php.test/run', 'POST', ['operation' => 'name-remove', 'name' => 'old-demo.test']), 'demo-php.test');
env_assert(str_contains($ran->getTargetUrl(), 'larena.env.admin.job/000000000000000000000000'), 'Confirmed, the job\'s screen follows.');
$refusedRequest = $asking('/admin/server/sites/demo-php.test/plan', 'POST', ['operation' => 'name-add', 'name' => 'not a name']);
$refusedAnswer = $withNames->plan($refusedRequest, 'demo-php.test');
env_assert($refusedAnswer instanceof RedirectResponse && str_contains($refusedAnswer->getTargetUrl(), 'tab=domains')
    && $refusedRequest->session()->get('larena_env_notice') === $text('names.reasons.invalid_argument'), 'A name the server would not take is said in the names\' words.');
$older = $controller($describing(false))->plan($asking('/admin/server/sites/demo-php.test/plan', 'POST', ['operation' => 'name-add', 'name' => 'www.demo-php.test']), 'demo-php.test');
env_assert($older instanceof RedirectResponse && $older->getTargetUrl() === '/admin/server/larena.env.admin.site/demo-php.test?tab=overview', 'An older server is not asked for a name.');
env_assert($withNames->namesReasonText('name_taken') === 'This name is already taken by another site or by the panel\'s address.'
    && $withNames->namesReasonText('owner_scope') === $text('reasons.owner_scope'), 'A taken name in words; other reasons are the gateway\'s.');

// Every text of the block in Russian as in English, with the words the owner reads.
$keys = static function (array $lines, string $prefix = '') use (&$keys): array {
    $all = [];
    foreach ($lines as $key => $value) {
        $all = array_merge($all, is_array($value) ? $keys($value, $prefix.$key.'.') : [$prefix.$key]);
    }

    return $all;
};
env_assert($keys($russian['names']) === $keys($lines['names']), 'The names\' texts in both languages.');
env_assert($russian['names']['reasons']['name_taken'] === 'Это имя уже занято другим сайтом или адресом панели.' && $russian['names']['kinds'] === ['primary' => 'основное', 'extra' => 'дополнительное', 'wildcard' => 'все поддомены']
    && $russian['names']['modes'] === ['serve' => 'показывает сайт', 'redirect' => 'переадресует на основное'] && str_contains($russian['names']['reasons']['invalid_argument'], '20'), 'The owner\'s words.');
env_assert(is_string($russian['operations']['name-add'] ?? null) && is_string($russian['operations']['name-remove'] ?? null) && is_string($lines['operations']['name-remove'] ?? null), 'The plan\'s heading.');
$source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/partials/site-names.blade.php');
env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, 'The block prints nothing raw and holds no script.');

// The answers written from ENV's contract say so in the recorded set, to be replaced by ENV's recordings.
$manifest = json_decode(env_recorded_bytes('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
foreach (['site-names.demo-php.test.json', 'site-names.demo-owned.test.json', 'plan.site-name-add.json', 'plan.site-name-remove.json'] as $file) {
    $document = json_decode(env_recorded_bytes($file), true, 64, JSON_THROW_ON_ERROR);
    env_assert(str_contains((string) ($manifest['files'][$file]['written_from'] ?? ''), "ENV's contract") && str_contains((string) ($document['written_from'] ?? ''), "ENV's contract"), "{$file} is marked as written from the contract.");
}

echo "SiteNamesTest passed.\n";
