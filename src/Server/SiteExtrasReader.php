<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Alerts\ServerAlerts;
use Larena\Env\Contracts\PanelGateway;
use Larena\Env\History\MetricHistory;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Gateway\PanelRequest;
use Larena\Env\Update\UpdatePlan;
use UnexpectedValueException;

/**
 * The reads a site's tabs and the server's overview add (Panel plan stage 3): PHP settings and extensions, databases,
 * SFTP accounts and the server's load. Each is asked only when the server's gateway has it, and a refusal or a
 * failure is null: the tab then says it cannot show this, never guesses.
 */
final readonly class SiteExtrasReader
{
    public function __construct(private PanelGateway $gateway)
    {
    }

    /** @return list<PhpSetting>|null */
    public function phpSettings(PanelActor $actor, string $domain): ?array
    {
        return $this->list($actor, 'site:php-ini-list', ['domain' => $domain], 'settings', PhpSetting::fromData(...));
    }

    /** @return list<PhpExtension>|null */
    public function phpExtensions(PanelActor $actor, string $php): ?array
    {
        return $this->list($actor, 'php:extensions', ['php' => $php], 'extensions', PhpExtension::fromData(...));
    }

    /** @return list<SiteDatabase>|null the site's databases, from the server's list (whose own owner scope applies) */
    public function databases(PanelActor $actor, string $domain): ?array
    {
        $all = $this->list($actor, 'db:list', [], 'databases', SiteDatabase::fromData(...));

        return $all === null ? null : array_values(array_filter($all, static fn (SiteDatabase $db): bool => $db->site === $domain));
    }

    /** @return list<SftpAccount>|null the site's own SFTP accounts, from the server's list (whose own owner scope applies) */
    public function sftpAccounts(PanelActor $actor, string $domain): ?array
    {
        $all = $this->list($actor, 'access:list', [], 'accesses', SftpAccount::fromData(...));

        return $all === null ? null : array_values(array_filter($all, static fn (SftpAccount $account): bool => $account->site === $domain));
    }

    public function metrics(PanelActor $actor): ?ServerMetrics
    {
        $data = $this->data($actor, 'self:metrics', []);

        return $data === null ? null : ServerMetrics::fromData($data);
    }

    /** The server's own alerts (simai-env 1.31.0): an owner's are their sites' only. */
    public function alerts(PanelActor $actor): ?ServerAlerts
    {
        $data = $this->data($actor, 'self:alerts', []);

        return $data === null ? null : ServerAlerts::fromData($data);
    }

    /**
     * The load the server kept itself (simai-env 1.31.0, administrator only), ready to draw.
     *
     * @param 'day'|'week'|'month' $period
     */
    public function metricsHistory(PanelActor $actor, string $period): ?MetricHistory
    {
        $data = $this->data($actor, 'self:metrics-history', ['period' => $period]);

        return $data === null ? null : MetricHistory::fromServer($period, $data);
    }

    /**
     * What a safe update of simai-env or of the panel would change (simai-env 1.32.0, administrator only).
     *
     * @param 'env'|'panel' $target
     */
    public function updatePlan(PanelActor $actor, string $target): ?UpdatePlan
    {
        $data = $this->data($actor, 'self:update-plan', ['target' => $target]);

        return $data === null ? null : UpdatePlan::fromData($data);
    }

    /** @return list<string>|null the last lines of a service's journal (Panel plan 5.1, administrator only) */
    /** @return list<HostService>|null the server's services and their state (simai-env 1.33.0), the administrator's */
    public function services(PanelActor $actor): ?array
    {
        return $this->list($actor, 'service:list', [], 'services', HostService::fromData(...));
    }

    public function serviceLog(PanelActor $actor, string $name, int $lines = 100): ?array
    {
        $data = $this->data($actor, 'service:log', ['name' => $name, 'lines' => (string) $lines]);
        if ($data === null) {
            return null;
        }
        $log = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        return array_values(array_filter($log, 'is_string'));
    }

    /** @return list<SiteProfile>|null the kinds of site the server makes, with their arguments (Panel plan 5.5) */
    public function profiles(PanelActor $actor): ?array
    {
        return $this->list($actor, 'self:profiles', [], 'profiles', SiteProfile::fromData(...));
    }

    /** @return list<\Larena\Env\Cron\CronJob>|null a site's own scheduled jobs (Panel plan 5.4); an owner's own sites only */
    public function cronJobs(PanelActor $actor, string $domain): ?array
    {
        return $this->list($actor, 'cron:list', ['domain' => $domain], 'jobs', \Larena\Env\Cron\CronJob::fromData(...));
    }

    /** @return list<\Larena\Env\Cron\CronRun>|null a job's last runs, the newest first */
    public function cronRuns(PanelActor $actor, string $id): ?array
    {
        $runs = $this->list($actor, 'cron:job-runs', ['id' => $id], 'runs', \Larena\Env\Cron\CronRun::fromData(...));

        if ($runs !== null) {
            usort($runs, static fn (\Larena\Env\Cron\CronRun $a, \Larena\Env\Cron\CronRun $b): int => strcmp((string) $b->startedAt, (string) $a->startedAt));
        }

        return $runs;
    }

    /** The firewall, its rules and blocks, the changes waiting to be kept, and who reaches the panel (5.2, 5.3). */
    public function firewall(PanelActor $actor): ?\Larena\Env\Firewall\FirewallStatus
    {
        $data = $this->data($actor, 'firewall:status', []);

        return $data === null ? null : \Larena\Env\Firewall\FirewallStatus::fromData($data);
    }

    /**
     * @template T
     *
     * @param array<string, string> $args
     * @param callable(array<array-key, mixed>): (T|null) $make
     *
     * @return list<T>|null
     */
    private function list(PanelActor $actor, string $command, array $args, string $key, callable $make): ?array
    {
        $data = $this->data($actor, $command, $args);
        if ($data === null) {
            return null;
        }
        $items = is_array($data[$key] ?? null) ? $data[$key] : (array_is_list($data) ? $data : []);
        $list = [];
        foreach ($items as $item) {
            $made = is_array($item) ? $make($item) : null;
            if ($made !== null) {
                $list[] = $made;
            }
        }

        return $list;
    }

    /**
     * @param array<string, string> $args
     *
     * @return array<array-key, mixed>|null
     */
    private function data(PanelActor $actor, string $command, array $args): ?array
    {
        try {
            $answer = $this->gateway->ask(PanelRequest::read($actor, $command, $args));
        } catch (PanelRefused|UnexpectedValueException) {
            return null;
        }

        return SiteDetailsReader::failureOf($answer) === null ? $answer->data : null;
    }
}
