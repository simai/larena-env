<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use DateTimeImmutable;
use Larena\Env\Server\SiteSummary;
use Larena\Env\Warnings\PanelWarnings;

/** Each site's last backup for the backups page: fresh, older than two days, or none (critics 2026-11-07). */
final class SiteBackupSummary
{
    public const string FRESH = 'fresh';
    public const string STALE = 'stale';
    public const string NONE = 'none';

    private function __construct()
    {
    }

    /**
     * The sites that keep data, the ones in need first.
     *
     * @param list<SiteSummary> $sites
     * @return list<array{domain: string, state: string, last: ?string, scheduled: bool}>
     */
    public static function of(array $sites, DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($sites as $site) {
            if (in_array($site->profile, ['redirect', 'alias'], true)) {
                continue;
            }
            $rows[] = ['domain' => $site->domain, 'state' => self::state($site->lastBackup, $now), 'last' => $site->lastBackup, 'scheduled' => $site->backupSchedule];
        }
        $order = [self::NONE => 0, self::STALE => 1, self::FRESH => 2];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['state']], $a['domain']] <=> [$order[$b['state']], $b['domain']]);

        return $rows;
    }

    public static function state(?string $last, DateTimeImmutable $now): string
    {
        if ($last === null) {
            return self::NONE;
        }
        try {
            $age = $now->getTimestamp() - (new DateTimeImmutable($last))->getTimestamp();
        } catch (\Exception) {
            return self::STALE;
        }

        return $age > PanelWarnings::BACKUP_HOURS * 3600 ? self::STALE : self::FRESH;
    }
}
