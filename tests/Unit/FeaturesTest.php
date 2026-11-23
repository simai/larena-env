<?php

declare(strict_types=1);

// The package's features (auditor msg-3d61bd36, point 5): the product chooses them through core's FeatureSet, and what is
// off is absent — routes, menu rows, rights, tables and background jobs. Local flags only narrow the choice. The panel's
// edition turns on all of them where its product file names none of env's. And while the gateway cannot be asked, the
// background jobs end with no request and no write.

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Larena\Core\Features\FeatureSet;
use Larena\Env\Console\PanelCheckCommand;
use Larena\Env\Console\PanelWarmCommand;
use Larena\Env\Gateway\CachingPanelGateway;
use Larena\Env\Gateway\GatewayReadiness;
use Larena\Env\Gateway\PanelAnswer;
use Larena\Env\Gateway\PanelReason;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\History\MetricSamples;
use Larena\Env\Providers\EnvDeclarations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Warnings\WarningLedger;

require_once dirname(__DIR__).'/Support/env-test.php';
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

/** @return array<string, mixed> */
function env_features_boot(string $features, string $scheduler = '', string $flags = '', string $mode = 'with'): array
{
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Support/env-boot.php').' '.escapeshellarg($mode).' '.escapeshellarg($scheduler)
        .' '.escapeshellarg($features).' '.escapeshellarg($flags).' 2>&1', $output, $exitCode);
    env_assert($exitCode === 0, "The provider did not boot ({$features}): ".implode("\n", $output));

    return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $boot @return list<string> */
function env_features_routes(array $boot): array
{
    $names = array_keys($boot['routes']);
    sort($names);

    return $names;
}

$dnsRoutes = static fn (array $names): array => array_values(array_filter($names, static fn (string $name): bool => str_contains($name, '.dns')));
$dnsRows = static fn (array $rows): array => array_values(array_filter($rows, static fn (string $row): bool => str_starts_with($row, 'env.dns')));
$sorted = static function (array $list): array {
    sort($list);

    return $list;
};

// The declared features and their dependencies, as core's FeatureSet takes them.
$refused = static function (array $chosen): ?string {
    $set = new FeatureSet($chosen);
    $set->declare('larena/env', EnvDeclarations::FEATURES);
    try {
        $set->validate();
    } catch (InvalidArgumentException $failure) {
        return $failure->getMessage();
    }

    return null;
};
env_assert($refused(['env.dns']) === 'larena_feature_requirement_off: env.dns -> env.admin', 'DNS needs the screens.');
env_assert($refused(['env.monitoring', 'env.notifications']) === 'larena_feature_requirement_off: env.notifications -> env.admin', 'Telling people needs the rights of the screens.');
env_assert($refused(['env.admin', 'env.notifications']) === 'larena_feature_requirement_off: env.notifications -> env.monitoring', 'Telling people needs the check.');
env_assert($refused(['env.monitoring']) === null && $refused(['env.admin', 'env.dns']) === null, 'The check alone, and the screens with DNS, are whole choices.');

// No feature set (a host without core's provider) and a product that takes its packages whole: everything, as before.
$whole = env_features_boot('null', 'env');
$bare = env_features_boot('', 'env');
env_assert(env_features_routes($whole) === env_features_routes($bare) && count($whole['routes']) > 80 && $dnsRoutes(env_features_routes($whole)) !== [],
    'With every feature on, the screens are those of a host without a feature set.');
env_assert($whole['rights'] === $bare['rights'] && count($whole['rights']) === 16 && $whole['migrations'] === ['migrations', 'dns']
    && $whole['scheduled'] === ['larena:env-check', 'larena:env-warm'] && $whole['notification_sources'] === true, 'Rights, tables, jobs and the bell\'s names, all of them.');

// A product that chose its features and none of env's: env is installed and absent.
$none = env_features_boot('[]', 'env');
env_assert($none['routes'] === [] && $none['navigation'] === [] && $none['rights'] === [] && $none['migrations'] === []
    && $none['scheduled'] === [] && $none['notification_sources'] === false, 'Nothing of env without its features: '.json_encode(array_intersect_key($none, array_flip(['rights', 'migrations', 'scheduled']))));

// The screens without DNS: no DNS route, menu row, right or table.
$admin = env_features_boot('["env.admin"]', 'env');
env_assert($dnsRoutes(env_features_routes($admin)) === [] && count(env_features_routes($admin)) === count(env_features_routes($whole)) - count($dnsRoutes(env_features_routes($whole))),
    'The screens without DNS: '.implode(', ', $dnsRoutes(env_features_routes($admin))));
env_assert($dnsRows($admin['navigation']) === [] && $dnsRows($whole['navigation']) === ['env.dns', 'env.dns.connection'], 'No DNS rows in the menu.');
env_assert($admin['rights'] === ['env.server.access', 'env.server.backup_storage', 'env.server.operate', 'env.server.read', 'env.server.security', 'env.server.software', 'env.server.update',
    'env.sites.backup', 'env.sites.database', 'env.sites.deploy', 'env.sites.files', 'env.sites.operate', 'env.sites.read', 'env.sites.remove'] && $admin['migrations'] === ['migrations'],
    'The rights and tables of the screens alone: '.json_encode([$admin['rights'], $admin['migrations']]));
env_assert($admin['scheduled'] === [] && $admin['notification_sources'] === false, 'No check without env.monitoring, even with the scheduler\'s flag on.');

// Auth's and Access's signals about people are heard only where the panel's people are a feature that is on.
$signals = [\Larena\Auth\Events\PersonDeleted::class, \Larena\Access\Events\RoleRevoked::class, \Larena\Access\Events\RoleRightsRevoked::class];
env_assert($whole['listening'] === $signals && $admin['listening'] === $signals, 'With env.admin on, the panel hears a person removed and a role or right taken away.');
env_assert($none['listening'] === [], 'Without env\'s features nothing is heard: '.json_encode($none['listening']));

// The check alone: measured every 5 minutes, no screen, no warm-up of pages that do not exist, nobody told.
$monitoring = env_features_boot('["env.monitoring"]', 'env');
env_assert($monitoring['routes'] === [] && $monitoring['navigation'] === [] && $monitoring['rights'] === [] && $monitoring['migrations'] === ['migrations']
    && $monitoring['scheduled'] === ['larena:env-check'] && $monitoring['notification_sources'] === false, 'The check alone: '.json_encode($monitoring['scheduled']));
env_assert($monitoring['listening'] === [], 'The check alone hears nothing about people: nobody is told and no screen ties people to sites.');

// Local flags only narrow: the scheduler's flag off keeps every job off, the screens' flag off keeps every route off.
$narrowed = env_features_boot('null', '', 'admin-off');
env_assert($narrowed['scheduled'] === [] && $narrowed['routes'] === [] && count($narrowed['rights']) === 16, 'The flags narrow a feature that is on.');

// The panel's edition: a product file that names none of env's features gets them all; one that names its own keeps them.
$panel = env_features_boot('[]', 'panel');
env_assert($panel['features'] === ['env.admin', 'env.monitoring', 'env.notifications', 'env.dns'] && env_features_routes($panel) === env_features_routes($whole)
    && $panel['scheduled'] === ['larena:env-check', 'larena:env-warm'] && $panel['migrations'] === ['migrations', 'dns'] && count($panel['rights']) === 16,
    'The panel behaves as before: '.json_encode($panel['features']));
$own = env_features_boot('["env.admin"]', 'panel');
env_assert($own['features'] === ['env.admin'] && $dnsRoutes(env_features_routes($own)) === [] && $own['scheduled'] === [], 'A product\'s own choice of env\'s features stays exact.');

// The gateway's readiness, from its configuration alone.
$folder = env_temp_directory();
touch($folder.'/gate');
touch($folder.'/key');
env_assert(GatewayReadiness::of(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path()]], true)->ready(), 'Recorded answers where allowed.');
env_assert(GatewayReadiness::of(['driver' => 'recorded', 'recorded' => ['path' => env_recorded_path()]], false)->reason === GatewayReadiness::RECORDED_NOT_ALLOWED, 'Not elsewhere.');
env_assert(GatewayReadiness::of(['driver' => 'sudo', 'sudo' => ['gate' => $folder.'/missing', 'binary' => $folder.'/gate']], false)->reason === GatewayReadiness::SUDO_GATE_MISSING
    && GatewayReadiness::of(['driver' => 'sudo', 'sudo' => ['gate' => $folder.'/gate', 'binary' => $folder.'/gate']], false)->ready(), 'The sudo gate where its program is.');
env_assert(GatewayReadiness::of(['driver' => 'ssh', 'ssh' => ['host' => 'server.example.test', 'key_path' => $folder.'/key', 'known_hosts_path' => $folder.'/missing', 'binary' => $folder.'/gate']], false)->reason === GatewayReadiness::SSH_TARGET_MISSING
    && GatewayReadiness::of(['driver' => 'ssh', 'ssh' => ['host' => 'server.example.test', 'key_path' => $folder.'/key', 'known_hosts_path' => $folder.'/key', 'binary' => $folder.'/gate']], false)->ready(), 'SSH with its key and known hosts.');
env_assert(GatewayReadiness::of([], false)->reason === PanelReason::NOT_CONFIGURED && GatewayReadiness::of(['driver' => 'other'], true)->reason === PanelReason::NOT_CONFIGURED, 'Anything else is not configured.');

// While the gateway is not ready, the background jobs ask nothing and write nothing: no samples, no ledger, no audit, no
// notification, not even the cache's note that the server did not answer.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$container = new AdminPresenceHost();
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
foreach ([...(glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: []), ...(glob(dirname(__DIR__, 2).'/vendor/larena/notification/database/migrations/*.php') ?: [])] as $migration) {
    (require $migration)->up();
}
$connection = $capsule->getConnection();
$sink = new \Larena\Audit\Runtime\InMemoryAuditSink();
$container->instance(\Larena\Audit\Contracts\AuditSink::class, $sink);
$container->instance(\Larena\Notification\Runtime\NotificationInbox::class, new \Larena\Notification\Runtime\NotificationInbox($connection));
$owners = new \Larena\Env\People\PersonOwners($connection);
$owners->assign('test:anna', 'demo', 'test:system');
$asked = 0;
$counting = new class(new RecordedAnswersGateway(env_recorded_path()), $asked) implements \Larena\Env\Contracts\PanelGateway {
    public function __construct(private RecordedAnswersGateway $inner, private int &$asked) {}
    public function ask(PanelRequest $request): PanelAnswer
    {
        $this->asked++;

        return $this->inner->ask($request);
    }
    public function source(): string { return 'recorded'; }
};
$cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());
$gateway = new CachingPanelGateway($counting, $cache, 60);
$config = new class implements \Illuminate\Contracts\Config\Repository {
    public function has($key): bool { return false; }
    public function get($key, $default = null): mixed { return ['app.url' => 'https://panel.example.test', 'larena-env.admin.prefix' => 'admin/server'][$key] ?? $default; }
    public function all(): array { return []; }
    public function set($key, $value = null): void {}
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$translator = new class implements \Illuminate\Contracts\Translation\Translator {
    public function get($key, array $replace = [], $locale = null): string { return (string) $key; }
    public function choice($key, $number, array $replace = [], $locale = null): string { return (string) $key; }
    public function getLocale(): string { return 'en'; }
    public function setLocale($locale): void {}
};
$recipients = new \Larena\Env\People\PanelRecipients(static fn (): array => [], static fn (string $subject, string $right): bool => true,
    static fn (string $subject): string => \Larena\Env\People\PanelRecipients::ACTIVE, $owners, new \Larena\Env\People\PanelAudit($container));
$check = static function (?GatewayReadiness $readiness) use ($gateway, $connection, $recipients, $config, $translator, $container): string {
    $command = new PanelCheckCommand();
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output));
    env_assert($command->handle(new ServerOverviewReader($gateway), new SiteExtrasReader($gateway), new MetricSamples($connection), new WarningLedger($connection),
        $recipients, $config, $translator, $container, $readiness) === 0, 'The check ends without a failure.');

    return $output->fetch();
};
$rows = static fn (): array => [$connection->table(MetricSamples::TABLE)->count(), $connection->table(WarningLedger::TABLE)->count(),
    $connection->table('larena_notifications')->count(), $connection->table(\Larena\Env\People\PersonOwners::TABLE)->count()];
$before = $rows();
$said = $check(new GatewayReadiness(PanelReason::NOT_CONFIGURED));
env_assert($asked === 0 && $rows() === $before && $sink->events() === [] && str_contains($said, 'not ready (gateway_not_configured)'), 'Not ready: nothing asked or written: '.$said);
env_assert(CachingPanelGateway::silentSince($cache) === null && $cache->get('larena-env:gateway:down') === null, 'Not even the cache\'s note of silence.');
$warm = new PanelWarmCommand();
$warm->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput()));
env_assert($warm->handle($gateway, new GatewayReadiness(GatewayReadiness::SUDO_GATE_MISSING)) === 0 && $asked === 0, 'The warm-up asks nothing either.');

// Ready, with env.notifications off: the check measures, nobody is told.
$container->instance(FeatureSet::class, new FeatureSet(['env.admin', 'env.monitoring']));
$said = $check(new GatewayReadiness());
env_assert($asked > 0 && str_contains($said, 'notifications: 0') && $connection->table('larena_notifications')->count() === 0, 'Without env.notifications nobody is told: '.$said);
// And with it on, the same check tells the space's person.
$container->instance(FeatureSet::class, new FeatureSet(['env.admin', 'env.monitoring', 'env.notifications']));
$connection->table(WarningLedger::TABLE)->delete();
$said = $check(new GatewayReadiness());
env_assert(!str_contains($said, 'notifications: 0,') && $connection->table('larena_notifications')->count() > 0, 'With env.notifications people are told: '.$said);

echo "FeaturesTest passed.\n";
