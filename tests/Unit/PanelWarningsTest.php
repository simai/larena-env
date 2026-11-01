<?php

declare(strict_types=1);

// What the panel says before anyone asks (Panel plan 4.4–4.6): the warnings it reads from the server's answers, the
// ledger that tells people once and again each day, the load it keeps for 30 days, and the check that does both.

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Larena\Env\Console\PanelCheckCommand;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\RecordedAnswersGateway;
use Larena\Env\History\MetricHistory;
use Larena\Env\History\MetricSamples;
use Larena\Env\Server\ServerMetrics;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteSummary;
use Larena\Env\Warnings\PanelWarning;
use Larena\Env\Warnings\PanelWarnings;
use Larena\Env\Warnings\WarningLedger;

require_once dirname(__DIR__).'/Support/env-test.php';
require_once dirname(__DIR__).'/Support/AdminPresenceHost.php';

$now = new DateTimeImmutable('2026-11-01T12:00:00Z');
$site = static fn (string $domain, array $with = []): SiteSummary => new SiteSummary(...$with + [
    'domain' => $domain, 'profile' => 'generic', 'php' => '8.3', 'ssl' => 'none', 'owner' => null, 'databaseEngine' => null,
    'release' => null, 'certificateDaysLeft' => null, 'backupSchedule' => true, 'answers' => 200, 'answersChecked' => true,
    'lastBackup' => '2026-11-01T03:00:00Z']);
$kinds = static fn (array $warnings): array => array_map(static fn (PanelWarning $warning): string => $warning->kind.':'.$warning->severity, $warnings);

// A healthy site says nothing.
env_assert(PanelWarnings::forSite($site('ok.test'), $now) === [], 'A site that answers, has a fresh daily backup and no https says nothing.');
// Whether it answers.
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['answers' => null]), $now)) === ['site_down:error'], 'A site that does not answer.');
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['answers' => 502]), $now)) === ['site_error:error'], 'A site that answers 502.');
env_assert(PanelWarnings::forSite($site('a.test', ['answers' => null, 'answersChecked' => false]), $now) === [], 'A site not checked yet is not said to be down.');
env_assert(PanelWarnings::forSite($site('a.test', ['answers' => 404]), $now) === [], 'A 404 is the site\'s own answer, not the server failing.');
// Its certificate.
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['ssl' => 'letsencrypt', 'certificateDaysLeft' => 9]), $now)) === ['certificate_ending:warning'], 'Ends in 9 days.');
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['ssl' => 'letsencrypt', 'certificateDaysLeft' => 2]), $now)) === ['certificate_ending:error'], 'Ends in 2 days: an error.');
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['ssl' => 'letsencrypt', 'certificateDaysLeft' => -1]), $now)) === ['certificate_expired:error'], 'Expired.');
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['ssl' => 'letsencrypt']), $now)) === ['certificate_missing:warning'], 'https without a certificate.');
env_assert(PanelWarnings::forSite($site('a.test', ['ssl' => 'letsencrypt', 'certificateDaysLeft' => 40]), $now) === [], 'A certificate with time left.');
// Its backups.
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['lastBackup' => null, 'backupSchedule' => false]), $now)) === ['backup_none:warning'], 'No backup, no daily one.');
env_assert(PanelWarnings::forSite($site('a.test', ['lastBackup' => null]), $now) === [], 'A daily backup set and none yet: the first comes tonight.');
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['lastBackup' => '2026-10-29T00:00:00']), $now)) === ['backup_stale:warning'], 'Older than two days.');
env_assert(PanelWarnings::forSite($site('a.test', ['lastBackup' => '2026-10-29T00:00:00']), $now)[0]->replace['time'] === '2026-10-29 00:00 UTC', 'The time reads as a person reads it.');
env_assert($kinds(PanelWarnings::forSite($site('a.test', ['backupSchedule' => false]), $now)) === ['backup_unscheduled:warning'], 'Fresh, but only by hand.');
env_assert(PanelWarnings::forSite($site('a.test', ['profile' => 'redirect', 'lastBackup' => null, 'backupSchedule' => false]), $now) === [], 'A redirect keeps nothing to back up.');

// The whole server: a disk at 92% and at 97%, errors first; the disks only with the metrics.
$overview = new ServerOverview('now', '1.28.0', 'ubuntu', '24.04', [], [], [$site('ok.test'), $site('slow.test', ['lastBackup' => null, 'backupSchedule' => false]), $site('down.test', ['answers' => null])]);
$metrics = ServerMetrics::fromData(['disk' => [['mount' => '/', 'total_bytes' => 100, 'used_bytes' => 92], ['mount' => '/var', 'total_bytes' => 100, 'used_bytes' => 97], ['mount' => '/srv', 'total_bytes' => 100, 'used_bytes' => 50]]]);
$all = PanelWarnings::of($overview, $metrics, $now);
env_assert($kinds($all) === ['disk_full:error', 'site_down:error', 'disk_full:warning', 'backup_none:warning'], 'Errors first: '.implode(', ', $kinds($all)));
env_assert($all[0]->subject === '/var' && $all[0]->replace['percent'] === '97' && $all[0]->key() === 'disk_full:/var', 'The disk is named.');
env_assert($kinds(PanelWarnings::of($overview, null, $now)) === ['site_down:error', 'backup_none:warning'], 'Without the server\'s metrics, no disks.');
env_assert(count(PanelWarnings::ofSite($all, 'slow.test')) === 1 && $all[1]->tab() === 'overview' && $all[3]->tab() === 'backups', 'One site\'s, and where each is dealt with.');
env_assert(PanelCheckCommand::href($all[3], 'admin/server') === '/admin/server/sites/slow.test?tab=backups'
    && PanelCheckCommand::href($all[0], '/admin/server/') === '/admin/server/overview', 'A notification opens where the warning is dealt with.');

// The recorded server: its sites have no daily backup, and only demo-php.test has a backup, from long ago.
$recorded = (new ServerOverviewReader(new RecordedAnswersGateway(env_recorded_path())))->read(new PanelActor('admin@example.com'))->overview;
$seen = [];
foreach (PanelWarnings::of($recorded, null, $now) as $warning) {
    $seen[$warning->domain] = $warning->kind;
}
env_assert($seen === ['demo-owned.test' => 'backup_none', 'demo-php.test' => 'backup_stale', 'demo-static.test' => 'backup_none'], 'The recording: '.json_encode($seen));

// The database the check keeps its ledger and the load in, made by the package's own migrations.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$container = new AdminPresenceHost();
$container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $migration) {
    (require $migration)->up();
    (require $migration)->up();
}
$connection = $capsule->getConnection();

// The ledger: told when new, not again within a day, at once when worse, again after a day, forgotten when gone.
$ledger = new WarningLedger($connection);
$backup = new PanelWarning(PanelWarnings::BACKUP_NONE, PanelWarning::WARNING, 'a.test');
$cert = new PanelWarning(PanelWarnings::CERTIFICATE_ENDING, PanelWarning::WARNING, 'a.test', ['days' => '9']);
env_assert(count($ledger->due([$backup, $cert], $now)) === 2, 'New warnings are told.');
env_assert($ledger->due([$backup, $cert], $now->modify('+5 minutes')) === [], 'Five minutes on, nothing new to tell.');
$worse = new PanelWarning(PanelWarnings::CERTIFICATE_ENDING, PanelWarning::ERROR, 'a.test', ['days' => '2']);
env_assert($ledger->due([$backup, $worse], $now->modify('+10 minutes')) === [$worse], 'A warning that got worse is told at once.');
env_assert($ledger->due([$backup, $worse], $now->modify('+25 hours')) === [$backup, $worse], 'Once a day while it lasts.');
env_assert($ledger->due([$worse], $now->modify('+26 hours')) === [] && $connection->table(WarningLedger::TABLE)->count() === 1, 'A warning that is gone is forgotten.');
env_assert($ledger->due([$backup, $worse], $now->modify('+27 hours')) === [$backup], 'Its return is news again.');

// The load: kept, drawn by the day and by the hour of the week, pruned after 30 days.
$samples = new MetricSamples($connection);
$sample = static fn (float $load, int $used): ServerMetrics => ServerMetrics::fromData(['cpu' => ['cores' => 4, 'load1' => $load, 'load5' => 0, 'load15' => 0],
    'memory' => ['total_bytes' => 1000, 'used_bytes' => $used], 'disk' => [['mount' => '/', 'total_bytes' => 100, 'used_bytes' => 40]]]);
$samples->record($sample(2.0, 500), $now->modify('-40 days'));
$samples->record($sample(1.0, 250), $now->modify('-3 days'));
$samples->record($sample(1.0, 250), $now->modify('-3 days +10 minutes'));
$samples->record($sample(2.0, 500), $now->modify('-2 hours'));
$samples->record($sample(8.0, 750), $now->modify('-1 hour'));
env_assert($samples->prune($now) === 1, 'Older than 30 days is removed.');
$day = $samples->history('day', $now);
env_assert($day->count === 2 && array_keys($day->lines) === ['load', 'memory', 'disk:/'], 'The day: two measurements, three lines.');
env_assert($day->lines['load'][0][1] === 50.0 && $day->lines['load'][1][1] === 100.0 && $day->lines['memory'][1][1] === 75.0, 'In percent of the whole, at most 100.');
$week = $samples->history('week', $now);
env_assert($week->count === 4 && count($week->lines['load']) === 3 && $week->lines['load'][0][1] === 25.0, 'The week by the hour: the two of one hour are one point.');
env_assert(MetricHistory::polyline([[0.0, 0.0], [1.0, 100.0]], 600, 120) === '0.0,120.0 600.0,0.0', 'Drawn bottom up.');
env_assert($samples->history('day', $now->modify('-10 days'))->count === 0, 'An empty period.');

// The check itself, on the recorded server: it measures, and a person of demo's space hears about demo-owned.test only;
// five minutes later it tells nothing again. Without an administrator role store, only the space's people are told.
foreach (glob(dirname(__DIR__, 2).'/vendor/larena/notification/database/migrations/*.php') ?: [] as $migration) {
    (require $migration)->up();
}
$inbox = new \Larena\Notification\Runtime\NotificationInbox($connection, static fn (): DateTimeImmutable => $now);
$container->instance(\Larena\Notification\Runtime\NotificationInbox::class, $inbox);
$owners = new \Larena\Env\People\PersonOwners($connection);
$owners->assign('test:anna', 'demo', 'test:system');
$owners->assign('test:boris', 'other', 'test:system');
$config = new class implements \Illuminate\Contracts\Config\Repository {
    public function has($key): bool { return false; }
    public function get($key, $default = null): mixed { return ['app.url' => 'https://panel.example.test', 'larena-env.admin.prefix' => 'admin/server'][$key] ?? $default; }
    public function all(): array { return []; }
    public function set($key, $value = null): void {}
    public function prepend($key, $value): void {}
    public function push($key, $value): void {}
};
$english = require dirname(__DIR__, 2).'/resources/lang/en/admin.php';
$translator = new class($english) implements \Illuminate\Contracts\Translation\Translator {
    /** @param array<string, mixed> $lines */
    public function __construct(private array $lines) {}
    public function get($key, array $replace = [], $locale = null): string
    {
        $line = (string) \Illuminate\Support\Arr::get($this->lines, str_replace('larena-env::admin.', '', $key), $key);
        foreach ($replace as $name => $value) {
            $line = str_replace(':'.$name, (string) $value, $line);
        }

        return $line;
    }
    public function choice($key, $number, array $replace = [], $locale = null): string { return $this->get($key, $replace); }
    public function getLocale(): string { return 'en'; }
    public function setLocale($locale): void {}
};
$gateway = new RecordedAnswersGateway(env_recorded_path());
$run = static function () use ($gateway, $connection, $owners, $config, $translator, $container): string {
    $command = new PanelCheckCommand();
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output));
    $status = $command->handle(new ServerOverviewReader($gateway), new \Larena\Env\Server\SiteExtrasReader($gateway), new MetricSamples($connection),
        new WarningLedger($connection), $owners, $config, $translator, $container);
    env_assert($status === 0, 'The check succeeds.');

    return $output->fetch();
};
$connection->table(WarningLedger::TABLE)->delete();
$connection->table(MetricSamples::TABLE)->delete();
$first = $run();
env_assert(str_contains($first, 'warnings: 3, new or reminded: 3, notifications: 1, measured: yes'), 'The first check: '.$first);
$annas = $inbox->list('test:anna');
env_assert($annas->total === 1 && $annas->items[0]->source === 'env:warning:backup_none' && $annas->items[0]->href === '/admin/server/sites/demo-owned.test?tab=backups'
    && str_contains($annas->items[0]->title, 'demo-owned.test') && $annas->items[0]->status === 'warning', 'The space\'s person hears about their own site.');
env_assert($inbox->list('test:boris')->total === 0, 'Another space hears nothing of it.');
env_assert(str_contains($run(), 'new or reminded: 0, notifications: 0'), 'Five minutes on, nothing is told again.');
env_assert($connection->table(MetricSamples::TABLE)->count() === 2, 'Each check measures the server.');

echo "PanelWarningsTest passed.\n";
