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
}
