<?php

declare(strict_types=1);

// The block after the FASTPANEL comparison (2026-11-18), on a stand-in gateway: a service sites can do without stopped and
// started again (simai-env 1.62.0), never a critical one; a site paused and resumed, its caches cleared, its certificate
// removed, a file copied, a plain PHP site restored from an archive (1.57.0–1.57.1); the PHP versions a site may use, with
// 7.4 and 8.0 marked old and unsafe (owner msg-93e8e022); «Сделать копию базы»; a failed run without its end.

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
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Controllers\ServicesController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\PanelPlan;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\HostService;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Server\SiteProfile;
use Larena\Env\Server\SiteSummary;
use Larena\Env\Software\PhpSupport;
use Larena\Env\Software\PhpVersions;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$ruLines = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
$text = static function (string $key, array $replace = []) use ($lines): string {
    $value = data_get($lines, $key);
    env_assert(is_string($value), "No English text for {$key}.");
    foreach ($replace as $name => $with) {
        $value = str_replace(':'.$name, (string) $with, $value);
    }

    return $value;
};

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $lines;
    $value = data_get($lines, str_replace('larena-env::admin.', '', $key));
    if (!is_string($value)) {
        return $key;
    }
    foreach ($replace as $name => $with) {
        $value = str_replace(':'.$name, (string) $with, $value);
    }

    return $value;
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/'.$name.(isset($parameters['domain']) ? '/'.$parameters['domain'] : '').(isset($parameters['job']) ? '/'.$parameters['job'] : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

function csrf_token(): string
{
    return 'test';
}

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

// The stand-in server: simai-env 1.62.0 by its description, cron and Redis may stop, nginx is critical.
$gateway = new class () implements PanelGateway {
    /** @var list<PanelRequest> */
    public array $asked = [];
    public bool $cronActive = true;

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request;
        $answer = static fn (string $status, string $kind, array $data, array $more = []): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => $status, 'kind' => $kind, 'data' => $data] + $more);
        $plan = static fn (array $extra = []): PanelAnswer => $answer('ok', 'plan', ['command' => $request->command, 'args' => $request->args,
            'target' => $request->args['domain'] ?? 'host', 'description' => 'stand-in', 'effects' => 'write', 'lock' => 'site', 'applied' => false] + $extra);
        if ($request->confirm) {
            return $answer('ok', 'job', [], ['job_id' => 'eeeeeeeeeeeeeeeeeeeeeeee']);
        }
        switch ($request->command) {
            case 'self:describe':
                $describe = json_decode(env_recorded_bytes('self-describe.json'), true, 64, JSON_THROW_ON_ERROR)['data'];
                $describe['simai_env']['version'] = '1.62.0';
                unset($describe['gate']);
                $describe['sites'][] = ['domain' => 'paused.test', 'profile' => 'generic', 'php' => '7.4', 'ssl' => 'none', 'suspended' => true,
                    'answers' => ['http' => 503, 'path' => '/', 'checked_at' => '2026-11-18T10:00:00Z']];

                return $answer('ok', 'result', $describe);
            case 'service:list':
                return $answer('ok', 'result', ['schema' => 'simai-services/1', 'services' => [
                    ['name' => 'nginx', 'active' => 'active', 'enabled' => 'enabled', 'can_reload' => true, 'critical' => true, 'can_stop' => false],
                    ['name' => 'cron', 'active' => $this->cronActive ? 'active' : 'inactive', 'enabled' => 'enabled', 'can_reload' => false, 'critical' => false, 'can_stop' => true],
                ]]);
            case 'service:stop':
                if ($request->args['name'] === 'nginx') {
                    return $answer('refused', 'result', ['detail' => 'nginx is critical: sites stop answering without it; use service restart',
                        'checks' => ['blockers' => ['nginx is critical: sites stop answering without it; use service restart'], 'notes' => []]], ['reason' => 'critical']);
                }

                return $plan(['checks' => ['blockers' => [], 'notes' => ['While cron is stopped: block4-wp.test panel.localhost', 'While cron is stopped the server\'s own checks and alerts pause too']],
                    'service' => ['service' => 'cron', 'active' => 'active', 'critical' => false, 'server' => true,
                        'sites' => [['domain' => 'block4-wp.test', 'uses' => ['scheduler']], ['domain' => 'panel.localhost', 'uses' => ['scheduler']]], 'until' => 'start or reboot']]);
            case 'site:cache-clear':
                return $plan(['clear' => [['name' => 'opcache', 'state' => 'planned', 'target' => 'pool', 'detail' => null, 'reason' => null],
                    ['name' => 'nginx_cache', 'state' => 'skipped', 'target' => null, 'detail' => null, 'reason' => 'none']]]);
            case 'site:resume':
                return $answer('refused', 'result', [], ['reason' => 'not_suspended']);
            case 'service:start': case 'site:suspend': case 'ssl:remove': case 'file:copy': case 'site:restore-ready':
                return $plan();
            default:
                return PanelAnswer::refused($request->requestId, 'not_allowed');
        }
    }

    public function source(): string
    {
        return 'stand-in';
    }

    /** @return list<PanelRequest> */
    public function of(string $command): array
    {
        return array_values(array_filter($this->asked, static fn (PanelRequest $one): bool => $one->command === $command));
    }
};
// One person's session through a plan and its confirmation: the plan's receipt comes back with it (PlanReceipts).
$actionsSession = new Store('test', new ArraySessionHandler(60));
$asking = static function (string $uri, array $input) use ($actionsSession): Request {
    $request = Request::create($uri, 'POST', $input);
    $request->setLaravelSession($actionsSession);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor('admin@stand.test'));

    return $request;
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
$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, env_temp_directory()), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$operations = new PanelOperations($gateway);
$overviews = new ServerOverviewReader($gateway);
$extras = new SiteExtrasReader($gateway);

// 1. «Службы»: stop and start (simai-env 1.62.0). The server says which may stop; a critical one is never offered it.
$services = $extras->services(new PanelActor('admin@stand.test'));
env_assert($services !== null && $services[0]->critical && !$services[0]->canStop && !$services[0]->stoppable() && $services[1]->stoppable() && !$services[1]->startable(),
    'service:list: nginx critical, cron may stop.');
env_assert(!(new HostService('cron', 'inactive', 'enabled', null, false, false, true))->stoppable() && (new HostService('cron', 'inactive', 'enabled', null, false, false, true))->startable(),
    'A stopped service is started, not stopped again.');
env_assert(!HostService::fromData(['name' => 'redis-server', 'active' => 'active'])->stoppable(), 'An older server says nothing: nothing is offered to stop.');
$servicesPage = static fn (array $list, array $allows): string => $factory->make('larena-env::admin.services', ['source' => 'live', 'services' => $list, 'reasonText' => null,
    'allows' => $allows + ['restart' => true, 'reload' => true, 'log' => false], 'logOf' => null, 'log' => null, 'notice' => null])->render();
$page = $servicesPage([...$services, new HostService('redis-server', 'inactive', 'enabled', null, false, false, true)], ['stop' => true, 'start' => true]);
env_assert(substr_count($page, 'data-env-service-op="stop"') === 1 && str_contains($page, 'value="cron"') && substr_count($page, 'data-env-service-op="start"') === 1
    && preg_match('/data-env-service="nginx".*?data-env-service="/s', $page, $nginxRow) === 1 && !str_contains($nginxRow[0], 'data-env-service-op="stop"'),
    'Stop only for cron, start only for the stopped Redis, never stop for nginx: '.$page);
env_assert(!str_contains($servicesPage($services, ['stop' => false, 'start' => false]), 'data-env-service-op="stop"'), 'Without the server\'s command or the right: no stop.');

$servicesClock = 1_800_000_000;
$servicesController = new ServicesController($operations, $overviews, $extras, $planViews, $translator, receipts: env_plan_receipts($servicesClock));
$planned = $servicesController->plan($asking('/services/plan', ['op' => 'stop', 'name' => 'cron']));
$cronReceipt = (string) ($planViews->data['hidden']['receipt'] ?? '');
env_assert(!$planned instanceof RedirectResponse && $planViews->data['plan'] instanceof PanelPlan
    && array_diff_key($planViews->data['hidden'], ['receipt' => true]) === ['op' => 'stop', 'name' => 'cron'] && strlen($cronReceipt) === 32
    && $planViews->data['serviceStop']['sites'] === [['domain' => 'block4-wp.test', 'uses' => ['scheduler']], ['domain' => 'panel.localhost', 'uses' => ['scheduler']]]
    && $planViews->data['serviceStop']['server'] === true && $planViews->data['plan']->checks->notes === [],
    'A stop is planned: the sites that lose their scheduler, the server\'s checks pausing; the server\'s English notes replaced by the panel\'s words.');
$more = $factory->make('larena-env::admin.partials.plan-more', ['serviceStop' => $planViews->data['serviceStop'], 'plan' => $planViews->data['plan']])->render();
env_assert(str_contains($more, 'data-env-plan-stop-site="block4-wp.test"') && str_contains($more, 'scheduler') && str_contains($more, 'data-env-plan-stop-server')
    && str_contains($more, 'data-env-plan-stop-until'), 'The plan lists the sites and what each loses: '.$more);
env_assert(str_contains($factory->make('larena-env::admin.partials.plan-more', ['serviceStop' => ['name' => 'redis-server', 'sites' => [], 'server' => false]])->render(), 'data-env-plan-stop-sites="0"'),
    'A service no site uses says so.');
$refused = $servicesController->plan($asking('/services/plan', ['op' => 'stop', 'name' => 'nginx']));
env_assert(!$refused instanceof RedirectResponse && $planViews->data['refusedReason'] === 'critical'
    && $planViews->data['refused'] === ['Web server nginx cannot be stopped: sites stop working without it. It can only be restarted.'],
    'A critical service\'s stop is refused by the server and said in words: '.json_encode($planViews->data['refused'] ?? null));
$gateway->asked = [];
$stops = static fn (): array => array_values(array_filter($gateway->of('service:stop'), static fn (PanelRequest $one): bool => $one->confirm));
// The plan of cron's stop does not stop nginx: another service with cron's receipt is refused before the server is asked.
$neverRun = $servicesController->run($asking('/services/run', ['op' => 'stop', 'name' => 'nginx', 'receipt' => $cronReceipt]));
env_assert(str_ends_with($neverRun->getTargetUrl(), 'services') && $stops() === [], 'A critical service is never stopped, whatever the form says.');
$servicesController->run($asking('/services/run', ['op' => 'stop', 'name' => 'cron']));
env_assert($stops() === [] && $actionsSession->get('larena_env_notice') === $lines['reasons']['plan_receipt'], 'Without the plan\'s receipt nothing stops, and the page says why.');
$restarts = static fn (): array => array_values(array_filter($gateway->of('service:restart'), static fn (PanelRequest $one): bool => $one->confirm));
$servicesController->run($asking('/services/run', ['op' => 'restart', 'name' => 'cron', 'receipt' => $cronReceipt]));
env_assert($stops() === [] && $restarts() === [] && $actionsSession->get('larena_env_notice') === $lines['reasons']['plan_receipt'],
    'The receipt of a stop does not restart: another action is refused.');
$servicesController->plan($asking('/services/plan', ['op' => 'stop', 'name' => 'cron']));
$late = (string) $planViews->data['hidden']['receipt'];
$servicesClock += 901;
$servicesController->run($asking('/services/run', ['op' => 'stop', 'name' => 'cron', 'receipt' => $late]));
env_assert($stops() === [], 'A receipt older than 15 minutes is refused.');
$servicesController->plan($asking('/services/plan', ['op' => 'stop', 'name' => 'cron']));
$cronReceipt = (string) $planViews->data['hidden']['receipt'];
$stopped = $servicesController->run($asking('/services/run', ['op' => 'stop', 'name' => 'cron', 'receipt' => $cronReceipt]));
env_assert(str_ends_with($stopped->getTargetUrl(), '/larena.env.admin.job/eeeeeeeeeeeeeeeeeeeeeeee') && $stops()[0]->confirm, 'Confirmed, cron stops as a job.');
$servicesController->run($asking('/services/run', ['op' => 'stop', 'name' => 'cron', 'receipt' => $cronReceipt]));
env_assert(count($stops()) === 1, 'A receipt stops once.');
env_assert($servicesController->plan($asking('/services/plan', ['op' => 'start', 'name' => 'cron'])) instanceof RedirectResponse, 'A running service is not started again.');
$gateway->cronActive = false;
env_assert(!$servicesController->plan($asking('/services/plan', ['op' => 'start', 'name' => 'cron'])) instanceof RedirectResponse && $planViews->data['plan']->command === 'service:start' && $planViews->data['then'] === ['The service starts at once.'],
    'A stopped one is started through its plan.');
$gateway->cronActive = true;

// 2. A site's cheap actions on the server's own commands.
$admin = new PanelActor('admin@stand.test');
$describe = static fn (array $more = []): SiteDetails => SiteDetails::fromDescription($more + ['schema' => 'simai-site/1', 'domain' => 'demo-php.test', 'profile' => 'generic', 'php' => '8.3', 'ssl' => 'none']);
$site = $describe();
$paused = $describe(['suspended' => true]);
$capabilities = $overviews->read($admin)->overview->capabilities;
$offered = SiteOperation::offeredFor($site, $capabilities);
$offeredPaused = SiteOperation::offeredFor($paused, $capabilities);
env_assert(in_array(SiteOperation::SitePause, $offered, true) && !in_array(SiteOperation::SiteResume, $offered, true)
    && in_array(SiteOperation::SiteResume, $offeredPaused, true) && !in_array(SiteOperation::SitePause, $offeredPaused, true), 'Pause a working site, resume a paused one.');
env_assert(in_array(SiteOperation::CacheClear, $offered, true) && SiteOperation::CacheClear->tab() === 'overview' && SiteOperation::SitePause->asksNothing($capabilities)
    && SiteOperation::CacheClear->asksNothing($capabilities), 'On the card, asked at once.');
env_assert(!SiteOperation::SitePause->suitsSite($describe(['profile' => 'redirect', 'php' => 'none'])) && !SiteOperation::CacheClear->suitsSite($describe(['profile' => 'proxy', 'php' => 'none'])),
    'A forwarding name is not paused; a proxy has no cache of the panel\'s to clear.');
env_assert(!in_array(SiteOperation::CertificateRemove, $offered, true), 'No certificate, nothing to remove.');
env_assert(SiteOperation::CertificateRemove->tab() === 'domains' && SiteOperation::CertificateRemove->destroys()
    && SiteOperation::inRow([SiteOperation::CertificateRemove, SiteOperation::CacheClear]) === [SiteOperation::CacheClear]
    && SiteOperation::CertificateRemove->arguments('demo-php.test', []) === ['domain' => 'demo-php.test', 'delete-cert' => 'yes'],
    'The certificate is removed from its own block, asked as what cannot be undone, deleted with it.');
env_assert(SiteOperation::inRow([SiteOperation::SiteRemove, SiteOperation::SitePause, SiteOperation::CacheClear]) === [SiteOperation::SitePause, SiteOperation::CacheClear, SiteOperation::SiteRemove],
    'The card\'s actions start with an ordinary one; removing the site comes last (panel audit 2026-11-18).');
env_assert(in_array(SiteOperation::PhpRestore, $offered, true) && !SiteOperation::PhpRestore->suitsSite($describe(['profile' => 'wordpress']))
    && SiteOperation::PhpRestore->arguments('demo-php.test', ['files' => 'site.tar.gz', 'dump' => '', 'overwrite' => 'no']) === ['domain' => 'demo-php.test', 'files' => 'site.tar.gz', 'overwrite' => 'no'],
    'A plain PHP site is restored from its archive; no dump is sent when none is chosen: '.json_encode(SiteOperation::PhpRestore->arguments('demo-php.test', ['files' => 'site.tar.gz', 'dump' => '', 'overwrite' => 'no'])));

$gateway->asked = [];
$cleared = $operations->plan($admin, SiteOperation::CacheClear, 'demo-php.test');
env_assert($gateway->of('site:cache-clear')[0]->args === ['domain' => 'demo-php.test'] && $cleared->plan !== null
    && $cleared->plan->clears === [['name' => 'opcache', 'state' => 'planned', 'reason' => null], ['name' => 'nginx_cache', 'state' => 'skipped', 'reason' => 'none']],
    'The cache clearing is planned with what it clears and skips: '.json_encode($cleared->plan?->clears));
$clearedMore = $factory->make('larena-env::admin.partials.plan-more', ['plan' => $cleared->plan])->render();
env_assert(str_contains($clearedMore, 'data-env-plan-clear="opcache"') && str_contains($clearedMore, 'data-env-plan-clear-state="skipped"'), 'Said what it clears: '.$clearedMore);
env_assert($operations->plan($admin, SiteOperation::SitePause, 'demo-php.test')->plan?->command === 'site:suspend', 'A pause is planned.');
$resumed = $operations->plan($admin, SiteOperation::SiteResume, 'demo-php.test');
env_assert($resumed->plan === null && $resumed->reason === 'not_suspended' && is_string($lines['reasons']['not_suspended'] ?? null) && is_string($ruLines['reasons']['already_suspended'] ?? null),
    'A refusal of the pause commands has its words: '.json_encode($resumed->reason));
$operations->plan($admin, SiteOperation::CertificateRemove, 'demo-php.test');
env_assert($gateway->of('ssl:remove')[0]->args === ['domain' => 'demo-php.test', 'delete-cert' => 'yes'] && \Larena\Env\Admin\PlanValues::destroys('ssl:remove'),
    'ssl:remove deletes the certificate, planned and asked as what cannot be undone.');
$operations->plan($admin, SiteOperation::PhpRestore, 'demo-php.test', ['files' => 'site.zip', 'dump' => 'db.sql.gz', 'overwrite' => 'yes']);
env_assert($gateway->of('site:restore-ready')[0]->args === ['domain' => 'demo-php.test', 'files' => 'site.zip', 'dump' => 'db.sql.gz', 'overwrite' => 'yes'] && !$gateway->of('site:restore-ready')[0]->confirm,
    'A restore from the site\'s archive is planned first; nothing is restored before the confirmation.');
$restored = $operations->start($admin, SiteOperation::PhpRestore, 'demo-php.test', ['files' => 'site.zip']);
env_assert($restored->jobId === 'eeeeeeeeeeeeeeeeeeeeeeee' && $gateway->of('site:restore-ready')[1]->confirm, 'Confirmed, the restore runs as a job.');

// «Копировать» in the site's files (file:copy): beside the original, under the name given.
$copyName = [\Larena\Env\Files\FilePath::copyName('index.php'), \Larena\Env\Files\FilePath::copyName('site.tar.gz'), \Larena\Env\Files\FilePath::copyName('assets'), \Larena\Env\Files\FilePath::copyName('.env')];
env_assert($copyName === ['index-copy.php', 'site-copy.tar.gz', 'assets-copy', '.env-copy'], 'A copy\'s name offered: '.json_encode($copyName));
$operations->planCommand($admin, 'file:copy', ['domain' => 'demo-php.test', 'path' => 'public/index.php', 'to' => 'public/index-copy.php']);
env_assert($gateway->of('file:copy')[0]->args === ['domain' => 'demo-php.test', 'path' => 'public/index.php', 'to' => 'public/index-copy.php'], 'A copy is planned beside the original.');
$filesPage = \Larena\Env\Admin\SiteFilesPage::folder('demo-php.test', 'public', [new \Larena\Env\Files\FileEntry('index.php', 'file', 10, null, null),
    new \Larena\Env\Files\FileEntry('up', 'link', null, null, null, '/etc')], ['copy' => true, 'rename' => true, 'remove' => true], static fn (string $key, array $replace = []): string => $key, 'tok', true);
$fileRows = array_column($filesPage->rows, null, 'id');
env_assert(isset($filesPage->rowActions['copy']) && str_contains($filesPage->rowActions['copy']['href'], '&op=copy&entry=__ID__') && in_array('copy', $fileRows['public/index.php']['_actions'], true)
    && in_array('copy', $fileRows['public/up']['_actions'], true), 'The row\'s «⋯» offers «Копировать».');

// The sites list: a paused site is «На паузе», never a failing site; no alert for it.
$summary = SiteSummary::fromDescription(['domain' => 'paused.test', 'profile' => 'generic', 'php' => '7.4', 'ssl' => 'none', 'suspended' => true,
    'answers' => ['http' => 503, 'path' => '/', 'checked_at' => '2026-11-18T10:00:00Z']]);
$siteWarnings = \Larena\Env\Warnings\PanelWarnings::forSite($summary, new DateTimeImmutable('2026-11-18T10:05:00Z'));
env_assert($summary->suspended && array_filter($siteWarnings, static fn ($warning): bool => in_array($warning->kind, [\Larena\Env\Warnings\PanelWarnings::SITE_DOWN, \Larena\Env\Warnings\PanelWarnings::SITE_ERROR], true)) === [],
    'A paused site raises no alarm: '.json_encode(array_map(static fn ($warning) => $warning->kind, $siteWarnings)));
$overview = $overviews->read($admin)->overview;
$sitesPage = \Larena\Env\Admin\ServerSitesPage::of($overview, $text, new DateTimeImmutable('2026-11-18T10:05:00Z'), [], true);
$row = array_column($sitesPage->rows, null, 'id')['paused.test'] ?? null;
env_assert($row !== null && $row['state']['value'] === 'warning' && $row['state']['label'] !== $text('sites.state.paused') && ($row['answers']['text'] ?? null) === $text('sites.state.paused'),
    'The list says «На паузе» as a mark while the dot says a worse thing (no backups): '.json_encode($row));
$quiet = new \Larena\Env\Server\SiteSummary('quiet.test', 'generic', '8.3', 'none', null, null, null, 60, true, answers: 503, answersChecked: true, lastBackup: '2026-11-18T09:00:00Z', suspended: true);
$quietRow = \Larena\Env\Admin\ServerSitesPage::of(new \Larena\Env\Server\ServerOverview(...[...get_object_vars($overview), 'sites' => [$quiet]]), $text, new DateTimeImmutable('2026-11-18T10:05:00Z'), [], true)->rows[0];
env_assert($quietRow['state'] === ['value' => 'stopped', 'label' => $text('sites.state.paused')] && $quietRow['answers'] === '',
    'Otherwise «На паузе» is said once, by the dot\'s word, not again as a mark (panel audit 2026-11-18): '.json_encode($quietRow));

env_assert(\Larena\Env\Admin\SiteState::of(true, 503, true) === 'paused' && \Larena\Env\Admin\SiteState::status('paused') === 'warning' && \Larena\Env\Admin\SiteState::of(true, 503) === 'error',
    'The card and its menu say «На паузе», not «Ошибка сервера».');

// 3. PHP: only what the server allows a site of this kind; 7.4 and 8.0 marked, 5.6 never offered by the panel itself.
$profile = SiteProfile::fromData(['id' => 'generic', 'php' => ['versions' => ['8.3', '8.0', '7.4'], 'min' => '7.4', 'default' => '8.3']]);
env_assert($profile->phpVersions === ['8.3', '8.0', '7.4'] && $profile->phpMin === '7.4', 'The kind\'s PHP bounds are the server\'s.');
env_assert(PhpSupport::forSite($profile, ['8.3', '8.0', '7.4', '5.6'], '8.3') === ['8.3', '8.0', '7.4'], 'Only the versions the server allows the kind.');
env_assert(PhpSupport::forSite(SiteProfile::fromData(['id' => 'bitrix', 'php' => ['min' => '8.2']]), ['8.3', '8.1', '7.4'], '8.3') === ['8.3'], 'Without a list, from the kind\'s lowest version up.');
env_assert(PhpSupport::forSite(null, ['8.3', '7.4'], '8.0') === ['8.3', '8.0', '7.4'], 'An older server: the installed ones, the site\'s own kept.');
env_assert(PhpSupport::outdated('7.4') && PhpSupport::outdated('8.0') && !PhpSupport::outdated('8.1') && !PhpSupport::outdated('8.0', false) && PhpSupport::outdated('8.1', true)
    && PhpSupport::label('7.4', 'outdated, unsafe') === '7.4 · outdated, unsafe' && PhpSupport::label('8.3', 'outdated, unsafe') === '8.3', 'One list marks 7.4 and 8.0; the server\'s word wins.');
env_assert(($ruLines['php_outdated']['mark'] ?? null) === 'устарела, небезопасна' && ($ruLines['software']['php']['no_source'] ?? null) === 'Старые версии недоступны: не подключён источник.',
    'The words the owner chose.');
$noSource = PhpVersions::fromData(['native' => '8.3', 'versions' => [['version' => '8.3', 'native' => true]], 'installable' => []]);
$withOld = PhpVersions::fromData(['native' => '8.3', 'versions' => [['version' => '8.3', 'native' => true], ['version' => '7.4', 'outdated' => true]], 'installable' => []]);
$said = PhpVersions::fromData(['native' => '8.3', 'versions' => [['version' => '8.3', 'native' => true], ['version' => '8.1']], 'installable' => [],
    'installable_reason' => 'no_source', 'installable_words' => 'Подключите источник ondrej/php.']);
env_assert($noSource->sourceMissing() && !$withOld->sourceMissing() && $withOld->versions[1]->outdated === true && $said->sourceMissing() && $said->installableWords === 'Подключите источник ondrej/php.',
    'No source said where the server has only its own PHP, or where it says so itself.');
$securityBlade = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/security.blade.php');
env_assert(str_contains((string) $securityBlade, 'php_outdated.advice_title') && str_contains((string) $securityBlade, 'data-env-security-php-site'), '«Обновите PHP» on «Безопасность».');

// 4. «Выгрузить» said what it does: a backup copy of the database.
env_assert(($ruLines['operations']['database-export'] ?? null) === 'Сделать копию базы' && SiteOperation::DatabaseExport->command() === 'backup:data', '«Сделать копию базы».');

// 5. A failed run the server did not stamp with its end counts nothing up to now: its last step's moment, or no times.
$failed = \Larena\Env\Operations\PanelJobStatus::fromData(['job_id' => str_repeat('f', 24), 'state' => 'failed', 'command' => 'backup:data', 'args' => ['domain' => 'demo.test'],
    'started_at' => '2026-11-18T10:00:00Z', 'steps' => [['id' => 1, 'title' => 'Копия базы', 'state' => 'failed', 'started_at' => '2026-11-18T10:00:05Z']]]);
env_assert(\Larena\Env\Admin\JobOutcome::span($failed) === ['2026-11-18T10:00:00+00:00', '2026-11-18T10:00:05+00:00']
    && !isset(\Larena\Env\Admin\JobOutcome::steps($failed)[0]['started']), 'A failure without its end ends at its last known moment: '.json_encode(\Larena\Env\Admin\JobOutcome::span($failed)));
$bare = \Larena\Env\Operations\PanelJobStatus::fromData(['job_id' => str_repeat('f', 24), 'state' => 'failed', 'command' => 'backup:data', 'args' => [], 'started_at' => '2026-11-18T10:00:00Z']);
env_assert(\Larena\Env\Admin\JobOutcome::span($bare) === ['', ''], 'With nothing known, no times: nothing counted up to now.');
$running = \Larena\Env\Operations\PanelJobStatus::fromData(['job_id' => str_repeat('f', 24), 'state' => 'running', 'command' => 'backup:data', 'args' => [], 'started_at' => '2026-11-18T10:00:00Z']);
env_assert(\Larena\Env\Admin\JobOutcome::span($running) === ['2026-11-18T10:00:00+00:00', ''], 'A running job counts from its start.');

// The thing acted on is said once: «Над чем cron», not again as a setting.
env_assert(\Larena\Env\Admin\PlanValues::of(['name' => 'cron'], 'cron') === [] && count(\Larena\Env\Admin\PlanValues::of(['name' => 'shop'], 'demo.test')) === 1, 'The service\'s name is not repeated.');

// 6. Files (the auditor's remarks): days in full, the visitors' folder in words, its real path kept for «Подробнее».
$ruDays = ['one' => $ruLines['units']['day_one'], 'few' => $ruLines['units']['day_few'], 'many' => $ruLines['units']['day_many']];
$enDays = ['one' => $lines['units']['day_one'], 'few' => $lines['units']['day_few'], 'many' => $lines['units']['day_many']];
env_assert(\Larena\Env\Admin\Plural::of(7, $ruDays) === "7\u{00A0}дней" && \Larena\Env\Admin\Plural::of(1, $ruDays) === "1\u{00A0}день"
    && \Larena\Env\Admin\Plural::of(3, $ruDays) === "3\u{00A0}дня" && \Larena\Env\Admin\Plural::of(11, $ruDays) === "11\u{00A0}дней"
    && \Larena\Env\Admin\Plural::of(21, $ruDays) === "21\u{00A0}день" && \Larena\Env\Admin\Plural::of(7, $enDays, 'en') === "7\u{00A0}days" && \Larena\Env\Admin\Plural::days(7) === "7\u{00A0}days"
    && !str_contains($ruLines['files']['explain_trash'].$ruLines['files']['trash']['kept'], 'дн.'), 'Days said in full, never «дн.».');
$crumbs = \Larena\Env\Files\FilePath::crumbs('releases/20261105012440/wp-content', 'releases/20261105012440', 'Папка посетителей');
env_assert($crumbs === [['name' => 'Папка посетителей', 'path' => 'releases/20261105012440'], ['name' => 'wp-content', 'path' => 'releases/20261105012440/wp-content']]
    && \Larena\Env\Files\FilePath::crumbs('releases', 'releases/20261105012440', 'Папка посетителей') === [['name' => 'releases', 'path' => 'releases']]
    && \Larena\Env\Files\FilePath::crumbs('public', 'public', 'Папка посетителей') === [['name' => 'Папка посетителей', 'path' => 'public']]
    && !\Larena\Env\Files\FilePath::within('public2', 'public'), 'The visitors\' folder is one step in words: '.json_encode($crumbs, JSON_UNESCAPED_UNICODE));
env_assert(($ruLines['files']['secret'] ?? null) === 'Секреты' && ($ruLines['files']['none_chosen'] ?? null) === 'Файл не выбран', 'A mark, not a lowercase word; the file field in Russian.');

echo "ServerActionsTest passed.\n";
