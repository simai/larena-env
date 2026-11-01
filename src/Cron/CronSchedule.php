<?php

declare(strict_types=1);

namespace Larena\Env\Cron;

/**
 * A cron schedule as a person reads it, and the choices the panel offers instead of five fields. A schedule the panel
 * has no words for is shown as it is; the server checks every schedule again (no more often than every minute).
 */
final class CronSchedule
{
    /** The schedules offered in the form, each with its words (`larena-env::admin.cron.presets.<key>`). */
    public const array PRESETS = [
        'minute' => '* * * * *',
        'five_minutes' => '*/5 * * * *',
        'fifteen_minutes' => '*/15 * * * *',
        'hourly' => '0 * * * *',
        'daily' => '0 3 * * *',
        'weekly' => '0 3 * * 1',
        'monthly' => '0 3 1 * *',
    ];

    private function __construct()
    {
    }

    /** Five fields of a cron schedule: numbers, `*`, steps, ranges, lists, or names of months and days. */
    public static function valid(string $schedule): bool
    {
        return preg_match('/\A[0-9A-Za-z*\/,-]{1,40}(?: [0-9A-Za-z*\/,-]{1,40}){4}\z/D', $schedule) === 1;
    }

    /**
     * The words for the schedule: a preset's key with no replacements, or `daily_at`/`hourly_at`/`weekly_at` with the
     * time, or null when there are none and the schedule is shown as written.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function words(string $schedule): ?array
    {
        $preset = array_search($schedule, self::PRESETS, true);
        if (is_string($preset)) {
            return [$preset, []];
        }
        $fields = explode(' ', $schedule);
        if (count($fields) !== 5) {
            return null;
        }
        [$minute, $hour, $day, $month, $weekday] = $fields;
        $number = static fn (string $value, int $most): bool => preg_match('/\A[0-9]{1,2}\z/D', $value) === 1 && (int) $value <= $most;
        if ($number($minute, 59) && $hour === '*' && $day === '*' && $month === '*' && $weekday === '*') {
            return ['hourly_at', ['minute' => sprintf('%02d', (int) $minute)]];
        }
        if ($number($minute, 59) && $number($hour, 23) && $day === '*' && $month === '*') {
            $time = sprintf('%02d:%02d', (int) $hour, (int) $minute);
            if ($weekday === '*') {
                return ['daily_at', ['time' => $time]];
            }
            if ($number($weekday, 7)) {
                return ['weekly_at', ['time' => $time, 'weekday' => (string) ((int) $weekday % 7)]];
            }
        }

        return null;
    }
}
