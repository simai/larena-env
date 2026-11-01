<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Larena\Env\Server\Bytes;
use Larena\Env\Server\ServerMetrics;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteSummary;

/**
 * What the panel warns about without being asked (Panel plan 4.4): a disk running out, a certificate ending or
 * missing, a site answering with an error or not at all, and a site without a recent backup or a daily one. It only
 * reads what the server already answered; the overview is the person's own, so the user level is warned about its own
 * sites only, and the disks (the whole server's) only when the metrics were read, which only the system level may.
 */
final class PanelWarnings
{
    public const string DISK_FULL = 'disk_full';
    public const string CERTIFICATE_ENDING = 'certificate_ending';
    public const string CERTIFICATE_EXPIRED = 'certificate_expired';
    public const string CERTIFICATE_MISSING = 'certificate_missing';
    public const string SITE_DOWN = 'site_down';
    public const string SITE_ERROR = 'site_error';
    public const string BACKUP_NONE = 'backup_none';
    public const string BACKUP_STALE = 'backup_stale';
    public const string BACKUP_UNSCHEDULED = 'backup_unscheduled';

    public const int DISK_PERCENT = 90;
    public const int DISK_ERROR_PERCENT = 95;
    public const int CERTIFICATE_DAYS = 14;
    public const int CERTIFICATE_ERROR_DAYS = 3;
    public const int BACKUP_HOURS = 48;

    private function __construct()
    {
    }

    /**
     * Every warning of the server as the person sees it, the errors first.
     *
     * @return list<PanelWarning>
     */
    public static function of(ServerOverview $overview, ?ServerMetrics $metrics, DateTimeImmutable $now): array
    {
        $warnings = [];
        foreach ($metrics->disks ?? [] as $disk) {
            if ($disk['total'] <= 0) {
                continue;
            }
            $percent = Bytes::percent($disk['used'], $disk['total']);
            if ($disk['used'] / $disk['total'] * 100 >= self::DISK_PERCENT) {
                $warnings[] = new PanelWarning(self::DISK_FULL, $percent >= self::DISK_ERROR_PERCENT ? PanelWarning::ERROR : PanelWarning::WARNING,
                    null, ['mount' => $disk['mount'], 'percent' => (string) $percent, 'free' => Bytes::human($disk['total'] - $disk['used'])], $disk['mount']);
            }
        }
        foreach ($overview->sites as $site) {
            array_push($warnings, ...self::forSite($site, $now));
        }
        usort($warnings, static fn (PanelWarning $a, PanelWarning $b): int => ($a->severity === PanelWarning::ERROR ? 0 : 1) <=> ($b->severity === PanelWarning::ERROR ? 0 : 1));

        return $warnings;
    }

    /**
     * One site's warnings: whether it answers, its certificate and its backups.
     *
     * @return list<PanelWarning>
     */
    public static function forSite(SiteSummary $site, DateTimeImmutable $now): array
    {
        $domain = $site->domain;
        $warnings = [];
        // A site the server could not reach, or that answered with a server error.
        if ($site->answersChecked && $site->answers === null) {
            $warnings[] = new PanelWarning(self::SITE_DOWN, PanelWarning::ERROR, $domain, ['domain' => $domain]);
        } elseif ($site->answers !== null && $site->answers >= 500) {
            $warnings[] = new PanelWarning(self::SITE_ERROR, PanelWarning::ERROR, $domain, ['domain' => $domain, 'code' => (string) $site->answers]);
        }
        if ($site->certificateDaysLeft !== null && $site->certificateDaysLeft < 0) {
            $warnings[] = new PanelWarning(self::CERTIFICATE_EXPIRED, PanelWarning::ERROR, $domain, ['domain' => $domain]);
        } elseif ($site->certificateDaysLeft !== null && $site->certificateDaysLeft < self::CERTIFICATE_DAYS) {
            $warnings[] = new PanelWarning(self::CERTIFICATE_ENDING,
                $site->certificateDaysLeft <= self::CERTIFICATE_ERROR_DAYS ? PanelWarning::ERROR : PanelWarning::WARNING,
                $domain, ['domain' => $domain, 'days' => (string) $site->certificateDaysLeft]);
        } elseif ($site->ssl !== 'none' && $site->certificateDaysLeft === null) {
            // The site is meant to be served over https, and nginx serves no certificate the server could read.
            $warnings[] = new PanelWarning(self::CERTIFICATE_MISSING, PanelWarning::WARNING, $domain, ['domain' => $domain]);
        }
        // A redirect keeps nothing worth a backup.
        if ($site->profile !== 'redirect') {
            $backup = self::backupWarning($site, $now);
            if ($backup !== null) {
                $warnings[] = $backup;
            }
        }

        return $warnings;
    }

    /**
     * No backup and no daily one; a newest backup older than two days; recent backups made by hand, with no daily one.
     * A site whose daily backup is set and that has none yet gets its first one on the schedule's next run.
     */
    private static function backupWarning(SiteSummary $site, DateTimeImmutable $now): ?PanelWarning
    {
        $domain = $site->domain;
        $last = self::time($site->lastBackup);
        if ($last === null) {
            return $site->backupSchedule ? null : new PanelWarning(self::BACKUP_NONE, PanelWarning::WARNING, $domain, ['domain' => $domain]);
        }
        if ($now->getTimestamp() - $last->getTimestamp() > self::BACKUP_HOURS * 3600) {
            return new PanelWarning(self::BACKUP_STALE, PanelWarning::WARNING, $domain, ['domain' => $domain, 'time' => (string) $site->lastBackup]);
        }

        return $site->backupSchedule ? null : new PanelWarning(self::BACKUP_UNSCHEDULED, PanelWarning::WARNING, $domain, ['domain' => $domain]);
    }

    /** The server writes its times in UTC, with or without the zone. */
    private static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * The warnings of one site, for its card.
     *
     * @param list<PanelWarning> $warnings
     *
     * @return list<PanelWarning>
     */
    public static function ofSite(array $warnings, string $domain): array
    {
        return array_values(array_filter($warnings, static fn (PanelWarning $warning): bool => $warning->domain === $domain));
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
