<?php

declare(strict_types=1);

// The plan's receipt (PlanReceipts, auditor's wave 1 item 1): a run on the server starts only from the plan the person
// saw — the same action, the same values, this person, once, within 15 minutes. Without a receipt, with a value changed
// after the plan, with a used or an old receipt, the confirmation is refused in words and the server is not asked.

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelCommands;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\BackupStorageController;
use Larena\Env\Http\Controllers\FirewallController;
use Larena\Env\Http\Controllers\NewSiteController;
use Larena\Env\Http\Controllers\PanelAddressController;
use Larena\Env\Http\Controllers\PeopleController;
use Larena\Env\Http\Controllers\SecurityController;
use Larena\Env\Http\Controllers\SiteCronController;
use Larena\Env\Http\Controllers\SoftwareController;
use Larena\Env\Http\Controllers\SoftwarePhpController;
use Larena\Env\Http\Controllers\UpdateController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PlanReceipts;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return '/'.$name.($parameters === [] ? '' : '/'.implode('/', array_map('strval', $parameters)));
}

$lines = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$refusal = $lines['reasons']['plan_receipt'];

// 1. The service on its own.
$now = 1_800_000_000;
$receipts = env_plan_receipts($now);
$session = new Store('panel', new ArraySessionHandler(10));
$asking = static function (array $input = [], string $user = 'admin@stand.test', string $signedIn = 'test:system') use ($session): Request {
    $request = Request::create('/admin/server/x', 'POST', $input);
    $request->attributes->set(ResolvePanelActor::ATTRIBUTE, new PanelActor($user));
    $request->attributes->set('larena_access_actor', $signedIn);
    $request->setLaravelSession($session);

    return $request;
};
$values = ['op' => 'block', 'args' => ['address' => '203.0.113.7', 'ttl' => '3600']];
$id = $receipts->issue($asking(), 'firewall:block', $values);
env_assert(preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1, 'A receipt is a random id.');
env_assert(!str_contains(json_encode($session->all(), JSON_THROW_ON_ERROR), '203.0.113.7'), 'The session keeps a keyed hash of the values, not the values.');
env_assert($receipts->confirms($asking(['receipt' => $id]), 'firewall:block', ['args' => ['ttl' => '3600', 'address' => '203.0.113.7'], 'op' => 'block']),
    'The same values in another order are the same plan.');
env_assert(!$receipts->confirms($asking(['receipt' => $id]), 'firewall:block', $values), 'A receipt confirms once.');
$id = $receipts->issue($asking(), 'firewall:block', $values);
env_assert(!$receipts->confirms($asking(['receipt' => $id]), 'firewall:block', ['op' => 'block', 'args' => ['address' => '203.0.113.8', 'ttl' => '3600']]),
    'Another value is refused.');
env_assert(!$receipts->confirms($asking(['receipt' => $id]), 'firewall:block', $values), 'A refused try uses the receipt up too.');
$id = $receipts->issue($asking(), 'firewall:block', $values);
env_assert(!$receipts->confirms($asking(['receipt' => $id]), 'firewall:unblock', $values), 'Another action is refused.');
$id = $receipts->issue($asking(), 'firewall:block', $values);
env_assert(!$receipts->confirms($asking(['receipt' => $id], 'staff@stand.test'), 'firewall:block', $values), 'Another person is refused.');
$id = $receipts->issue($asking(), 'firewall:block', $values);
env_assert(!$receipts->confirms($asking(['receipt' => $id], 'admin@stand.test', 'test:other'), 'firewall:block', $values), 'Another signed-in account is refused.');
$id = $receipts->issue($asking(), 'firewall:block', $values);
$now += 900;
env_assert($receipts->confirms($asking(['receipt' => $id]), 'firewall:block', $values), 'Within 15 minutes it confirms.');
$id = $receipts->issue($asking(), 'firewall:block', $values);
$now += 901;
env_assert(!$receipts->confirms($asking(['receipt' => $id]), 'firewall:block', $values), 'After 15 minutes it is refused.');
env_assert(!$receipts->confirms($asking([]), 'firewall:block', $values) && !$receipts->confirms($asking(['receipt' => 'x']), 'firewall:block', $values)
    && !$receipts->confirms($asking(['receipt' => ['a']]), 'firewall:block', $values), 'No receipt, or none of its form, is refused.');
$kept = $receipts->issue($asking(), 'dns:change', [], ['changes' => [['a' => 1]], 'site' => null]);
env_assert($receipts->take($asking(['plan' => $kept]), 'mail:settings', 'plan') === null, 'What waits in a receipt is given to its own action only.');
$kept = $receipts->issue($asking(), 'dns:change', [], ['changes' => [['a' => 1]], 'site' => null]);
env_assert($receipts->take($asking(['plan' => $kept]), 'dns:change', 'plan') === ['changes' => [['a' => 1]], 'site' => null]
    && $receipts->take($asking(['plan' => $kept]), 'dns:change', 'plan') === null, 'What waits in a receipt is given once.');
for ($i = 0; $i < 12; $i++) {
    $ids[] = $receipts->issue($asking(), 'self:alerts-test');
}
env_assert(!$receipts->confirms($asking(['receipt' => $ids[0]]), 'self:alerts-test') && $receipts->confirms($asking(['receipt' => $ids[11]]), 'self:alerts-test'),
    'Ten plans open at once; the oldest goes first.');
env_assert(PlanReceipts::canonical(['b' => 2, 'a' => ['y' => true, 'x' => null, 'z' => ''], 'c' => []]) === ['a' => ['y' => 'yes'], 'b' => '2'],
    'Keys in order, numbers and yes as the form sends them, an empty value as none.');
$bare = Request::create('/admin/server/x', 'POST');
env_assert($receipts->issue($bare, 'self:alerts-test') === '' && !$receipts->confirms($bare, 'self:alerts-test'), 'Without a session nothing can be confirmed.');

// 2. Each of the panel's runs on the recorded server: the plans as the server would answer them, every start counted.
$server = new class (new RecordedAnswersGateway(env_recorded_path())) implements PanelGateway {
    /** @var list<PanelRequest> */
    public array $started = [];

    public function __construct(private PanelGateway $recorded)
    {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        $answer = static fn (string $kind, array $data, ?string $job = null): PanelAnswer => PanelAnswer::fromDocument($request->requestId,
            ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId, 'status' => 'ok', 'kind' => $kind, 'data' => $data] + ($job !== null ? ['job_id' => $job] : []));
        if ($request->confirm) {
            $this->started[] = $request;

            return $answer('job', ['state' => 'running'], str_repeat('e', 24));
        }
        // The backups the server holds (simai-env 1.64.0): a removed site's last copy and one before a database went.
        if ($request->command === 'backup:data-list') {
            $item = static fn (string $domain, bool $exists, string $backup, string $reason, array $parts): array => ['domain' => $domain, 'site_exists' => $exists,
                'backup' => $backup, 'path' => '/var/backups/simai/'.$domain.'/'.$backup, 'created_at' => '2026-11-20T01:00:00', 'size_bytes' => 4096,
                'parts' => $parts, 'checksums' => true, 'encrypted' => false, 'reason' => $reason, 'keep_until' => '2026-11-27T01:00:00'];

            return $answer('result', ['schema' => 'simai-backup-list/1', 'dest' => '/var/backups/simai', 'count' => 2, 'items' => [
                $item('gone.test', false, '20261120-010000', 'site-remove', ['config', 'db', 'files']),
                $item('demo-php.test', true, '20261120-020000', 'db-drop', ['db'])]]);
        }
        if (PanelCommands::kind($request->command) !== 'read') {
            return $answer('plan', ['command' => $request->command, 'args' => array_map(static fn (mixed $value): mixed => $value, $request->args),
                'description' => 'What the server would do', 'effects' => 'write', 'lock' => 'host', 'applied' => false]);
        }
        $read = $this->recorded->ask($request);
        $data = $read->data;
        if ($request->command === 'self:describe') {
            // A server new enough for every action, its gateway listing none apart.
            $data['simai_env']['version'] = '1.70.0';
            unset($data['gate']);
        } elseif ($request->command === 'php:list') {
            $data['versions'][] = ['version' => '8.2', 'fpm_active' => true, 'sites' => [], 'native' => false, 'cli_default' => false, 'package_version' => '8.2.1', 'updates' => [], 'removable' => true];
        } elseif ($request->command === 'self:profiles') {
            $data['disabled'] = [['id' => 'bitrix', 'title' => 'Bitrix'], ['id' => 'node', 'title' => 'Node.js']];
        } else {
            return $read;
        }

        return PanelAnswer::fromDocument($request->requestId, ['schema' => 'simai-panel-answer/1', 'request_id' => $request->requestId,
            'status' => 'ok', 'kind' => 'result', 'data' => $data]);
    }

    public function source(): string
    {
        return 'recorded';
    }
};
$views = new class implements \Illuminate\Contracts\View\Factory {
    /** @var array<string, mixed> */
    public array $data = [];

    public function exists($view): bool { return true; }
    public function file($path, $data = [], $mergeData = []): \Illuminate\Contracts\View\View { throw new LogicException('file'); }
    public function make($view, $data = [], $mergeData = []): \Illuminate\Contracts\View\View
    {
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
$translator = new class ($lines) implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines) {}
    public function get($key, array $replace = [], $locale = null): mixed
    {
        $value = data_get($this->lines, substr((string) $key, strlen('larena-env::admin.')));

        return is_string($value) ? $value : $key;
    }
    public function choice($key, $number, array $replace = [], $locale = null): string { return (string) $this->get($key, $replace); }
    public function getLocale(): string { return 'en'; }
    public function setLocale($locale): void {}
};
$rights = new class implements \Illuminate\Contracts\Config\Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-access.operations' => [
        'env.server.operate' => ['target' => 'server:host', 'required_grants' => ['operate'], 'test_grants' => ['test:system' => ['host' => ['operate']]]],
        'env.sites.operate' => ['target' => 'server:host', 'required_grants' => ['operate'], 'test_grants' => ['test:system' => ['host' => ['operate']]]],
    ]];
    public function has($key): bool { return array_key_exists($key, $this->items); }
    public function get($key, $default = null): mixed { return $this->items[$key] ?? $default; }
    public function all(): array { return $this->items; }
    public function set($key, $value = null): void { $this->items[$key] = $value; }
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$encrypter = new class implements \Illuminate\Contracts\Encryption\StringEncrypter {
    public function encryptString($value): string { return 'sealed:'.base64_encode((string) $value); }
    public function decryptString($payload): string { return (string) base64_decode(substr((string) $payload, 7)); }
};
$authorizer = new \Larena\Access\Runtime\AccessOperationAuthorizer($rights, 'testing');
$operations = new PanelOperations($server);
$overviews = new ServerOverviewReader($server);
$extras = new SiteExtrasReader($server);
$clock = time();
$timed = env_plan_receipts($clock);

$people = (new ReflectionClass(PeopleController::class))->newInstanceWithoutConstructor();
// The owner actions read only the gateway, the views and the words: the rest of the people's screen is not built here.
foreach (['operations' => $operations, 'views' => $views, 'translator' => $translator, 'receipts' => $timed] as $property => $value) {
    (new ReflectionProperty(PeopleController::class, $property))->setValue($people, $value);
}

$cron = new SiteCronController($operations, $overviews, $extras, $views, $translator, $timed);
$update = new UpdateController($operations, $extras, $views, $translator, $authorizer, $overviews, $timed);
$cases = [
    'update' => [$update->plan(...), $update->run(...), ['target' => 'env'], ['target' => 'panel'], 'self:update'],
    'firewall' => [($firewall = new FirewallController($operations, $overviews, $extras, $views, $translator, $authorizer, $timed))->plan(...), $firewall->run(...),
        ['op' => 'block', 'address' => '203.0.113.7', 'ttl' => '3600'], ['args' => ['address' => '203.0.113.8', 'ttl' => '3600']], 'firewall:block'],
    'software' => [($software = new SoftwareController($operations, $overviews, $extras, $views, $translator, $update, $authorizer, $timed))->plan(...), $software->run(...),
        ['op' => 'update', 'security_only' => 'yes'], ['security-only' => 'no', 'security_only' => 'no'], 'software:update'],
    'php' => [($php = new SoftwarePhpController($operations, $overviews, $extras, $views, $translator, $authorizer, $timed))->plan(...), $php->run(...),
        ['op' => 'update', 'php' => '8.3'], ['php' => '8.2'], 'php:update'],
    'panel address' => [($address = new PanelAddressController($operations, $overviews, $extras, $views, $translator, $authorizer, $timed))->plan(...), $address->run(...),
        ['op' => 'ip-access', 'enabled' => 'yes'], ['enabled' => 'no'], 'panel:ip-access'],
    'cron' => [static fn (Request $request) => $cron->plan($request, 'demo-owned.test'), static fn (Request $request) => $cron->run($request, 'demo-owned.test'),
        ['op' => 'add', 'preset' => 'custom', 'custom' => '*/5 * * * *', 'command' => 'php artisan schedule:run'],
        ['args' => ['domain' => 'demo-owned.test', 'schedule' => '*/5 * * * *', 'command' => 'php artisan queue:work']], 'cron:job-add'],
    'storage' => [($storage = new BackupStorageController($operations, $overviews, $extras, $views, $translator, $encrypter, $authorizer, $timed))->plan(...), $storage->run(...),
        ['op' => 'add', 'name' => 'offsite', 'provider' => 'other', 'bucket' => 'backups', 'endpoint' => 'https://s3.example.org', 'region' => 'eu-1',
            'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'very-secret-key'], ['bucket' => 'elsewhere'], 'backup:storage-add'],
    // The way back of a held backup (simai-env 1.64.0): a removed site is made again first; a database the site has
    // again comes back from the backup, its files left as they are.
    'return site' => [($held = new BackupStorageController($operations, $overviews, $extras, $views, $translator, $encrypter, $authorizer, $timed,
        new \Larena\Env\Server\SiteDetailsReader($server)))->plan(...), $held->run(...),
        ['op' => 'return-site', 'domain' => 'gone.test', 'backup' => '20261120-010000'], ['step' => 'restore'], 'site:add'],
    'return database' => [$held->plan(...), $held->run(...),
        ['op' => 'return-db', 'domain' => 'demo-php.test', 'backup' => '20261120-020000'], ['db' => 'no'], 'backup:data-restore'],
    'security' => [($security = new SecurityController($operations, $overviews, $extras, $views, $translator, $authorizer, null, $timed))->plan(...), $security->run(...),
        ['op' => 'level', 'set' => 'enhanced'], ['set' => 'strict'], 'security:level'],
    'kind of site' => [($newSite = new NewSiteController($overviews, $operations, $views, $translator, $extras, $timed))->enablePlan(...), $newSite->enableRun(...),
        ['id' => 'bitrix'], ['id' => 'node'], 'profile:enable'],
    'server account' => [$people->planOwner(...), $people->runOwner(...), ['action' => 'block', 'name' => 'demo'], ['name' => 'other'], 'owner:block'],
];
foreach ($cases as $name => [$plan, $run, $input, $changed, $command]) {
    $planned = static function () use ($plan, $asking, $input, $views, $name): array {
        $answer = $plan($asking($input));
        $hidden = $views->data['hidden'] ?? [];
        env_assert(!$answer instanceof RedirectResponse && strlen((string) ($hidden[PlanReceipts::FIELD] ?? '')) === 32,
            "{$name}: planned with its receipt: ".($answer instanceof RedirectResponse ? (string) $answer->getSession()?->get('larena_env_notice') : json_encode($hidden)));
        // The page's hidden fields as the browser sends them back: args[name] becomes args.name.
        parse_str(http_build_query($hidden), $sent);

        return $sent;
    };
    $starts = static fn (): int => count(array_filter($server->started, static fn (PanelRequest $one): bool => $one->command === $command));
    $refused = static function (RedirectResponse|\Illuminate\Contracts\View\View $answer, string $what) use ($session, $refusal, $starts, $name): void {
        env_assert($answer instanceof RedirectResponse && $starts() === 0 && $session->get('larena_env_notice') === $refusal,
            "{$name}: {$what} is refused in words, the server not asked: ".(string) $session->get('larena_env_notice'));
        $session->forget('larena_env_notice');
    };
    $server->started = [];
    $sent = $planned();
    $refused($run($asking(array_diff_key($sent, [PlanReceipts::FIELD => true]))), 'a confirmation without the receipt');
    $changedSent = array_replace_recursive($sent, $changed);
    $refused($run($asking($changedSent)), 'a value changed after the plan');
    $sent = $planned();
    $refused($run($asking($sent, 'staff@stand.test')), 'another person\'s confirmation');
    $sent = $planned();
    $clock += 901;
    $refused($run($asking($sent)), 'a receipt older than 15 minutes');
    $sent = $planned();
    $run($asking($sent));
    env_assert($starts() === 1, "{$name}: the plan's receipt starts it.");
    $run($asking($sent));
    env_assert($starts() === 1 && $session->get('larena_env_notice') === $refusal, "{$name}: a receipt starts it once.");
    $session->forget('larena_env_notice');
}

// What each step of the way back says comes next, and what it asks of the server.
$held->plan($asking(['op' => 'return-site', 'domain' => 'gone.test', 'backup' => '20261120-010000']));
env_assert(($views->data['hidden']['step'] ?? null) === 'add' && $views->data['then'] === [$lines['storages']['held']['then']['return-site']['add']],
    'A removed site is made again first, and the plan says the data comes back after.');
$held->plan($asking(['op' => 'return-db', 'domain' => 'demo-php.test', 'backup' => '20261120-020000']));
env_assert(($views->data['hidden']['step'] ?? null) === 'restore' && ($views->data['plan']->args['files'] ?? null) === 'no', 'A database the site has again: only the database is put back.');
$unknown = $held->plan($asking(['op' => 'return-db', 'domain' => 'demo-php.test', 'backup' => '20990101-000000']));
env_assert($unknown instanceof RedirectResponse && $session->get('larena_env_notice') === $lines['reasons']['backup_unknown'], 'A backup the server does not hold is refused in words.');
$session->forget('larena_env_notice');

echo "PlanReceiptsTest passed.\n";
