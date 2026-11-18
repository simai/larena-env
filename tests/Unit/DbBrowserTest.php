<?php

declare(strict_types=1);

// «Открыть базу» (simai-env 1.58.0): site:db-browser {domain, minutes?} is planned and confirmed as every action, the
// plan refuses what the site lacks (plan_failed) and the panel says why, the address comes once in the first job:status
// after the job (ONCE_RESULT) and never in its log, the panel keeps it nowhere, site:db-browser-close closes it and
// site:db-browser-status says until when it is open. On a stand-in of the gateway, as the other tests of env.

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
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
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Operations\DbBrowserLink;
use Larena\Env\Operations\JobSecret;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\DbBrowserState;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteExtrasReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
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

function csrf_token(): string
{
    return 'test';
}

$url = 'https://shop.example.com/.simai-db/Zx8aQ2kP9vR4tY7uW1eI3oA5sD6fG0hJ/';

/**
 * The gateway's side of the browser as simai-env 1.58.0 answers it: a plan for a site that can have one, plan_failed for
 * one that cannot, a job, the address in the first job:status after the job only, a log without it, the status and close.
 */
$server = new class($url) implements PanelGateway {
    public bool $open = false;
    public ?string $minutes = null;
    public int $statusAsked = 0;
    /** @var list<string> */
    public array $asked = [];
    /** @var array<string, bool> the sites the plan refuses */
    public array $refuses = ['plain.example.com' => true];

    public function __construct(private string $url)
    {
    }

    public function source(): string
    {
        return 'stand-in';
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request->command;
        $ok = static fn (string $kind, array $data, ?string $job = null): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => $kind, 'data' => $data] + ($job !== null ? ['job_id' => $job] : []));
        $domain = $request->args['domain'] ?? '';
        switch ($request->command) {
            case 'site:db-browser':
            case 'site:db-browser-close':
                if ($request->command === 'site:db-browser' && isset($this->refuses[$domain])) {
                    return PanelAnswer::failed($request->requestId, 'plan_failed');
                }
                if (!$request->confirm) {
                    return $ok('plan', ['command' => $request->command, 'args' => $request->args, 'target' => $domain,
                        'description' => $request->command === 'site:db-browser' ? 'Open Adminer for the site\'s database' : 'Close the database browser',
                        'effects' => 'write', 'lock' => $domain, 'applied' => false]);
                }
                if ($request->command === 'site:db-browser') {
                    $this->open = true;
                    $this->minutes = $request->args['minutes'] ?? '60';
                    $this->statusAsked = 0;

                    return $ok('job', [], 'b00000000000000000000001');
                }
                $this->open = false;

                return $ok('job', [], 'c00000000000000000000001');
            case 'job:status':
                $job = $request->args['job_id'];
                $browser = $job === 'b00000000000000000000001';
                $result = $browser ? ['domain' => 'shop.example.com', 'expires_at' => '2026-11-18T13:00:00Z', 'engine' => 'mysql', 'database' => 'shop']
                    : ['domain' => 'shop.example.com', 'open' => false];
                // ONCE_RESULT: the first answer after the job hands the address out; later ones name it in shown_once.
                if ($browser && $this->statusAsked++ === 0) {
                    $result['browser_url'] = $this->url;
                } elseif ($browser) {
                    $result['shown_once'] = ['browser_url'];
                }

                return $ok('result', ['job_id' => $job, 'state' => 'succeeded', 'exit_code' => 0, 'command' => $browser ? 'site:db-browser' : 'site:db-browser-close',
                    'args' => ['domain' => 'shop.example.com'] + ($browser ? ['minutes' => (string) $this->minutes] : []), 'created_at' => '2026-11-18T12:00:00Z', 'result' => $result]);
            case 'job:log':
                return $ok('result', ['job_id' => $request->args['job_id'], 'lines' => ['Opening the database browser of shop.example.com', 'Done'], 'next' => 2, 'done' => true]);
            case 'site:db-browser-status':
                return $ok('result', ['schema' => 'simai-db-browser/1', 'domain' => $domain, 'open' => $this->open, 'expires_at' => $this->open ? '2026-11-18T13:00:00Z' : null]);
        }

        return PanelAnswer::refused($request->requestId, 'not_allowed');
    }
};
$admin = new PanelActor('admin@example.com');
$owner = new PanelActor('staff@example.com', 'demo');

// The commands: an action with minutes 5 to 480, its close, its status; from simai-env 1.58.0.
env_assert(PanelCommands::kind('site:db-browser') === PanelCommands::ACTION && PanelCommands::kind('site:db-browser-close') === PanelCommands::ACTION
    && PanelCommands::kind('site:db-browser-status') === PanelCommands::READ && PanelCommands::since('site:db-browser') === '1.58.0', 'Two actions and a read of 1.58.0.');
foreach (['5', '15', '60', '240', '480'] as $minutes) {
    env_assert(PanelCommands::valid('site:db-browser', 'minutes', $minutes), "{$minutes} minutes are taken.");
}
foreach (['4', '481', '0', '60 ', 'x', '1000'] as $minutes) {
    env_assert(!PanelCommands::valid('site:db-browser', 'minutes', $minutes), "{$minutes} minutes are refused before the server.");
}
env_assert(!(new PanelCapabilities('1.57.0'))->allows('site:db-browser') && (new PanelCapabilities('1.58.0'))->allows('site:db-browser-close'), 'Offered from 1.58.0 only.');

// What stops it: the panel knows that a site has no database, or no certificate (the browser opens over HTTPS: the hint
// and «Получить сертификат» at once, FASTPANEL comparison 2026-11-18); every other reason is the server's (simai-env 1.61.0).
$site = static fn (array $over = []): SiteDetails => SiteDetails::fromDescription($over + ['schema' => 'simai-site/1', 'domain' => 'shop.example.com', 'profile' => 'generic',
    'php' => '8.3', 'ssl' => 'letsencrypt', 'database' => ['engine' => 'mysql'], 'certificate' => ['names' => ['shop.example.com'], 'days_left' => 60]]);
env_assert(DbBrowserState::refusal($site()) === null && DbBrowserState::refusal($site(['database' => null])) === 'no_database', 'Without a database there is nothing to open.');
env_assert(DbBrowserState::refusal($site(['php' => 'none'])) === null && DbBrowserState::refusal($site(['profile' => 'alias'])) === null
    && DbBrowserState::refusal($site(['suspended' => true])) === null,
    'No PHP, an alias, paused: no longer worked out from the description; the server names them.');
env_assert(DbBrowserState::refusal($site(['ssl' => 'none', 'certificate' => null])) === 'no_https'
    && DbBrowserState::refusal($site(['ssl' => 'none', 'certificate' => null, 'database' => null])) === 'no_database', 'No certificate: no HTTPS, said before anyone asks.');
env_assert(SiteOperation::DatabaseBrowse->suitsSite($site()) && SiteOperation::DatabaseBrowse->suitsSite($site(['ssl' => 'none', 'certificate' => null]))
    && !SiteOperation::DatabaseBrowse->suitsSite($site(['database' => null]))
    && SiteOperation::DatabaseBrowse->tab() === 'database' && SiteOperation::DatabaseBrowse->fields() === ['minutes'] && SiteOperation::DatabaseBrowseClose->asksNothing()
    && SiteOperation::DatabaseBrowse->plansAsChosen() && SiteOperation::PhpVersion->plansAsChosen() && SiteOperation::BackupSchedule->plansAsChosen()
    && !SiteOperation::DatabaseBrowseClose->plansAsChosen() && !SiteOperation::RedirectAdd->plansAsChosen() && !SiteOperation::SftpCreate->plansAsChosen()
    && SiteOperation::SftpDisable->plansAsChosen(),
    'Offered on «База данных» wherever there is a database; closing asks nothing.');
foreach (SiteOperation::DatabaseBrowse->arguments('shop.example.com', ['minutes' => '15', 'other' => 'x']) as $name => $value) {
    env_assert(in_array($name, ['domain', 'minutes'], true), 'Only the domain and the minutes are sent.');
}

// The plan, its refusal, the job.
$operations = new PanelOperations($server);
$plan = $operations->plan($admin, SiteOperation::DatabaseBrowse, 'shop.example.com', ['minutes' => '15']);
env_assert($plan->plan?->command === 'site:db-browser' && $plan->plan->args['minutes'] === '15', 'Planned first, for the chosen term.');
env_assert($operations->plan($admin, SiteOperation::DatabaseBrowse, 'plain.example.com', ['minutes' => '60'])->reason === 'plan_failed', 'A site that cannot have one: plan_failed.');
$refusedWith = static function (callable $make): ?string {
    try {
        $make();
    } catch (PanelRefused $refused) {
        return $refused->reasonCode;
    }

    return null;
};
env_assert($refusedWith(static fn () => PanelRequest::plan($admin, 'site:db-browser', ['domain' => 'shop.example.com', 'minutes' => '1000'])) === 'invalid_argument', 'A term the server would refuse never leaves.');
$started = $operations->start($admin, SiteOperation::DatabaseBrowse, 'shop.example.com', ['minutes' => '15']);
env_assert($started->jobId === 'b00000000000000000000001' && $server->open && $server->minutes === '15', 'Confirmed, it opens for the term chosen.');

// The address once: the first job:status hands it out, never through the panel's cache, never in the log.
$cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());
$kept = new CachingPanelGateway($server, $cache);
$first = (new PanelOperations($kept))->view($admin, (string) $started->jobId);
env_assert($first->status?->browser instanceof DbBrowserLink && $first->status->browser->url === $url && $first->status->browser->engine === 'mysql'
    && $first->status->browser->database === 'shop' && $first->status->browser->expiresAt === '2026-11-18T13:00:00Z', 'The first answer after the job: the address, its term, its database.');
env_assert(!str_contains(implode("\n", $first->log->lines ?? []), '.simai-db'), 'The job\'s log never carries the address.');
env_assert(!str_contains(print_r($first->status->browser, true), 'Zx8aQ2'), 'The link is never printed by accident.');
env_assert(JobSecret::in(['result' => ['browser_url' => $url]]) && JobSecret::in(['result' => ['db_password' => 'x']]) && !JobSecret::in(['result' => ['shown_once' => ['browser_url']]]),
    'Every value given once is kept from the panel\'s cache.');
$again = (new PanelOperations($kept))->view($admin, (string) $started->jobId);
env_assert($again->status?->browser === null && in_array('browser_url', $again->status->shownOnce, true) && $server->statusAsked === 2, 'Asked again, the server only says it was shown: nothing was kept.');
env_assert(DbBrowserLink::fromData(['browser_url' => 'https://evil.example.com/phish']) === null && DbBrowserLink::fromData(['browser_url' => 'http://shop.example.com/.simai-db/Zx8aQ2kP9vR4tY7u/']) === null
    && DbBrowserLink::fromData(['browser_url' => 'https://shop.example.com/.simai-db/Zx8a"onclick=x/']) === null, 'Only the site\'s own HTTPS browser address is shown as one.');

// Until when, and closing it early.
$extras = new SiteExtrasReader($server);
env_assert($extras->dbBrowser($admin, 'shop.example.com') == new DbBrowserState(true, '2026-11-18T13:00:00Z'), 'Open until its term.');
$close = $operations->plan($admin, SiteOperation::DatabaseBrowseClose, 'shop.example.com');
env_assert($close->plan?->command === 'site:db-browser-close', 'Closing is planned too.');
env_assert($operations->start($admin, SiteOperation::DatabaseBrowseClose, 'shop.example.com')->jobId !== null && $extras->dbBrowser($admin, 'shop.example.com')?->open === false, 'Closed.');

// The screens: the job shows the address to open in a new tab, once; the site's «База данных» says what it can.
$files = new Filesystem();
$resolver = new EngineResolver();
$compiled = env_temp_directory();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, $compiled), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
$job = static fn ($status): string => $factory->make('larena-env::admin.job', ['jobId' => 'b00000000000000000000001', 'source' => 'stand-in', 'status' => $status,
    'log' => null, 'refused' => false, 'reasonText' => null, 'notice' => null])->render();
$shown = $job($first->status);
env_assert(str_contains($shown, 'data-env-job-browser') && str_contains($shown, 'href="'.$url.'"') && str_contains($shown, 'target="_blank" rel="noopener noreferrer"')
    && str_contains($shown, 'data-larena-action-keep') && str_contains($shown, 'datetime="2026-11-18T13:00:00+00:00"'), 'The address, in a new tab with no referrer, kept from a closing panel.');
$later = $job($again->status);
env_assert(!str_contains($later, '.simai-db') && str_contains($later, 'data-env-job-browser-shown'), 'Later the screen says it was shown, nothing else.');

$card = static fn (array $data): string => $factory->make('larena-env::admin.site', $data + [
    'domain' => 'shop.example.com', 'source' => 'stand-in', 'refused' => false, 'reasonText' => null, 'tab' => 'database', 'tabs' => \Larena\Env\Http\Controllers\SiteAdminController::TABS,
    'doctor' => null, 'phpVersions' => ['8.3'], 'phpSettings' => null, 'phpExtensions' => null, 'databases' => null, 'sftpAccounts' => null, 'capabilities' => null, 'notice' => null,
    'sftpChoices' => [], 'tabOperations' => [SiteOperation::DatabaseBrowse, SiteOperation::DatabaseBrowseClose, SiteOperation::DatabaseExport],
])->render();
$closed = $card(['site' => $site(), 'dbBrowser' => ['state' => new DbBrowserState(false, null), 'refusal' => null, 'certificate' => true]]);
env_assert(str_contains($closed, 'data-env-db-browser="closed"') && str_contains($closed, 'data-env-db-browser-open') && str_contains($closed, 'data-env-db-browser-minutes')
    && str_contains($closed, '<option value="60"  selected >1 hour</option>') && str_contains($closed, '<option value="15" >15 minutes</option>') && str_contains($closed, '<option value="240" >4 hours</option>')
    && !str_contains($closed, 'data-env-operation-open="database-browse"') && str_contains($closed, 'data-env-operation-open="database-export"'),
    'Closed: «Открыть базу» with 15 minutes, an hour or four, from its own block, not the row of buttons.');
$open = $card(['site' => $site(), 'dbBrowser' => ['state' => new DbBrowserState(true, '2026-11-18T13:00:00Z'), 'refusal' => null, 'certificate' => true]]);
env_assert(str_contains($open, 'data-env-db-browser="open"') && str_contains($open, 'data-env-db-browser-until') && str_contains($open, 'format="time"')
    && str_contains($open, 'data-env-db-browser-close') && !str_contains($open, '.simai-db'), 'Open: until when and «Закрыть», never the address.');
$noHttps = $card(['site' => $site(['ssl' => 'none', 'certificate' => null]), 'tabOperations' => [SiteOperation::DatabaseBrowseClose],
    'dbBrowser' => ['state' => new DbBrowserState(false, null), 'refusal' => 'no_https', 'certificate' => true]]);
env_assert(str_contains($noHttps, 'data-env-db-browser-refusal="no_https"') && str_contains($noHttps, $text('db_browser.need_https'))
    && str_contains($noHttps, 'data-env-db-browser-certificate') && str_contains($noHttps, 'action=certificate-issue') && !str_contains($noHttps, 'data-env-db-browser-open'),
    'No HTTPS: «Нужен HTTPS сайта» with «Получить сертификат», and no button that leads into a refusal.');
foreach (['no_database', 'no_php', 'alias', 'suspended'] as $why) {
    $refused = $card(['site' => $site(), 'tabOperations' => [], 'dbBrowser' => ['state' => null, 'refusal' => $why, 'certificate' => true]]);
    env_assert(str_contains($refused, 'data-env-db-browser-refusal="'.$why.'"') && str_contains($refused, htmlspecialchars($text('db_browser.refusals.'.$why), ENT_QUOTES)) && !str_contains($refused, 'data-env-db-browser-certificate'), "Refused {$why}: said in words.");
}
foreach (['ru', 'en'] as $locale) {
    $words = require dirname(__DIR__, 2).'/resources/lang/'.$locale.'/admin.php';
    foreach (array_merge(DbBrowserState::REFUSALS, ['other']) as $why) {
        env_assert(is_string($words['db_browser']['refusals'][$why] ?? null), "{$locale}: the words of {$why}.");
    }
}

echo "DbBrowserTest passed.\n";
