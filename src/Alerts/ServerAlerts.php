<?php

declare(strict_types=1);

namespace Larena\Env\Alerts;

/**
 * The server's alert list and where it sends them (simai-env 1.31.0). An owner gets only their sites' alerts and no
 * channel: the channel is the system level's.
 */
final readonly class ServerAlerts
{
    /**
     * @param list<ServerAlert> $alerts
     * @param array{kind: string, target_hint: ?string, last_sent_at: ?string, last_error: ?string}|null $channel
     */
    public function __construct(
        public array $alerts,
        public ?array $channel,
        public ?string $checkedAt,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $alerts = [];
        foreach (is_array($data['alerts'] ?? null) ? $data['alerts'] : [] as $alert) {
            $made = is_array($alert) ? ServerAlert::fromData($alert) : null;
            if ($made !== null) {
                $alerts[] = $made;
            }
        }
        $channel = is_array($data['channel'] ?? null) ? $data['channel'] : null;
        $text = static fn (string $key): ?string => $channel !== null && is_string($channel[$key] ?? null) && $channel[$key] !== '' ? $channel[$key] : null;

        return new self(
            $alerts,
            $channel === null ? null : ['kind' => in_array($channel['kind'] ?? null, ['webhook', 'telegram', 'off'], true) ? (string) $channel['kind'] : 'off',
                'target_hint' => $text('target_hint'), 'last_sent_at' => $text('last_sent_at'), 'last_error' => $text('last_error')],
            is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null,
        );
    }

    /** @return list<ServerAlert> the ones still to deal with: open or acknowledged, not resolved */
    public function current(): array
    {
        return array_values(array_filter($this->alerts, static fn (ServerAlert $alert): bool => $alert->state !== ServerAlert::RESOLVED));
    }
}
