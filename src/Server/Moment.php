<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * A time the server wrote (ISO or systemd's «Thu 2026-01-01 00:00:00 UTC», in UTC) as a person reads it, the way the
 * whole admin shows times: in the site's time zone, «01.01.2026 03:00», with «UTC» named while the site keeps UTC
 * (owner decision 2026-11-05: the panel's words and data as the admin's). Outside the admin: «01.01.2026 00:00 UTC».
 */
final class Moment
{
    private function __construct()
    {
    }

    public static function human(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        try {
            $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return $value;
        }

        $settings = \Larena\Admin\Settings\AdminSiteSettings::class;
        if (class_exists($settings) && class_exists(\Illuminate\Container\Container::class)) {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->bound($settings)) {
                try {
                    $shown = $container->make($settings)->moment($time);
                    if ($shown !== '') {
                        return $shown;
                    }
                } catch (\Throwable) {
                    // The site's zone could not be read: the time in UTC, named.
                }
            }
        }

        return $time->setTimezone(new DateTimeZone('UTC'))->format('d.m.Y H:i').' UTC';
    }

    /**
     * The panel's own time zone (the admin's site.timezone setting, UTC until one is chosen), as sf-time's time-zone, so
     * a time reads the same on every panel screen; null outside the admin or when it cannot be read.
     */
    public static function zone(): ?string
    {
        $settings = \Larena\Admin\Settings\AdminSiteSettings::class;
        if (!class_exists($settings) || !class_exists(\Illuminate\Container\Container::class)) {
            return null;
        }
        $container = \Illuminate\Container\Container::getInstance();
        if (!$container->bound($settings)) {
            return null;
        }
        try {
            $zone = $container->make($settings)->timezone();
        } catch (\Throwable) {
            return null;
        }

        return $zone !== '' ? $zone : null;
    }

    /**
     * The moment as ISO 8601 with its offset, for the Framework's sf-time to say it in the reader's own time (PX-6), or
     * null when the server wrote none or not a time.
     */
    public static function iso(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * How long ago, as the lists say a time (owner decision 2026-11-17, C183): «только что», «12 мин назад», «2 ч назад»,
     * «вчера», «3 дн. назад», then the short date. It needs no time zone, so it is the reader's as much as the server's;
     * the exact time goes in the tooltip. Null when the server wrote no time.
     *
     * @param callable(string, array<string, string>=): string $text the panel's line for a key under larena-env::admin.
     */
    public static function ago(?string $value, DateTimeImmutable $now, callable $text): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $seconds = $now->getTimestamp() - (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            return null;
        }
        $minutes = intdiv(max(0, $seconds), 60);

        return match (true) {
            $minutes < 1 => $text('ago.now'),
            $minutes < 60 => $text('ago.minutes', ['count' => (string) $minutes]),
            $minutes < 24 * 60 => $text('ago.hours', ['count' => (string) intdiv($minutes, 60)]),
            $minutes < 48 * 60 => $text('ago.yesterday'),
            $minutes < 7 * 24 * 60 => $text('ago.days', ['count' => (string) intdiv($minutes, 24 * 60)]),
            default => explode(' ', self::short($value, (int) $now->format('Y')))[0],
        };
    }

    /** The same, shorter where a column is narrow: this year's date without its year, «05.11 03:30 UTC». */
    public static function short(?string $value, ?int $year = null): string
    {
        $year ??= (int) gmdate('Y');

        return (string) preg_replace('/\b(\d{2}\.\d{2})\.'.$year.'\b/', '$1', self::human($value));
    }
}
