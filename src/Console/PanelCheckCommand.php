<?php

declare(strict_types=1);

namespace Larena\Env\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Env\Alerts\ServerAlert;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\History\MetricSamples;
use Larena\Env\People\PersonOwners;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;
use Larena\Env\Warnings\PanelWarning;
use Larena\Env\Warnings\PanelWarnings;
use Larena\Env\Warnings\WarningLedger;
use Larena\Notification\Runtime\NotificationInbox;
use Throwable;

/**
 * The panel's own look at the server every 5 minutes, whether anyone has it open or not (Panel plan 4.5, 4.6): it
 * keeps the load the server measured, and tells people what needs attention through their notifications. The whole
 * server's people hear about everything; a person of the user level about the sites of their own space. A warning is
 * told when it appears and once a day while it lasts (WarningLedger). Without larena/notification it only measures.
 */
final class PanelCheckCommand extends Command
{
    /** Who the check asks the server as: the whole server, in the server's own log under the panel's name. */
    public const string ACTOR = 'panel:env-check';

    protected $signature = 'larena:env-check';

    protected $description = 'Measure the server and tell people what needs attention (every 5 minutes).';

    public function handle(
        ServerOverviewReader $overviews,
        SiteExtrasReader $extras,
        MetricSamples $samples,
        WarningLedger $ledger,
        PersonOwners $owners,
        Config $config,
        Translator $translator,
        Container $container,
    ): int {
        $actor = new PanelActor(self::ACTOR);
        $now = PanelWarnings::now();
        $result = $overviews->read($actor);
        $overview = $result->overview;
        if ($overview === null) {
            // A server that did not answer is not a server without problems: what was seen stays as it was.
            $this->warn('server not read: '.($result->reason ?? 'unknown'));

            return self::FAILURE;
        }
        $ownHost = parse_url((string) $config->get('app.url'), PHP_URL_HOST);
        $overview = $overview->withoutSites(is_string($ownHost) ? [$ownHost] : []);
        // A server that keeps its own load history (simai-env 1.31.0) is not sampled again; an older one is.
        $serverHistory = $overview->capabilities?->allows('self:metrics-history') === true;
        $metrics = !$serverHistory && $overview->capabilities?->allows('self:metrics') === true ? $extras->metrics($actor) : null;
        if ($metrics !== null) {
            $samples->record($metrics, $now);
        }
        $samples->prune($now);

        // A server that keeps its own alerts: each is told when it opens and when it is resolved, never reminded (the
        // server sends them to its own channel too). An older server's warnings are worked out here, reminded daily.
        $alerts = $overview->capabilities?->allows('self:alerts') === true ? $extras->alerts($actor) : null;
        if ($alerts !== null) {
            $sites = array_map(static fn ($site): string => $site->domain, $overview->sites);
            $warnings = [];
            foreach ($alerts->alerts as $alert) {
                $warning = PanelWarnings::fromAlert($alert);
                if ($warning->domain !== null && !in_array($warning->domain, $sites, true)) {
                    continue;
                }
                // Acknowledged is seen already: nobody is told again, and its open record stays so it is not news.
                $warnings[] = $alert->state === ServerAlert::RESOLVED
                    ? new PanelWarning(PanelWarnings::RESOLVED, 'success', $warning->domain, $warning->replace, $warning->subject, $alert->id, ServerAlert::RESOLVED)
                    : new PanelWarning($warning->kind, $warning->severity, $warning->domain, $warning->replace, $warning->subject, $alert->id, ServerAlert::OPEN);
            }
            $due = $ledger->due($warnings, $now, false);
        } else {
            $warnings = PanelWarnings::of($overview, $metrics, $now);
            $due = $ledger->due($warnings, $now);
        }
        $inbox = class_exists(NotificationInbox::class) && $container->bound(NotificationInbox::class)
            ? $container->make(NotificationInbox::class) : null;
        $told = 0;
        if ($inbox instanceof NotificationInbox && $due !== []) {
            $whole = $container->bound(PersistentAccessStore::class)
                ? $container->make(PersistentAccessStore::class)->subjectsWithRole(SystemRolePresetSynchronizer::ADMINISTRATOR) : [];
            $spaces = $owners->all();
            $siteOwners = [];
            foreach ($overview->sites as $site) {
                $siteOwners[$site->domain] = $site->owner;
            }
            foreach ($due as $warning) {
                $recipients = $whole;
                $owner = $warning->domain === null ? null : ($siteOwners[$warning->domain] ?? null);
                if ($owner !== null) {
                    $recipients = array_merge($recipients, array_keys(array_filter($spaces, static fn (string $space): bool => $space === $owner)));
                }
                $title = (string) $translator->get('larena-env::admin.warnings.'.$warning->kind, $warning->replace);
                // The server's alert names its own message; a resolved one says what cleared.
                foreach (array_unique($recipients) as $recipient) {
                    try {
                        $inbox->record($recipient, $warning->severity, $title, null, 'env:warning:'.$warning->kind,
                            self::href($warning, (string) $config->get('larena-env.admin.prefix', 'admin/server')), 'env:'.$warning->key());
                        $told++;
                    } catch (Throwable $error) {
                        $this->warn('not told '.$recipient.': '.$error->getMessage());
                    }
                }
            }
        }
        $this->line(sprintf('warnings: %d, new or reminded: %d, notifications: %d, measured: %s, from the server: %s',
            count($warnings), count($due), $told, $metrics === null ? 'no' : 'yes', $alerts === null ? 'no' : 'yes'));

        return self::SUCCESS;
    }

    /** Where the warning is dealt with: the site's card on its tab, or the server's overview (the panel's routes). */
    public static function href(PanelWarning $warning, string $prefix): string
    {
        $base = '/'.trim($prefix, '/');

        return $warning->domain === null ? $base.'/overview' : $base.'/sites/'.$warning->domain.'?tab='.$warning->tab();
    }
}
