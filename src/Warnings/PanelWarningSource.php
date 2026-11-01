<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use DateTimeImmutable;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Server\ServerMetrics;
use Larena\Env\Server\ServerOverview;
use Larena\Env\Server\SiteExtrasReader;

/**
 * Where the panel's warnings come from. A server that keeps its own alerts (simai-env 1.31.0, `self:alerts`) is asked
 * for them: it checks every 5 minutes, also while the panel is closed or down, and its list is the one people
 * acknowledge. An older server's warnings are worked out by the panel from what it answered (PanelWarnings::of).
 */
final readonly class PanelWarningSource
{
    public function __construct(private SiteExtrasReader $extras)
    {
    }

    /**
     * The person's warnings, the errors first, and whether the server gave them.
     *
     * @return array{warnings: list<PanelWarning>, fromServer: bool}
     */
    public function of(PanelActor $actor, ServerOverview $overview, ?ServerMetrics $metrics, DateTimeImmutable $now): array
    {
        if ($overview->capabilities?->allows('self:alerts') === true) {
            $alerts = $this->extras->alerts($actor);
            if ($alerts !== null) {
                $sites = array_map(static fn ($site): string => $site->domain, $overview->sites);

                return ['warnings' => PanelWarnings::fromAlerts($alerts, $sites, $actor->owner === null), 'fromServer' => true];
            }
        }

        return ['warnings' => PanelWarnings::of($overview, $metrics, $now), 'fromServer' => false];
    }

    /**
     * The same, for one site's card.
     *
     * @return array{warnings: list<PanelWarning>, fromServer: bool}
     */
    public function ofSite(PanelActor $actor, ServerOverview $overview, string $domain, DateTimeImmutable $now): array
    {
        if ($overview->capabilities?->allows('self:alerts') === true) {
            $alerts = $this->extras->alerts($actor);
            if ($alerts !== null) {
                $warnings = array_values(array_filter(PanelWarnings::fromAlerts($alerts, [$domain], false),
                    static fn (PanelWarning $warning): bool => $warning->domain !== null && strcasecmp($warning->domain, $domain) === 0));

                return ['warnings' => $warnings, 'fromServer' => true];
            }
        }
        foreach ($overview->sites as $summary) {
            if (strcasecmp($summary->domain, $domain) === 0) {
                return ['warnings' => PanelWarnings::forSite($summary, $now), 'fromServer' => false];
            }
        }

        return ['warnings' => [], 'fromServer' => false];
    }
}
