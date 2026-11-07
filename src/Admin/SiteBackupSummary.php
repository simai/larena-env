<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use DateTimeImmutable;
use Larena\Env\Alerts\ServerAlerts;
use Larena\Env\Server\Moment;
use Larena\Env\Server\SiteSummary;
use Larena\Env\Warnings\PanelWarnings;

/**
 * A site's backups said one way wherever they are shown: the sites list, the site's backups and the backups page
 * (critics of 0.4.1: the same site was «ежедневно» in the list, three warnings on its tab and «Копий нет» on the page).
 * A failed last attempt is said first, then no backup at all, then one older than two days; a redirect keeps nothing
 * worth a backup. Whether a daily backup is on is said apart, so it never stands in for a backup that does not exist.
 */
final class SiteBackupSummary
{
    public const string FAILED = 'failed';
    public const string NONE = 'none';
    public const string STALE = 'stale';
    public const string FRESH = 'fresh';
    public const string NOT_NEEDED = 'not_needed';

    /** The colour of each state, the same in the list, on the site and on the backups page. */
    public const array COLORS = [self::FAILED => 'danger', self::NONE => 'danger', self::STALE => 'danger', self::FRESH => 'success', self::NOT_NEEDED => 'neutral'];

    /** The ones in need first. */
    private const array ORDER = [self::FAILED => 0, self::NONE => 1, self::STALE => 2, self::FRESH => 3, self::NOT_NEEDED => 4];

    private function __construct()
    {
    }

    /**
     * The sites whose last backup failed, by lower-case domain, with the time the server first saw it; null when it
     * did not say. The server's description has no outcome of a backup run: only its own alerts tell a failure.
     *
     * @return array<string, ?string>
     */
    public static function failures(?ServerAlerts $alerts): array
    {
        $failed = [];
        foreach ($alerts?->current() ?? [] as $alert) {
            if ($alert->kind === 'backup_failed' && $alert->domain !== null) {
                $failed[strtolower($alert->domain)] = $alert->firstSeen;
            }
        }

        return $failed;
    }

    /**
     * The sites that keep data, the ones in need first.
     *
     * @param list<SiteSummary> $sites
     * @param array<string, ?string> $failures from failures()
     * @param callable(string, array<string, string>=): string $text the panel's line for a key under larena-env::admin.
     * @return list<array{domain: string, state: string, last: ?string, failed: ?string, scheduled: bool, words: string, color: string, schedule: string}>
     */
    public static function of(array $sites, DateTimeImmutable $now, array $failures, callable $text): array
    {
        $rows = [];
        foreach ($sites as $site) {
            $row = self::row($site, $now, $failures, $text);
            if ($row['state'] !== self::NOT_NEEDED) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => [self::ORDER[$a['state']], $a['domain']] <=> [self::ORDER[$b['state']], $b['domain']]);

        return $rows;
    }

    /**
     * One site's backup state, its words and colour, and whether its daily backup is on.
     *
     * @param array<string, ?string> $failures from failures()
     * @param callable(string, array<string, string>=): string $text
     * @return array{domain: string, state: string, last: ?string, failed: ?string, scheduled: bool, words: string, color: string, schedule: string}
     */
    public static function row(SiteSummary $site, DateTimeImmutable $now, array $failures, callable $text): array
    {
        $failed = array_key_exists(strtolower($site->domain), $failures);
        $state = in_array($site->profile, ['redirect', 'alias'], true) ? self::NOT_NEEDED : self::state($site->lastBackup, $now, $failed);
        $failedAt = $failed ? $failures[strtolower($site->domain)] : null;

        return [
            'domain' => $site->domain,
            'state' => $state,
            'last' => $site->lastBackup,
            'failed' => $failedAt,
            'scheduled' => $site->backupSchedule,
            'words' => self::words($state, $site->lastBackup, $failedAt, $text),
            'color' => self::COLORS[$state],
            'schedule' => $text($site->backupSchedule ? 'backups.state.daily' : 'backups.state.unscheduled'),
        ];
    }

    /** A failed last attempt beats no backup, which beats an old one: the first is what to deal with. */
    public static function state(?string $last, DateTimeImmutable $now, bool $failed = false): string
    {
        if ($failed) {
            return self::FAILED;
        }
        if ($last === null) {
            return self::NONE;
        }
        try {
            $age = $now->getTimestamp() - (new DateTimeImmutable($last, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return self::STALE;
        }

        return $age > PanelWarnings::BACKUP_HOURS * 3600 ? self::STALE : self::FRESH;
    }

    /**
     * The words of a state: «Копий ещё нет», «Последняя копия не удалась 07.11 03:00 UTC», «Последняя копия 05.11 03:00
     * UTC — старше двух суток», «Последняя копия 07.11 03:00 UTC».
     *
     * @param callable(string, array<string, string>=): string $text
     */
    public static function words(string $state, ?string $last, ?string $failedAt, callable $text): string
    {
        return match ($state) {
            self::FAILED => $failedAt === null ? $text('backups.state.failed_untimed') : $text('backups.state.failed', ['time' => Moment::short($failedAt)]),
            self::NONE => $text('backups.state.none'),
            self::STALE => $text('backups.state.stale', ['time' => Moment::short($last)]),
            self::FRESH => $text('backups.state.fresh', ['time' => Moment::short($last)]),
            default => $text('backups.state.not_needed'),
        };
    }
}
