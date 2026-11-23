<?php

declare(strict_types=1);

namespace Larena\Env\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Larena\Env\Alerts\ServerAlert;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\History\MetricSamples;
use Larena\Env\Mail\WarningLetters;
use Larena\Env\People\PanelRecipients;
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
 * told when it appears and once a day while it lasts (WarningLedger), in the same notification; when it ends, that
 * notification says so itself — «… → снова работает», read on its own — and no second one is added (study 2026-11-17,
 * PX-40). Each names what it is about (the site, or «Сервер») for the bell's groups. Without larena/notification it
 * only measures. Who is told — by the bell and by mail alike — is PanelRecipients': only active people who still hold
 * the right to see the server or the site; a link of a removed person, or of one who lost the right, is dropped on
 * every run and written to the audit log.
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
        PanelRecipients $recipients,
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
        // The menu's rows read the same description: kept for the pages, which then do not ask the server for it again.
        $store = $container->bound('cache') ? $container->make('cache')->store() : null;
        \Larena\Env\Navigation\ListedCommands::keep($store instanceof \Illuminate\Contracts\Cache\Repository ? $store : null, $overview->capabilities);
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
        // The notifications of conditions that ended: closed in place (dedupe key => the kind it was).
        $ended = [];
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
                    : new PanelWarning($warning->kind, $warning->severity, $warning->domain, $warning->replace, $warning->subject, $alert->id, ServerAlert::OPEN,
                        null, null, $warning->title, $warning->line);
                if ($alert->state === ServerAlert::RESOLVED) {
                    $ended['env:alert:'.$alert->id.':open'] = $warning->kind;
                }
            }
            // A resolved alert ends its own notification instead of adding one (PX-40).
            $due = array_values(array_filter($ledger->due($warnings, $now, false),
                static fn (PanelWarning $warning): bool => $warning->kind !== PanelWarnings::RESOLVED));
        } else {
            $warnings = PanelWarnings::of($overview, $metrics, $now);
            // What the ledger knew and is no longer seen has ended (WarningLedger forgets it in due()).
            foreach ($ledger->gone($warnings) as $key => $kind) {
                $ended['env:'.$key] = $kind;
            }
            $due = $ledger->due($warnings, $now);
        }
        // Links of people who were removed or lost the right go first, told or not (auditor msg-3d61bd36, point 1).
        $recipients->sweep();
        $inbox = class_exists(NotificationInbox::class) && $container->bound(NotificationInbox::class)
            ? $container->make(NotificationInbox::class) : null;
        $told = 0;
        $mailed = 0;
        // The server's warnings by mail too, as «Что слать на почту» says (owner decision C335): urgent by default.
        $letters = $container->bound(WarningLetters::class) ? $container->make(WarningLetters::class) : null;
        $byMail = $letters instanceof WarningLetters && ($letters->takes(true) || $letters->takes(false));
        if (($inbox instanceof NotificationInbox || $byMail) && $due !== []) {
            $siteOwners = [];
            foreach ($overview->sites as $site) {
                $siteOwners[$site->domain] = $site->owner;
            }
            $prefix = (string) $config->get('larena-env.admin.prefix', 'admin/server');
            foreach ($due as $warning) {
                $people = $recipients->of($warning->domain === null ? null : ($siteOwners[$warning->domain] ?? null));
                // The server's own Russian title and line where it gave them (simai-env 1.63.0), else the panel's words.
                $title = PanelWarnings::said($warning, static fn (string $key, array $replace): string => (string) $translator->get('larena-env::admin.'.$key, $replace),
                    $translator->getLocale());
                $line = PanelWarnings::line($warning, $translator->getLocale());
                // What it is about: the site, or the server for the bell's group «Сервер».
                $object = $warning->domain ?? (string) $translator->get('larena-env::admin.warnings.bar_server');
                foreach ($inbox instanceof NotificationInbox ? $people : [] as $recipient) {
                    try {
                        $inbox->record($recipient, $warning->severity, $title, $line, 'env:warning:'.$warning->kind,
                            self::href($warning, $prefix), 'env:'.$warning->key(), false, $object);
                        $told++;
                    } catch (Throwable $error) {
                        $this->warn('not told '.$recipient.': '.$error->getMessage());
                    }
                }
                $urgent = $warning->severity === PanelWarning::ERROR;
                if ($byMail && $letters->takes($urgent)) {
                    $mailed += $letters->queue($people,
                        (string) $translator->get('larena-env::mail.letters.warning.subject_'.($urgent ? 'urgent' : 'warning'), ['title' => $title]),
                        (string) $translator->get('larena-env::mail.letters.warning.body', [
                            'title' => $title,
                            'line' => $line === null ? '' : "\n".$line,
                            'server' => $object,
                            'url' => rtrim((string) $config->get('app.url'), '/').self::href($warning, $prefix),
                        ]));
                }
            }
        }
        $resolved = 0;
        if ($inbox instanceof NotificationInbox) {
            foreach ($ended as $key => $kind) {
                $resolved += $inbox->resolve($key, (string) $translator->get('larena-env::admin.warnings.resolutions.'.self::resolution($kind)));
            }
        }
        $this->line(sprintf('warnings: %d, new or reminded: %d, notifications: %d, resolved: %d, measured: %s, from the server: %s, letters: %d',
            count($warnings), count($due), $told, $resolved, $metrics === null ? 'no' : 'yes', $alerts === null ? 'no' : 'yes', $mailed));

        return self::SUCCESS;
    }

    /** Which words say how a condition of this kind ended (larena-env::admin.warnings.resolutions.*). */
    public static function resolution(string $kind): string
    {
        return match (true) {
            in_array($kind, [PanelWarnings::SITE_DOWN, PanelWarnings::SITE_ERROR, PanelWarnings::SITE_UNAVAILABLE], true) => 'site',
            str_starts_with($kind, 'backup_') => 'backup',
            in_array($kind, [PanelWarnings::DISK_FULL, PanelWarnings::DISK_ALERT], true) => 'disk',
            str_starts_with($kind, 'certificate_') => 'certificate',
            $kind === PanelWarnings::SWAP_FULL => 'swap',
            $kind === PanelWarnings::PANEL_DOWN => 'panel',
            default => 'other',
        };
    }

    /** Where the warning is dealt with: the site's card on its tab, or the server's overview (the panel's routes). */
    public static function href(PanelWarning $warning, string $prefix): string
    {
        $base = '/'.trim($prefix, '/');

        return $warning->domain === null ? $base.'/overview' : $base.'/sites/'.$warning->domain.'?tab='.$warning->tab();
    }
}
