<?php

declare(strict_types=1);

// The new site's fields in the Framework property view (NewSitePropertyViewPort): each kind's empty site, its fields and
// groups; record.create plans site:add as the form does and answers a draft whose address is the plan screen; a value
// the gateway would refuse stands under its field; an owner makes sites in their own space only.

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Env\Admin\NewSitePropertyViewPort;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\Http\Controllers\NewSiteController;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

require_once dirname(__DIR__).'/Support/env-test.php';

/** @param array<string, mixed> $parameters */
function route(string $name, array $parameters = [], bool $absolute = true): string
{
    return match ($name) {
        'larena.env.admin.sites.plan.draft' => '/admin/server/sites/new/plan/'.$parameters['draft'],
        default => '/admin/server/'.$name,
    };
}

$lines = require dirname(__DIR__, 2).'/resources/lang/ru/admin.php';
$translator = new class ($lines) implements Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines)
    {
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $value = str_starts_with($key, 'larena-env::admin.') ? data_get($this->lines, substr($key, strlen('larena-env::admin.'))) : null;

        return is_string($value) || is_array($value) ? $value : $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->get($key);
    }

    public function getLocale()
    {
        return 'ru';
    }

    public function setLocale($locale): void
    {
    }
};

$database = tempnam(sys_get_temp_dir(), 'larena-env-new-site-');
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
$connection = $capsule->getConnection();
\Larena\Setting\Persistence\DatabaseViewSettingsStore::createSchema($connection->getSchemaBuilder());
register_shutdown_function(static fn () => @unlink((string) $database));

$gateway = new RecordedAnswersGateway(env_recorded_path());
$sites = new NewSiteController(new ServerOverviewReader($gateway), new PanelOperations($gateway),
    new \Illuminate\View\Factory(new \Illuminate\View\Engines\EngineResolver(), new \Illuminate\View\FileViewFinder(new \Illuminate\Filesystem\Filesystem(), []), new \Illuminate\Events\Dispatcher()),
    $translator, new SiteExtrasReader($gateway));
$port = new NewSitePropertyViewPort($sites, $translator, new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection)));
$session = new Store('test', new ArraySessionHandler(10));
$admin = $port->for(new PanelActor('admin@stand.test'), $session);
$owner = $port->for(new PanelActor('staff@stand.test', 'demo'), $session);

$keys = static function (array $answer): array {
    $keys = [];
    foreach ($answer['record']['sections'] ?? [] as $section) {
        foreach ($section['groups'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $keys[$group['key'] ?? $group['id'] ?? '?'][] = $field['key'];
            }
        }
    }

    return $keys;
};
$empty = static fn ($port, string $kind): array => $port->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.load', ['mode' => 'create', 'collection' => 'env.new_site.'.$kind]);

// Laravel: the domain and whose site it is, PHP and a database (on by default), the daily backup.
$laravel = $empty($admin, 'laravel');
env_assert(($laravel['answer'] ?? null) === 'applied', 'Laravel\'s empty site reads: '.json_encode($laravel));
$fields = array_merge(...array_values($keys($laravel)));
env_assert($fields === ['domain', 'owner', 'php', 'create_db', 'db_engine', 'daily_backup'], 'Laravel asks the domain, the owner, PHP, a database and the backup: '.json_encode($fields));
env_assert(array_keys($keys($laravel)) === ['address', 'run', 'backup'], 'In three groups: '.json_encode(array_keys($keys($laravel))));
$json = json_encode($laravel, JSON_UNESCAPED_UNICODE);
env_assert(str_contains($json, 'PHP и база данных') && str_contains($json, 'Резервная копия') && str_contains($json, '"create_db"'), 'The groups in Russian.');

// An app on a port asks the port, the path and WebSocket, no PHP, no database, no backup.
$proxy = array_merge(...array_values($keys($empty($admin, 'proxy'))));
env_assert($proxy === ['domain', 'owner', 'upstream_port', 'upstream_path', 'websockets'], 'An app on a port: '.json_encode($proxy));
$redirect = array_merge(...array_values($keys($empty($admin, 'redirect'))));
env_assert(in_array('redirect_to', $redirect, true) && !in_array('php', $redirect, true), 'A redirect asks where to.');
env_assert(($empty($admin, 'nope')['answer'] ?? null) === 'refused', 'A kind the server does not make is refused.');

// An owner: the same kinds, never whose the site is.
$ownLaravel = array_merge(...array_values($keys($empty($owner, 'laravel'))));
env_assert(!in_array('owner', $ownLaravel, true) && in_array('domain', $ownLaravel, true), 'An owner\'s site is theirs: no owner field.');

// record.create plans site:add as the form does, keeps the values and leads to the plan screen.
$created = $admin->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.laravel',
    'values' => ['domain' => 'app.test', 'create_db' => true, 'db_engine' => 'mysql', 'daily_backup' => true]]);
env_assert(($created['answer'] ?? null) === 'applied' && preg_match('/^draft-[0-9a-f]{16}$/', (string) ($created['record_id'] ?? '')) === 1,
    'Laravel is planned: '.json_encode($created));
env_assert(($created['record']['address'] ?? null) === '/admin/server/sites/new/plan/'.$created['record_id'], 'The answer leads to the plan screen.');
$kept = $session->get(NewSiteController::DRAFTS.'.'.$created['record_id']);
env_assert(is_array($kept) && $kept['profile'] === 'laravel' && $kept['domain'] === 'app.test' && $kept['create-db'] === 'yes' && $kept['daily-backup'] === 'yes',
    'The values are kept as the form sends them: '.json_encode($kept));

$app = $admin->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.proxy',
    'values' => ['domain' => 'demo-proxy.test', 'upstream_port' => 3000, 'websockets' => true]]);
env_assert(($app['answer'] ?? null) === 'applied', 'An app on port 3000 is planned: '.json_encode($app));
env_assert($session->get(NewSiteController::DRAFTS.'.'.$app['record_id'])['upstream-port'] === '3000', 'The port is sent as text.');

// A value the gateway would refuse stands under its field, before the server is asked.
$wrong = $admin->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.laravel', 'values' => ['domain' => 'not a domain']]);
env_assert(($wrong['answer'] ?? null) === 'refused' && str_contains((string) ($wrong['errors']['domain'] ?? ''), 'example.com'), 'A wrong domain is said under it: '.json_encode($wrong, JSON_UNESCAPED_UNICODE));
$port80 = $admin->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.proxy', 'values' => ['domain' => 'a.test', 'upstream_port' => 80]]);
env_assert(($port80['answer'] ?? null) === 'refused' && isset($port80['errors']['upstream_port']), 'Port 80 is refused under the port.');
$missing = $admin->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.proxy', 'values' => ['domain' => 'a.test']]);
env_assert(($missing['answer'] ?? null) === 'refused' && isset($missing['errors']['upstream_port']), 'The port is required.');
env_assert(($admin->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.laravel',
    'values' => ['domain' => 'app.test', 'owner' => 'x']])['answer'] ?? null) === 'refused', 'An owner the server does not have is refused.');

// An owner's create never sends whose the site is: the gateway puts it in their space.
$ownCreated = $owner->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.static', 'values' => ['domain' => 'mine.test']]);
env_assert(in_array($ownCreated['answer'] ?? null, ['applied', 'refused'], true), 'An owner\'s plan is the gateway\'s to answer: '.json_encode($ownCreated));
env_assert(($owner->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.create', ['collection' => 'env.new_site.static',
    'values' => ['domain' => 'mine.test', 'owner' => 'demo']])['answer'] ?? null) === 'refused', 'An owner cannot name whose the site is.');

// Without a person, nothing is read.
env_assert(($port->raise('panel', NewSitePropertyViewPort::SCOPE, 'record.load', ['mode' => 'create', 'collection' => 'env.new_site.laravel'])['answer'] ?? null) === 'refused',
    'The port without its request answers nothing.');

echo "NewSitePortTest passed.\n";
