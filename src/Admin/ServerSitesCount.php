<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use DateTimeImmutable;
use Larena\Env\Server\SiteSummary;
use Larena\Env\Warnings\PanelWarnings;

/**
 * The sites in one line on the overview (critics of 0.4.1: the dashboard said nothing about them): how many, how many
 * work, how many have no fresh copy and how many reachable names go without HTTPS.
 */
final class ServerSitesCount
{
    private function __construct()
    {
    }

    /**
     * A site has no fresh copy when its newest backup is older than PanelWarnings::BACKUP_HOURS (or there is none) or the
     * server's last check of a copy failed; a redirect keeps nothing to copy. HTTPS is counted for public names only: a
     * local one (.test, .localhost) gets no certificate.
     *
     * @param list<SiteSummary> $sites
     * @return array{count: int, works: int, stale: int, plain: int}
     */
    public static function of(array $sites, DateTimeImmutable $now): array
    {
        $counts = ['count' => count($sites), 'works' => 0, 'stale' => 0, 'plain' => 0];
        foreach ($sites as $site) {
            if (SiteState::of($site->answersChecked, $site->answers) === 'works') {
                $counts['works']++;
            }
            if (!in_array($site->profile, ['redirect', 'alias'], true) && (!self::fresh($site->lastBackup, $now) || $site->lastVerify?->ok === false)) {
                $counts['stale']++;
            }
            if (!\Larena\Env\Security\SecurityFindings::local($site->domain) && ($site->ssl === 'none' || ($site->certificateDaysLeft !== null && $site->certificateDaysLeft < 0))) {
                $counts['plain']++;
            }
        }

        return $counts;
    }

    private static function fresh(?string $last, DateTimeImmutable $now): bool
    {
        if ($last === null || $last === '') {
            return false;
        }
        try {
            $at = new DateTimeImmutable($last, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        return $now->getTimestamp() - $at->getTimestamp() <= PanelWarnings::BACKUP_HOURS * 3600;
    }
}
