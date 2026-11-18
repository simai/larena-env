<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\Server\Moment;

/**
 * «Куда слать оповещения» as one card «значение · Изменить» (study part 8, PX-82): where the server sends its alerts, the
 * address it keeps (never the token), the last sending and the last error, headed by the section with «Изменить» that
 * opens the change in place (?edit=channel).
 */
final class AlertsCard
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $channel the server's channel as SiteExtrasReader::alerts reads it
     *
     * @return list<array<string, mixed>>
     */
    public static function items(?array $channel, bool $canEdit): array
    {
        $kind = is_string($channel['kind'] ?? null) ? $channel['kind'] : 'off';
        $error = is_string($channel['last_error'] ?? null) ? $channel['last_error'] : null;
        $items = [['type' => 'section', 'id' => 'channel', 'label' => self::text('now')]
            + ($canEdit ? ['action' => ['label' => self::text('card.edit'), 'href' => '?edit=channel#env-alerts-now']] : [])];
        $items[] = ['id' => 'kind', 'label' => self::text('kind'), 'value' => self::text('kinds.'.$kind),
            'status' => $error !== null ? 'error' : ($kind === 'off' ? 'warning' : 'success')] + ($error !== null ? ['hint' => self::text('state_failing')] : []);
        if (is_string($channel['target_hint'] ?? null)) {
            $items[] = ['id' => 'target', 'label' => self::text('target_hint'), 'value' => $channel['target_hint']];
        }
        $sent = is_string($channel['last_sent_at'] ?? null) ? $channel['last_sent_at'] : null;
        $items[] = ['id' => 'last-sent', 'label' => self::text('last_sent'), 'value' => $sent === null ? self::text('never') : Moment::human($sent)];
        $items[] = ['id' => 'last-error', 'label' => self::text('last_error'), 'value' => $error ?? self::text('no_error')] + ($error !== null ? ['status' => 'error'] : []);

        return $items;
    }

    private static function text(string $key): string
    {
        return (string) __('larena-env::admin.alerts.'.$key);
    }
}
