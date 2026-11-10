<?php

declare(strict_types=1);

// The rules of a site's profile in «Проверка» (simai-env 1.55.1): site:doctor names each rule with its key, what the
// server found, since when it does not pass and the fix it offers; «Исправить…» goes through the server's plan and a
// job (site:check-fix). The answers are ENV's own recordings (simai-env v1.55.1, testing/fixtures/panel, marked
// `recorded_from` in the set). A server without any of this shows the block as before.

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
use Larena\Env\Admin\PlanValues;
use Larena\Env\Admin\SiteChecks;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelAnswerStatus;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\SiteAdminController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteDetailsReader;
use Larena\Env\Server\SiteDoctor;
use Larena\Env\Server\SiteExtrasReader;

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
    return '/admin/server/'.$name.(($parameters['domain'] ?? $parameters['job'] ?? null) !== null ? '/'.implode('/', $parameters) : '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="test">';
}

$recorded = new RecordedAnswersGateway(env_recorded_path());
$admin = new PanelActor('admin@stand.test');
$owner = new PanelActor('demo@stand.test', 'demo');
$bitrix = 'demo-bitrix.test';

// The two commands: a rule's state is a read, its fix an action planned before it runs; an id the gateway would refuse
// never leaves.
env_assert(PanelCommands::kind('site:check-state') === PanelCommands::READ && PanelCommands::kind('site:check-fix') === PanelCommands::ACTION
    && PanelCommands::since('site:check-fix') === '1.55.1' && in_array('site:check-fix', PanelCommands::actions(), true), 'Two commands of simai-env 1.55.1.');
PanelCommands::check('site:check-fix', ['domain' => $bitrix, 'id' => 'bitrix.db-charset'], true);
foreach ([['id' => 'Bitrix DB'], ['id' => '../x'], ['id' => ''], []] as $bad) {
    try {
        PanelCommands::check('site:check-fix', $bad + ['domain' => $bitrix]);
        env_assert(false, 'A rule id the gateway would refuse never leaves: '.json_encode($bad));
    } catch (PanelRefused $refused) {
        env_assert($refused->reasonCode === PanelReason::INVALID_ARGUMENT, 'Refused as an invalid argument.');
    }
}
env_assert(PanelCommands::isCheckId('php.fpm-graceful-reload') && !PanelCommands::isCheckId('rm -rf') && !PanelCommands::isCheckId(''), 'A rule\'s id.');

// The answers are ENV's recordings of simai-env v1.55.1: the set says where each comes from.
$manifest = json_decode(env_recorded_bytes('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
foreach (['site-doctor.demo-bitrix.test.json', 'site-check-state.bitrix-db-charset.json', 'plan.site-check-fix.json', 'job-site-check-fix.json',
    'job-site-check-fix-status.json', 'refused.unknown-check.json', 'refused.check-ok.json'] as $file) {
    env_assert(($manifest['files'][$file]['recorded_from'] ?? null) === 'simai-env v1.55.1, testing/fixtures/panel/'.$file
        && hash_equals((string) ($manifest['files'][$file]['sha256'] ?? ''), hash('sha256', env_recorded_bytes($file))), "{$file} is marked as recorded by ENV.");
}

// The check of a 1C-Bitrix site: what did not pass in the server's order, the profile's rules among them with what the
// server found, since when and the fix it offers; the rules that pass are kept for the list.
$reader = new SiteDetailsReader($recorded);
$doctor = $reader->doctor($admin, $bitrix);
env_assert($doctor instanceof SiteDoctor && $doctor->status === 'FAIL' && $doctor->passed === 39 && count($doctor->problems) === 17, 'The check reads: 13 problems and 4 warnings, 39 passed.');
$rules = array_values(array_filter($doctor->problems, static fn (array $check): bool => $check['key'] !== null));
env_assert(array_map(static fn (array $check): array => [$check['key'], $check['status'], $check['fix']], $rules) === [
    ['bitrix.ownership', 'WARN', null],
    ['bitrix.docroot-leftovers', 'FAIL', 'bitrix.docroot-leftovers'],
    ['bitrix.db-session-modes', 'WARN', 'bitrix.db-session-modes'],
    ['bitrix.db-charset', 'WARN', 'bitrix.db-charset'],
    ['php.fpm-graceful-reload', 'WARN', 'php.fpm-graceful-reload'],
], 'Five rules do not pass; the server offers a fix for four of them.');
env_assert($rules[3]['detail'] === 'utf8mb3/utf8mb3_general_ci (expected utf8mb4/utf8mb4_unicode_ci)' && $rules[3]['since'] === '2026-01-01T00:00:00Z'
    && $rules[3]['title'] === 'Bitrix database default charset' && $rules[3]['area'] === 'rules', 'A rule says what the server found and since when.');
env_assert(array_map(static fn (array $check): string => (string) $check['key'], $doctor->passedRules)
    === ['bitrix.dot-default', 'bitrix.timezone', 'bitrix.opcache', 'bitrix.upload-limit', 'bitrix.sef-fallback', 'bitrix.static-gzip', 'bitrix.static-cache']
    && $doctor->passedRules[1]['since'] === null && $doctor->passedRules[1]['detail'] === 'UTC', 'Seven rules pass, with no «since».');
$plain = array_values(array_filter($doctor->problems, static fn (array $check): bool => $check['key'] === null));
env_assert(count($plain) === 12 && $plain[0] === ['status' => 'FAIL', 'area' => 'fs', 'title' => 'Writable path', 'key' => null, 'fix' => null, 'since' => null, 'detail' => null],
    'A check that is not a rule has no key, detail or fix.');

// The rows the block prints: a fix only where the server lists the command, names one and the rule does not pass.
$offered = SiteChecks::problems($doctor, true);
env_assert(array_values(array_filter(array_column($offered, 'fix'))) === ['bitrix.docroot-leftovers', 'bitrix.db-session-modes', 'bitrix.db-charset', 'php.fpm-graceful-reload'],
    'Four fixes are offered.');
env_assert(array_filter(array_column(SiteChecks::problems($doctor), 'fix')) === [] && array_filter(array_column(SiteChecks::problems($doctor, false), 'fix')) === [],
    'None on a server that does not list site:check-fix.');
env_assert(array_filter(array_column(SiteChecks::passed($doctor), 'fix')) === [] && count(SiteChecks::passed($doctor)) === 7, 'A rule that passes is never fixed.');
$charset = array_values(array_filter($offered, static fn (array $row): bool => $row['key'] === 'bitrix.db-charset'))[0];
env_assert($charset['tone'] === 'warning' && $charset['text'] === $text('doctor.rules.bitrix_db_charset') && $charset['since'] === '01.01.2026 00:00 UTC'
    && $charset['area'] === $text('doctor.areas.rules') && $offered[0]['tone'] === 'error' && $offered[0]['text'] === 'Writable path', 'A row in words.');
env_assert(SiteDoctor::checkText('A new rule of the server', 'bitrix.something-new') === 'A new rule of the server' && SiteDoctor::checkText('PHP version') === $text('doctor.checks.php_version'),
    'A rule the panel has no words for keeps the server\'s title.');
env_assert(SiteChecks::problems(null) === [] && SiteChecks::passed(null) === [], 'No check, no rows.');
// What the contract does not have is no fix: an id the gateway would not take, a fix without a rule, a text that is not text.
$odd = SiteDoctor::fromData(['schema' => 'simai-site-diagnostics/1', 'profile' => 'bitrix', 'status' => 'WARN', 'checks' => [
    ['id' => 1, 'status' => 'WARN', 'area' => 'rules', 'title' => 'A', 'key' => 'a.rule', 'fix' => 'not an id', 'since' => 7, 'detail' => ['x']],
    ['id' => 2, 'status' => 'WARN', 'area' => 'rules', 'title' => 'B', 'fix' => 'b.rule', 'since' => '2026-01-01T00:00:00Z', 'detail' => 'd'],
    ['id' => 3, 'status' => 'PASS', 'area' => 'rules', 'title' => 'C', 'key' => 'c.rule', 'fix' => 'c.rule', 'since' => null, 'detail' => 'fine'],
    ['id' => 4, 'status' => 'SKIP', 'area' => 'rules', 'title' => 'D', 'key' => 'd.rule', 'fix' => 'd.rule', 'since' => null, 'detail' => null],
]]);
env_assert(array_column(SiteChecks::problems($odd, true), 'fix') === [null, null] && SiteChecks::problems($odd, true)[0]['detail'] === null && SiteChecks::problems($odd, true)[0]['since'] === null
    && SiteChecks::problems($odd, true)[1]['detail'] === null && count($odd->passedRules) === 1 && $odd->passed === 1, 'Nothing is offered that the server would not take.');

// A check of an older server (the recorded set's own, simai-env 1.49.0): no rules, the rows it always had.
$olderDoctor = $reader->doctor($admin, 'demo-php.test');
env_assert($olderDoctor instanceof SiteDoctor && $olderDoctor->passedRules === [] && $olderDoctor->passed === 21
    && $olderDoctor->problems === [['status' => 'WARN', 'area' => 'php', 'title' => 'Recommended extensions', 'key' => null, 'fix' => null, 'since' => null, 'detail' => null]],
    'An older check has no rules.');
env_assert(array_column(SiteChecks::problems($olderDoctor, true), 'fix') === [null], 'And no fix, whatever the server lists.');

// The recorded server answers about a rule as simai-env does: the state of one, the plan and the job of another's fix;
// a rule that passes is already fixed, one the check does not have is unknown.
$state = $recorded->ask(PanelRequest::read($admin, 'site:check-state', ['domain' => $bitrix, 'id' => 'bitrix.db-charset']));
env_assert($state->status === PanelAnswerStatus::Ok && ($state->data['schema'] ?? null) === 'simai-site-check/1' && ($state->data['state'] ?? null) === 'needed'
    && ($state->data['id'] ?? null) === 'bitrix.db-charset', 'A rule\'s state reads.');
env_assert($recorded->ask(PanelRequest::read($admin, 'site:check-state', ['domain' => $bitrix, 'id' => 'bitrix.no-such']))->reason === 'unknown_check'
    && $recorded->ask(PanelRequest::read($admin, 'site:check-state', ['domain' => $bitrix, 'id' => 'bitrix.timezone']))->reason === PanelReason::RECORDING_MISSING,
    'An unknown rule is refused; a state the set does not hold is missing, never another\'s.');
$operations = new PanelOperations($recorded);
env_assert(SiteOperation::CheckFix->command() === 'site:check-fix' && SiteOperation::CheckFix->tab() === 'overview' && SiteOperation::CheckFix->fields() === ['id']
    && SiteOperation::CheckFix->arguments($bitrix, ['id' => 'bitrix.db-session-modes', 'name' => 'x']) === ['domain' => $bitrix, 'id' => 'bitrix.db-session-modes'], 'The fix is a site\'s action.');
$planned = $operations->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.db-session-modes']);
env_assert($planned->plan?->command === 'site:check-fix' && $planned->plan->args === ['domain' => $bitrix, 'id' => 'bitrix.db-session-modes'] && $planned->plan->effects === 'write'
    && $planned->plan->check?->id === 'bitrix.db-session-modes' && $planned->plan->check->title === 'Bitrix MySQL session modes' && $planned->plan->check->state === 'needed'
    && $planned->plan->check->detail === 'after_connect_d7.php: partial' && str_starts_with((string) $planned->plan->check->plan, 'Append sql_mode'), 'The fix is planned, the plan naming the rule: '.(string) $planned->reason);
env_assert($operations->start($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.db-session-modes'])->jobId === '000000000000000000000000', 'Confirmed, it starts a job.');
$finished = PanelJobStatus::fromData(json_decode(env_recorded_bytes('job-site-check-fix-status.json'), true, 64, JSON_THROW_ON_ERROR)['data']);
env_assert($finished->command === 'site:check-fix' && $finished->state === JobState::Succeeded && $finished->args === ['domain' => $bitrix, 'id' => 'bitrix.db-session-modes'], 'The job\'s state reads.');
$already = $operations->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.timezone']);
env_assert($already->plan === null && $already->reason === 'check_ok' && $already->refused, 'A rule that passes is already fixed.');
$unknown = $operations->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.no-such']);
env_assert($unknown->plan === null && $unknown->reason === 'unknown_check' && $unknown->refused && $operations->start($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.no-such'])->reason === 'unknown_check',
    'A rule the site has not is refused, planned or confirmed.');
env_assert($operations->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.db-charset'])->reason === PanelReason::RECORDING_MISSING, 'A plan the set does not hold is missing, never another rule\'s.');
env_assert($operations->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'not an id'])->reason === PanelReason::INVALID_ARGUMENT
    && $operations->plan($admin, SiteOperation::CheckFix, $bitrix)->reason === PanelReason::INVALID_ARGUMENT, 'A fix without a rule never leaves.');
env_assert($operations->plan($owner, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.db-session-modes'])->reason === 'owner_scope', 'An owner fixes their own sites only.');
env_assert(PlanValues::of($planned->plan->args, $bitrix, ['id']) === [] && array_column(PlanValues::of($planned->plan->args, $bitrix), 'name') === ['id'], 'The plan says the rule by its title, not by its id.');

// The site's check is kept like its other reads; «already fixed» means the kept check is out of date, so it is read anew.
$counting = new class ($recorded) implements PanelGateway {
    public int $asked = 0;

    public function __construct(private RecordedAnswersGateway $inner)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked += $request->command === 'site:doctor' ? 1 : 0;

        return $this->inner->ask($request);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
$caching = new CachingPanelGateway($counting, new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
(new SiteDetailsReader($caching))->doctor($admin, $bitrix);
(new SiteDetailsReader($caching))->doctor($admin, $bitrix);
env_assert($counting->asked === 1, 'Read twice, asked once.');
env_assert((new PanelOperations($caching))->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.db-session-modes'])->plan !== null, 'A plan runs nothing.');
(new SiteDetailsReader($caching))->doctor($admin, $bitrix);
env_assert($counting->asked === 1, 'A plan leaves the kept check.');
env_assert((new PanelOperations($caching))->plan($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.timezone'])->reason === 'check_ok', 'Already fixed.');
(new SiteDetailsReader($caching))->doctor($admin, $bitrix);
env_assert($counting->asked === 2, 'After «already fixed» the check is read anew.');
env_assert((new PanelOperations($caching))->start($admin, SiteOperation::CheckFix, $bitrix, ['id' => 'bitrix.db-session-modes'])->jobId !== null, 'The fix starts.');
(new SiteDetailsReader($caching))->doctor($admin, $bitrix);
env_assert($counting->asked === 3, 'After the job too.');

// The block on stubs of the admin's layout, through the controller. The set holds the check of demo-bitrix.test but not
// its description: the site is described as the recorded PHP site, under its own domain and kind.
$files = new Filesystem();
$resolver = new EngineResolver();
$resolver->register('blade', static fn () => new CompilerEngine(new BladeCompiler($files, env_temp_directory()), $files));
$factory = new Factory($resolver, new FileViewFinder($files, []), new Dispatcher());
$factory->addExtension('blade.php', 'blade');
$factory->addNamespace('larena-admin', dirname(__DIR__).'/Support/admin-views');
$factory->addNamespace('larena-env', dirname(__DIR__, 2).'/resources/views');
/** @param list<string> $lists the commands the server lists besides the recorded set's; null leaves the set's own server (simai-env 1.49.0) */
$serving = static fn (?array $lists): PanelGateway => new class ($recorded, $lists) implements PanelGateway {
    /** @param list<string>|null $lists */
    public function __construct(private RecordedAnswersGateway $inner, private ?array $lists)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        if ($request->command === 'site:describe' && ($request->args['domain'] ?? null) === 'demo-bitrix.test' && $request->actor->owner === null) {
            $document = json_decode(env_recorded_bytes('site-describe.demo-php.test.json'), true, 64, JSON_THROW_ON_ERROR);
            $document['data']['domain'] = 'demo-bitrix.test';
            $document['data']['profile'] = 'bitrix';

            return PanelAnswer::fromDocument($request->requestId, $document);
        }
        if ($request->command !== 'self:describe' || $this->lists === null || $request->actor->owner !== null) {
            return $this->inner->ask($request);
        }
        $document = json_decode(env_recorded_bytes('self-describe.json'), true, 64, JSON_THROW_ON_ERROR);
        $document['data']['simai_env']['version'] = '1.55.1';
        $document['data']['gate']['commands'] = array_merge($document['data']['gate']['commands'], $this->lists);

        return PanelAnswer::fromDocument($request->requestId, $document);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
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
$controller = static fn (PanelGateway $gateway, ?array $words = null): SiteAdminController => new SiteAdminController(new SiteDetailsReader($gateway), new ServerOverviewReader($gateway),
    new SiteExtrasReader($gateway), new PanelOperations($gateway), $factory, $translating($words ?? $lines));
$asking = static function (string $uri, string $method = 'GET', array $input = [], ?PanelActor $as = null): Request {
    $request = Request::create($uri, $method, $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, $as ?? new PanelActor('admin@stand.test'));
    $request->setLaravelSession(new Store('panel', new ArraySessionHandler(10)));

    return $request;
};
$block = static function (string $page): string {
    env_assert(preg_match('#<section[^>]*data-env-doctor=.*?</section>#s', $page, $found) === 1, 'The page has the check: '.$page);

    return $found[0];
};

$current = $controller($serving(['site:check-state', 'site:check-fix']));
$capabilities = (new ServerOverviewReader($serving(['site:check-fix'])))->read($admin)->overview?->capabilities;
env_assert($capabilities instanceof PanelCapabilities && $capabilities->allows('site:check-fix') && $capabilities->takes('site:check-fix', 'id'), 'A server listing the fix has it.');
$page = (string) $current->show($asking("/admin/server/sites/{$bitrix}"), $bitrix)->render();
$checks = $block($page);
env_assert(str_contains($checks, 'data-env-doctor="FAIL"') && str_contains($checks, '17 need attention; 39 passed.') && substr_count($checks, 'data-env-problem="') === 17
    && substr_count($checks, 'data-env-problem="FAIL"') === 13 && substr_count($checks, 'data-env-problem="WARN"') === 4, 'One block, one list: every check that did not pass, counted as before.');
env_assert(preg_match_all('/data-env-problem="[A-Z]+" data-env-rule="([^"]+)"/', $checks, $shown) === 5
    && $shown[1] === ['bitrix.ownership', 'bitrix.docroot-leftovers', 'bitrix.db-session-modes', 'bitrix.db-charset', 'php.fpm-graceful-reload'], 'The profile\'s rules stand among them.');
env_assert(preg_match_all('/data-env-check-fix="([^"]+)"/', $checks, $fixes) === 4
    && $fixes[1] === ['bitrix.docroot-leftovers', 'bitrix.db-session-modes', 'bitrix.db-charset', 'php.fpm-graceful-reload'], 'A fix where the server offers one, and only there.');
env_assert(preg_match('#<li [^>]*data-env-problem="WARN" data-env-rule="bitrix\.db-charset">\s*<sf-badge appearance="tonal" status="warning" text="Warning"></sf-badge>\s*'
    .'<span class="grow min-w-0" style="overflow-wrap: anywhere;" data-env-check="Bitrix database default charset">Default charset of the database'
    .'<span class="text-small color-on-surface-variant" style="display: block;" data-env-check-detail>utf8mb3/utf8mb3_general_ci \(expected utf8mb4/utf8mb4_unicode_ci\) · since 01\.01\.2026 00:00 UTC</span></span>\s*'
    .'<span class="text-small color-on-surface-variant">profile rules</span>\s*'
    .'<form method="post" action="/admin/server/larena\.env\.admin\.site\.plan/demo-bitrix\.test" class="m-0" data-env-check-fix="bitrix\.db-charset">\s*<input type="hidden" name="_token" value="test">\s*'
    .'<input type="hidden" name="operation" value="check-fix">\s*<input type="hidden" name="id" value="bitrix\.db-charset">\s*'
    .'<button type="submit" class="larena-button larena-control-floor">Fix…</button>\s*</form>\s*</li>#', $checks) === 1, 'A rule: its state, title, what the server found, since when, and the fix through the plan: '.$checks);
env_assert(preg_match('#data-env-rule="bitrix\.ownership">.*?</li>#s', $checks, $ownership) === 1 && !str_contains($ownership[0], '<form') && str_contains($ownership[0], '1 item(s) under')
    && str_contains($ownership[0], 'since 01.01.2026'), 'A rule without a fix says what is wrong and offers nothing.');
env_assert(preg_match('#<li [^>]*data-env-problem="FAIL">\s*<sf-badge appearance="tonal" status="error" text="Problem"></sf-badge>\s*<span class="grow min-w-0" style="overflow-wrap: anywhere;" data-env-check="Writable path">'
    .'Writable path</span>\s*<span class="text-small color-on-surface-variant">files</span>\s*</li>#', $checks) === 1, 'A check that is not a rule is one line as before.');
env_assert(preg_match('#<details class="m-t-1" data-env-rules-passed>\s*<summary[^>]*>Profile rules that pass: 7</summary>(.*?)</details>#s', $checks, $passedList) === 1
    && substr_count($passedList[1], 'data-env-rule-state="PASS"') === 7 && str_contains($passedList[1], 'data-env-rule="bitrix.timezone"') && str_contains($passedList[1], '>UTC</span>')
    && !str_contains($passedList[1], '<form') && strpos($checks, 'data-env-rules-passed') > strrpos($checks, 'data-env-problem='), 'The rules that pass are counted, their list under the problems.');
env_assert(!str_contains($page, 'data-env-operation-open="check-fix"') && !str_contains($page, 'data-env-operation="check-fix"'), 'The fix is not repeated among the tab\'s buttons.');
env_assert(!str_contains($checks, 'check_ok') && !str_contains($checks, 'unknown_check') && preg_match('/<style\b|<script\b|\bsize=/i', $checks) !== 1, 'No codes, no script, no sizes.');

// A server that names the rules but does not list the fix: the rules are said, nothing is offered.
$unlisted = $block((string) $controller($serving([]))->show($asking("/admin/server/sites/{$bitrix}"), $bitrix)->render());
env_assert(!str_contains($unlisted, 'data-env-check-fix') && !str_contains($unlisted, '<form') && substr_count($unlisted, 'data-env-rule="') === 12 && str_contains($unlisted, 'data-env-check-detail'),
    'Without site:check-fix no fix is offered.');
$refusedOlder = $controller($serving([]))->plan($asking("/admin/server/sites/{$bitrix}/plan", 'POST', ['operation' => 'check-fix', 'id' => 'bitrix.db-session-modes']), $bitrix);
env_assert($refusedOlder instanceof RedirectResponse && $refusedOlder->getTargetUrl() === "/admin/server/larena.env.admin.site/{$bitrix}?tab=overview", 'And a form left from another page is not sent.');

// An older server (the recorded set's own: no profile, no rules, no site:check-fix): the block exactly as it was.
$older = $block((string) $controller($serving(null))->show($asking('/admin/server/sites/demo-php.test'), 'demo-php.test')->render());
env_assert(preg_match('#\A<section class="larena-panel larena-panel-padded" data-env-doctor="WARN" aria-labelledby="env-site-doctor">\s*<h2 id="env-site-doctor" class="text-2">Check</h2>\s*'
    .'<p>1 need attention; 21 passed\.</p>\s*<ul class="m-0 p-0" style="list-style: none;">\s*'
    .'<li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-problem="WARN">\s*'
    .'<sf-badge appearance="tonal" status="warning" text="Warning"></sf-badge>\s*'
    .'<span class="grow min-w-0" style="overflow-wrap: anywhere;" data-env-check="Recommended extensions">Recommended extensions</span>\s*'
    .'<span class="text-small color-on-surface-variant">PHP</span>\s*</li>\s*</ul>\s*</section>\z#', $older) === 1, 'An older server: the check as before, nothing added: '.$older);
$clean = $block((string) $controller($serving(null))->show($asking('/admin/server/sites/demo-static.test'), 'demo-static.test')->render());
env_assert(!str_contains($clean, 'data-env-rule') && !str_contains($clean, '<details') && !str_contains($clean, '<form'), 'Nor on a site whose check found nothing.');

// «Исправить…» opens the server's plan, which names the rule in words; the confirmation starts the job.
$planRequest = $asking("/admin/server/sites/{$bitrix}/plan", 'POST', ['operation' => 'check-fix', 'id' => 'bitrix.db-session-modes']);
$planView = $current->plan($planRequest, $bitrix);
env_assert($planView instanceof \Illuminate\View\View && ($planView->getData()['hidden'] ?? null) === ['operation' => 'check-fix', 'id' => 'bitrix.db-session-modes']
    && $planView->getData()['plan']->command === 'site:check-fix', 'The button opens the server\'s plan, the confirmation carrying the same rule.');
$planPage = (string) $planView->render();
env_assert(str_contains($planPage, 'data-env-plan="site:check-fix"') && str_contains($planPage, 'data-env-plan-check="bitrix.db-session-modes">MySQL modes for Bitrix</h3>')
    && str_contains($planPage, 'data-env-plan-check-detail>Now: after_connect_d7.php: partial</p>') && str_contains($planPage, 'data-env-plan-check-plan>Append sql_mode=')
    && !str_contains($planPage, 'data-env-plan-argument="id"') && str_contains($planPage, 'name="id" value="bitrix.db-session-modes"') && str_contains($planPage, 'data-env-confirm="site:check-fix"')
    && str_contains($planPage, '<h1>Fix a check finding</h1>'), 'The plan says which rule, what the server found and what it would do: '.$planPage);
$ran = $current->run($asking("/admin/server/sites/{$bitrix}/run", 'POST', ['operation' => 'check-fix', 'id' => 'bitrix.db-session-modes']), $bitrix);
env_assert(str_contains($ran->getTargetUrl(), 'larena.env.admin.job/000000000000000000000000'), 'Confirmed, the job\'s screen follows.');

// A refusal is said in words on the overview, never as its code: in Russian as the owner reads it.
$inRussian = $controller($serving(['site:check-fix']), $russian);
foreach (['bitrix.timezone' => 'Уже исправлено.', 'bitrix.no-such' => 'Такой проверки у сайта нет.'] as $id => $words) {
    foreach (['plan', 'run'] as $step) {
        $request = $asking("/admin/server/sites/{$bitrix}/{$step}", 'POST', ['operation' => 'check-fix', 'id' => $id]);
        $answer = $inRussian->{$step}($request, $bitrix);
        env_assert($answer instanceof RedirectResponse && $answer->getTargetUrl() === "/admin/server/larena.env.admin.site/{$bitrix}?tab=overview"
            && $request->session()->get('larena_env_notice') === $words, "{$id} on {$step}: «{$words}», got ".(string) $request->session()->get('larena_env_notice'));
    }
}
$said = $asking("/admin/server/sites/{$bitrix}");
$said->session()->put('larena_env_notice', $text('reasons.check_ok'));
$saidPage = (string) $current->show($said, $bitrix)->render();
env_assert(str_contains($saidPage, 'data-env-notice') && str_contains($saidPage, 'Already fixed.') && !str_contains($saidPage, 'check_ok'), 'The page says it in words.');

// Every text of the block in Russian as in English, with the words the owner reads; the views print nothing raw.
$keys = static function (array $lines, string $prefix = '') use (&$keys): array {
    $all = [];
    foreach ($lines as $key => $value) {
        $all = array_merge($all, is_array($value) ? $keys($value, $prefix.$key.'.') : [$prefix.$key]);
    }

    return $all;
};
env_assert($keys($russian['doctor']) === $keys($lines['doctor']), 'The check\'s texts in both languages.');
env_assert($russian['doctor']['fix'] === 'Исправить…' && $russian['doctor']['since'] === 'с :time' && str_contains($russian['doctor']['summary'], 'пройдено: :passed')
    && $russian['reasons']['check_ok'] === 'Уже исправлено.' && $russian['reasons']['unknown_check'] === 'Такой проверки у сайта нет.'
    && is_string($russian['operations']['check-fix'] ?? null) && is_string($lines['operations']['check-fix'] ?? null), 'The owner\'s words.');
foreach (array_merge($doctor->problems, $doctor->passedRules) as $check) {
    if ($check['key'] !== null) {
        $slug = str_replace(['.', '-'], '_', $check['key']);
        env_assert(is_string($russian['doctor']['rules'][$slug] ?? null) && is_string($lines['doctor']['rules'][$slug] ?? null), "The rule {$check['key']} has words.");
    }
}
foreach (['site.blade.php', 'plan.blade.php'] as $view) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);
    env_assert(!str_contains($source, '{!!') && preg_match('/<style\b|<script\b|@php\b/i', $source) !== 1, "{$view} prints nothing raw and holds no script.");
}

echo "SiteChecksTest passed.\n";
