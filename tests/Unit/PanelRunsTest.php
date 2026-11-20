<?php

declare(strict_types=1);

// The panel on simai-env 1.59.0–1.61.0, on a stand-in of the gateway: a plan in words (title, display, effects_words,
// consequence, checks: what stops it apart from what is worth knowing, a blocker turning the button off); a run's steps,
// where it stands and what stays done when it is stopped; «История» paged by `before`, with how long a run took, who
// started it and how it ended; the count of running jobs for the menu; a refusal named by the server; «Базы данных»
// from db:list; the database password handed out once and never kept by the panel.

use Illuminate\Http\Request;
use Larena\Env\Admin\EnvHistorySource;
use Larena\Env\Admin\JobOutcome;
use Larena\Env\Admin\PlanAction;
use Larena\Env\Admin\ServerDatabasesPage;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelLanguage;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Operations\PanelPlan;
use Larena\Env\Operations\SiteOperation;
use Larena\Env\Server\SiteDetails;
use Larena\Env\Server\SiteExtrasReader;

require_once dirname(__DIR__).'/Support/env-test.php';

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';

/** @param array<string, string> $replace */
function __(string $key, array $replace = []): string
{
    global $lines;
    $line = str_starts_with($key, 'larena-env::admin.') ? data_get($lines, substr($key, strlen('larena-env::admin.'))) : null;
    $line = is_string($line) ? $line : $key;
    foreach ($replace as $name => $value) {
        $line = str_replace(':'.$name, (string) $value, $line);
    }

    return $line;
}

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/admin/server/'.$name.($parameters !== [] ? '/'.implode('/', $parameters) : '');
}

$password = 'stand-in-'.bin2hex(random_bytes(6));

/** The gateway as simai-env 1.61.0 answers the commands of this test. */
$server = new class($password) implements PanelGateway {
    /** @var list<PanelRequest> */
    private array $asked = [];
    public int $credentialsAsked = 0;
    /** @var list<array<string, mixed>> the jobs of job:list, newest first */
    public array $jobs = [];

    public function __construct(private string $password)
    {
        for ($i = 0; $i < 130; $i++) {
            $this->jobs[] = ['job_id' => sprintf('%024x', 1000 - $i), 'state' => $i === 0 ? 'running' : ($i === 1 ? 'failed' : 'succeeded'),
                'command' => 'backup:data', 'args' => ['domain' => 'shop.example.com'], 'created_at' => '2026-11-18T10:00:00Z',
                'started_at' => '2026-11-18T10:00:00Z', 'finished_at' => $i === 0 ? null : '2026-11-18T10:03:40Z',
                'title' => 'Backup of shop.example.com', 'object' => ['kind' => 'site', 'id' => 'shop.example.com'],
                'actor' => $i === 2 ? ['kind' => 'schedule'] : ['kind' => 'person', 'user' => 'admin@example.com'],
                'steps' => $i === 0 ? [['id' => 1, 'title' => 'Dump the database', 'state' => 'done'], ['id' => 2, 'title' => 'Pack the files', 'state' => 'running'],
                    ['id' => 3, 'state' => 'waiting']] : [],
                'progress' => $i === 0 ? ['current' => 2, 'total' => 3, 'title' => 'Pack the files'] : null,
                'outcome' => $i === 1 ? ['code' => 'failed', 'words' => 'The disk is full: the backup stopped.'] : null];
        }
    }

    public function source(): string
    {
        return 'stand-in';
    }

    /** @return list<PanelRequest> what the panel asked since the last forget() */
    public function asked(): array
    {
        return $this->asked;
    }

    public function forget(): void
    {
        $this->asked = [];
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked[] = $request;
        $ok = static fn (string $kind, array $data, ?string $job = null): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => $kind, 'data' => $data] + ($job !== null ? ['job_id' => $job] : []));
        $refused = static fn (string $reason, array $data = []): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'refused', 'reason' => $reason, 'data' => $data]);
        $domain = $request->args['domain'] ?? '';
        switch ($request->command) {
            case 'job:list':
                $jobs = $this->jobs;
                if (($request->args['state'] ?? null) !== null) {
                    $jobs = array_values(array_filter($jobs, static fn (array $job): bool => $job['state'] === $request->args['state']));
                }
                $before = $request->args['before'] ?? null;
                if ($before !== null) {
                    $jobs = array_values(array_filter($jobs, static fn (array $job): bool => strcmp($job['job_id'], $before) < 0));
                }
                $limit = (int) ($request->args['limit'] ?? 100);
                $page = array_slice($jobs, 0, $limit);

                return $ok('result', ['jobs' => $page, 'more' => count($jobs) > $limit, 'next_before' => count($jobs) > $limit ? end($page)['job_id'] : null,
                    'counts' => ['running' => 1, 'failed_since' => 1]]);
            case 'site:db-credentials':
                if (!$request->confirm) {
                    return $ok('plan', ['command' => 'site:db-credentials', 'args' => $request->args, 'target' => $domain, 'description' => 'Show the database connection details',
                        'effects' => 'read', 'lock' => 'none', 'applied' => false, 'title' => 'Connection details of '.$domain]);
                }

                return $ok('job', [], 'd00000000000000000000001');
            case 'site:db-create':
                // A kind of site without a database (db=no): the plan refuses, the checks say why.
                return $refused('plan_failed', ['checks' => ['blockers' => ['A static site has no database: its profile says db=no.'], 'notes' => ['The site keeps working.']]]);
            case 'site:db-browser':
                return $refused('no_https', ['detail' => 'the site has no certificate', 'checks' => ['blockers' => [], 'notes' => []]]);
            case 'job:status':
                $result = ['domain' => 'shop.example.com', 'engine' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'shop', 'user' => 'shop'];
                // ONCE_RESULT: the first answer after the job hands the password out; later ones name it in shown_once.
                $result += $this->credentialsAsked++ === 0 ? ['db_password' => $this->password] : ['shown_once' => ['db_password']];

                return $ok('result', ['job_id' => $request->args['job_id'], 'state' => 'succeeded', 'exit_code' => 0, 'command' => 'site:db-credentials',
                    'args' => ['domain' => 'shop.example.com'], 'created_at' => '2026-11-18T12:00:00Z', 'started_at' => '2026-11-18T12:00:00Z',
                    'finished_at' => '2026-11-18T12:00:02Z', 'result' => $result]);
            case 'job:log':
                return $ok('result', ['job_id' => $request->args['job_id'], 'lines' => ['Reading the connection details of shop.example.com', 'Done'], 'next' => 2, 'done' => true]);
            case 'db:list':
                return $ok('result', ['schema' => 'simai-databases/1', 'databases' => [
                    ['name' => 'shop', 'engine' => 'mysql', 'site' => 'shop.example.com', 'size_bytes' => 2048, 'users' => ['shop']],
                    ['name' => 'blog', 'engine' => 'postgresql', 'site' => 'blog.example.com', 'size_bytes' => 1048576, 'users' => ['blog']],
                    ['name' => 'left_over', 'engine' => 'mysql', 'site' => null, 'size_bytes' => 0, 'users' => []],
                ]]);
        }

        return PanelAnswer::refused($request->requestId, 'not_allowed');
    }
};
$admin = new PanelActor('admin@example.com');
$operations = new PanelOperations($server);

// The request says the panel's language (simai-env 1.60.0 `lang`): the server answers its words in it.
PanelLanguage::use('ru');
env_assert(PanelRequest::read($admin, 'job:list')->toDocument()['lang'] === 'ru', 'The server is asked in the panel\'s language.');
PanelLanguage::use(null);

// A. A plan in words: its title, each value as the server says it, what it changes, what follows; what stops it apart from
// what is worth knowing, and a blocker turns the button off with its reason above it.
$plan = PanelPlan::fromData(['command' => 'site:db-rotate', 'args' => ['domain' => 'shop.example.com'], 'target' => 'shop.example.com', 'description' => 'Rotate the password',
    'effects' => 'write', 'lock' => 'site', 'applied' => false, 'title' => 'New database password for shop.example.com',
    'display' => [['key' => 'domain', 'label' => 'Site', 'value' => 'shop.example.com', 'words' => 'shop.example.com']],
    'effects_words' => 'changes the password in the site\'s settings', 'consequence' => 'The old password stops working at once.',
    'checks' => ['blockers' => ['wp-config.php holds another password.'], 'notes' => ['The site restarts its workers.']]]);
env_assert($plan->title === 'New database password for shop.example.com' && $plan->effectsWords === 'changes the password in the site\'s settings'
    && $plan->consequence === 'The old password stops working at once.' && $plan->checks?->blockers === ['wp-config.php holds another password.']
    && $plan->said('domain')['label'] === 'Site', 'The plan\'s words are read.');
$part = (string) PlanAction::part(['plan' => $plan, 'runUrl' => '/run', 'heading' => '', 'cancelUrl' => '/cancel'], 'token');
env_assert(str_contains($part, 'data-larena-action-warnings="blocking"') && str_contains($part, 'wp-config.php holds another password.')
    && str_contains($part, 'data-larena-action-warnings="known"') && str_contains($part, 'The site restarts its workers.')
    && strpos($part, 'wp-config.php') < strpos($part, 'The site restarts'), 'What stops it, then what is worth knowing, each under its own heading.');
env_assert(str_contains($part, 'New database password for shop.example.com') && str_contains($part, 'The old password stops working at once.')
    && preg_match('/\bdisabled\b/', $part) === 1, 'The title and the consequence in the server\'s words; the button off.');
// The effects in the server's words where the panel has none of its own for them.
$otherEffects = (string) PlanAction::part(['plan' => PanelPlan::fromData(['command' => 'site:db-credentials', 'args' => ['domain' => 'shop.example.com'], 'description' => 'd',
    'effects' => 'secret-read', 'applied' => false, 'effects_words' => 'reads the password once']), 'runUrl' => '/run', 'heading' => 'Connection details', 'cancelUrl' => '/cancel'], 'token');
env_assert(str_contains($otherEffects, 'reads the password once') && preg_match('/\bdisabled\b/', $otherEffects) !== 1, 'Effects in words; nothing stops it, the button on.');

// A refused plan (1.60.0 checks): the reasons in words, the button off. «Создать базу» for a site whose kind has none.
$refused = $operations->plan($admin, SiteOperation::DatabaseCreate, 'static.example.com');
env_assert($refused->plan === null && $refused->said() && $refused->checks?->blockers === ['A static site has no database: its profile says db=no.']
    && $refused->checks->notes === ['The site keeps working.'], 'The server says why it will not make a database.');
$window = (string) PlanAction::refused('Create a database', $refused->checks->blockers, $refused->checks->notes, '/run', 'token', '/cancel', 'refused-database-create');
env_assert(str_contains($window, 'A static site has no database') && str_contains($window, 'data-larena-action-warnings="blocking"') && preg_match('/\bdisabled\b/', $window) === 1,
    'The refusal in the same window, the button off.');
// The panel's one sentence above; the server's own words (a console command among them) only under «Подробнее»
// (FASTPANEL comparison 2026-11-18).
$said = (string) PlanAction::refused('Open the database', ['The database opens over HTTPS only.'], [], '/run', 'token', '/cancel', 'refused-database-browse', '',
    'shop.example.com has no HTTPS certificate (ssl letsencrypt --domain shop.example.com)');
env_assert(substr_count($said, 'data-larena-action-warning="blocking"') === 1 && str_contains($said, 'data-larena-action-more')
    && strpos($said, 'ssl letsencrypt') > strpos($said, 'data-larena-action-more'), 'The server\'s words under «Подробнее», one sentence above.');
// E. A refusal named by the server (1.61.0): the reason itself, and its detail, kept for the panel's words.
$browser = $operations->plan($admin, SiteOperation::DatabaseBrowse, 'shop.example.com', ['minutes' => '15']);
env_assert($browser->plan === null && $browser->reason === 'no_https' && $browser->detail === 'the site has no certificate'
    && is_string($lines['db_browser']['refusals']['no_https'] ?? null) && is_string($lines['db_browser']['refusals']['no_driver'] ?? null)
    && is_string($lines['db_browser']['refusals']['engine_unsupported'] ?? null), 'no_https and every named reason have the panel\'s words.');

// A run: its steps in words, where it stands, what stays done when stopped.
$running = PanelJobStatus::fromData($server->jobs[0] + ['cancel' => ['cancellable' => false, 'leaves' => 'The dump stays on the server.']]);
env_assert($running->state === JobState::Running && count($running->steps) === 3 && $running->steps[2]['title'] === null && !$running->cancellable()
    && $running->cancel['leaves'] === 'The dump stays on the server.', 'The steps, a step not reached yet, not cancellable with what stays.');
env_assert(JobOutcome::where($running) === '2 of 3: Pack the files' && JobOutcome::steps($running)[2]['title'] === 'Step 3', 'Where it stands: «2 из 3: …»; a step by its number.');
$progress = (string) JobOutcome::progress($running);
env_assert(str_contains($progress, 'sf-progress-run') && str_contains($progress, '2 of 3: Pack the files') && str_contains($progress, 'Dump the database'), 'The steps go in sf-progress-run.');
$failed = PanelJobStatus::fromData($server->jobs[1]);
env_assert($failed->seconds() === 220 && str_contains((string) JobOutcome::progress($failed), 'The disk is full'), 'How long it took; the failure in the server\'s words.');
env_assert(PanelJobStatus::fromData($server->jobs[0] + ['cancel' => ['cancellable' => true, 'leaves' => null]])->cancellable(), 'Cancellable where the server says so.');

// B. «История»: paged by `before`, who started it (a person, the schedule), how long, how it ended; the menu's count.
$cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());
$translator = new class implements \Illuminate\Contracts\Translation\Translator {
    public function get($key, array $replace = [], $locale = null)
    {
        return __($key, $replace);
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return __($key, $replace);
    }

    public function getLocale()
    {
        return 'en';
    }

    public function setLocale($locale)
    {
    }
};
// The person is known to the request already (ResolvePanelActor): the resolver is not asked.
$actors = (new ReflectionClass(\Larena\Env\Http\Support\PanelActorResolver::class))->newInstanceWithoutConstructor();
$history = new EnvHistorySource($actors, $operations, $translator, null, $cache);
$request = Request::create('/admin/history');
$request->attributes->set(ResolvePanelActor::ATTRIBUTE, $admin);
$server->forget();
$entries = $history->entries($request, 120);
$lists = array_values(array_filter($server->asked(), static fn (PanelRequest $asked): bool => $asked->command === 'job:list'));
env_assert(count($entries) === 120 && count($lists) === 2 && ($lists[1]->args['before'] ?? null) === sprintf('%024x', 1000 - 99), 'Past the first 100 the server is asked for those before («Ещё»).');
env_assert($entries[0]->running() && $entries[0]->steps !== [] && $entries[0]->note === '2 of 3: Pack the files', 'A running job with its steps and where it stands.');
env_assert($entries[1]->outcome === 'failed' && $entries[1]->note === 'The disk is full: the backup stopped.' && $entries[1]->seconds === 220
    && $entries[1]->actor === 'You' && $entries[1]->title !== '', 'Done: how it ended in words, how long, who.');
env_assert($entries[2]->actor === 'On schedule' && $entries[2]->actorKey === 'schedule', 'A run of the schedule says so.');
$server->forget();
$counts = [$history->running($request), $history->running($request)];
env_assert($counts === [1, 1] && count($server->asked()) === 0, 'The menu\'s count of running jobs is kept a little while, not asked on every page.');
$cache->flush();
env_assert($history->running($request) === 1 && count($server->asked()) === 1 && ($server->asked()[0]->args['state'] ?? null) === 'running', 'Asked once, for the running ones.');
// The indicator of background actions (owner 2026-11-18): one cheap job:list with since and limit, its counts; the latest
// actions; the failures not seen yet; kept a few seconds; the menu's running count taken from the same read.
$cache->flush();
$server->forget();
$activity = $history->activity($request, null, 8);
$asked = $server->asked();
env_assert($activity !== null && count($asked) === 1 && $asked[0]->command === 'job:list' && ($asked[0]->args['limit'] ?? null) === '8'
    && isset($asked[0]->args['since']) && !isset($asked[0]->args['state']), 'One job:list, with since and limit.');
env_assert($activity->running === 1 && $activity->unseenFailed === 1 && count($activity->entries) === 8 && array_values($activity->entries)[0]->running(),
    'What runs, the failure not seen, the latest actions.');
$server->forget();
env_assert($history->activity($request, null, 8)?->running === 1 && $history->running($request) === 1 && $server->asked() === [],
    'Asked again within seconds: kept; the menu\'s count comes from the same read.');
$seenNow = gmdate('Y-m-d\TH:i:s\Z', max(time(), (int) strtotime('2026-11-18T10:03:41Z')));
env_assert($history->activity($request, $seenNow, 8)?->unseenFailed === 0, 'Seen after the failure ended: no red dot.');
$seenLong = $history->activity($request, '2026-11-01T00:00:00Z', 8);
$sinceAsked = array_values(array_filter($server->asked(), static fn (PanelRequest $asked): bool => $asked->command === 'job:list'));
env_assert($seenLong?->unseenFailed === 1 && (end($sinceAsked)->args['since'] ?? null) === '2026-11-01T00:00:00Z', 'Not looked for days: asked since then, its failures counted.');

// The list of jobs only looks: it does not forget the panel's kept answers.
$kept = new CachingPanelGateway($server, $cache);
$kept->ask(PanelRequest::read($admin, 'db:list'));
$server->forget();
$kept->ask(PanelRequest::read($admin, 'job:list'));
$kept->ask(PanelRequest::read($admin, 'db:list'));
env_assert(array_map(static fn (PanelRequest $asked): string => $asked->command, $server->asked()) === ['job:list'], 'job:list keeps the other answers.');

// F. «Базы данных»: the sites' databases from db:list, each row opening its site's «База данных».
$databases = (new SiteExtrasReader($server))->allDatabases($admin);
$page = ServerDatabasesPage::of($databases ?? [], static fn (string $key, array $replace = []): string => __('larena-env::admin.'.$key, $replace));
env_assert(count($databases ?? []) === 3 && $page->total === 2 && array_column($page->rows ?? [], 'id') === ['blog.example.com', 'shop.example.com']
    && ($page->rows[1]['size'] ?? null) === '2.0 KB', 'Each site\'s database, by site; one no site owns is not listed.');
env_assert(str_contains(json_encode($page->rowLinks, JSON_UNESCAPED_SLASHES) ?: '', '/admin/server/sites/__ID__?tab=database'), 'A row opens the site\'s «База данных».');

// The database's own actions: one database per site; its password changed and its details shown wherever it has one.
$site = static fn (array $over = []): SiteDetails => SiteDetails::fromDescription($over + ['schema' => 'simai-site/1', 'domain' => 'shop.example.com', 'profile' => 'wordpress',
    'php' => '8.3', 'ssl' => 'letsencrypt', 'database' => ['engine' => 'mysql']]);
env_assert(SiteOperation::DatabaseCredentials->command() === 'site:db-credentials' && SiteOperation::DatabaseCredentials->tab() === 'database'
    && SiteOperation::DatabaseCredentials->asksNothing() && PanelCommands::kind('site:db-credentials') === PanelCommands::ACTION, 'The connection details: an action of the database tab, nothing to fill.');
env_assert(SiteOperation::DatabasePassword->suitsSite($site()) && SiteOperation::DatabaseCredentials->suitsSite($site()) && !SiteOperation::DatabaseCreate->suitsSite($site())
    && SiteOperation::DatabaseCreate->suitsSite($site(['database' => null, 'php' => 'none'])) && !SiteOperation::DatabaseCredentials->suitsSite($site(['database' => null])),
    'A WordPress site changes its password; a second database is never offered; a site without one is offered one.');
env_assert(SiteOperation::inRow([SiteOperation::DatabaseCredentials, SiteOperation::DatabaseCreate, SiteOperation::DatabasePassword, SiteOperation::DatabaseExport]) === [SiteOperation::DatabaseExport],
    'The block offers its own actions, the row the rest.');
env_assert(JobOutcome::next(PanelJobStatus::fromData(['job_id' => 'j', 'state' => 'succeeded', 'command' => 'site:db-rotate', 'args' => ['domain' => 'shop.example.com']]))[0]
    === '/admin/server/larena.env.admin.site/shop.example.com?tab=database&action=database-credentials', 'After a new password: «Показать данные».');
// A text saved over its previous version (simai-env 1.64.0): the end says the previous version is in the site's trash and
// opens it; an unpacking that replaced nothing says plainly «Готово».
$saved = PanelJobStatus::fromData(['job_id' => 'j', 'state' => 'succeeded', 'command' => 'file:write', 'args' => ['domain' => 'shop.example.com', 'path' => 'a.txt'],
    'result' => ['previous_trash_id' => '20261120T010000Z-abcdef']]);
env_assert($saved->replacedIntoTrash === 1 && JobOutcome::heading($saved) === $lines['job']['done']['file-replaced']
    && JobOutcome::next($saved) === ['/admin/server/larena.env.admin.site/shop.example.com?tab=files&trash=1', $lines['job']['next']['trash']], 'After a save: the previous version is in the trash.');
$unpacked = PanelJobStatus::fromData(['job_id' => 'j', 'state' => 'succeeded', 'command' => 'file:extract', 'args' => ['domain' => 'shop.example.com'],
    'result' => ['trashed' => 0, 'trash_ids' => []]]);
env_assert($unpacked->replacedIntoTrash === 0 && JobOutcome::heading($unpacked) === $lines['job']['done']['other'], 'Nothing replaced: plainly done.');
env_assert(PanelJobStatus::fromData(['job_id' => 'j', 'state' => 'succeeded', 'command' => 'file:upload', 'result' => ['trashed' => 2]])->replacedIntoTrash === 2, 'Counted by trashed too.');

// The password once: the first job:status hands it out, the panel keeps it nowhere, a later answer says it was shown.
$cached = new PanelOperations(new CachingPanelGateway($server, $cache));
$started = $cached->start($admin, SiteOperation::DatabaseCredentials, 'shop.example.com');
$first = $cached->view($admin, (string) $started->jobId);
env_assert($first->status?->credentials?->password === $password && $first->status->credentials->database === 'shop' && $first->status->credentials->port === '3306',
    'The first answer after the job: the details and the password.');
env_assert(!str_contains(print_r($first->status->credentials, true), $password) && !str_contains(var_export($first->status->secret, true), $password),
    'The password is never printed by accident.');
foreach ($cache->getStore() instanceof \Illuminate\Cache\ArrayStore ? (new ReflectionProperty(\Illuminate\Cache\ArrayStore::class, 'storage'))->getValue($cache->getStore()) : [] as $item) {
    env_assert(!str_contains(serialize($item), $password), 'The panel\'s cache never keeps the password.');
}
$second = $cached->view($admin, (string) $started->jobId);
env_assert($second->status?->credentials === null && in_array('db_password', $second->status->shownOnce, true), 'Asked again: shown once already, the password gone.');

echo "PanelRunsTest passed.\n";
