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
    /** The most jobs the server keeps for a site (simai-env 1.34.0). */
    public const int MAX_JOBS = 50;
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

    /** The ranges of the five fields, as the server checks them (simai-env 1.34.0): minute, hour, day, month, weekday. */
    private const array RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /**
     * Five cron fields of numbers only, as the server takes them: `*`, a number, a range and a step, lists of them,
     * each within its field's range; no names, no `@daily` or `@reboot`. "61 * * * *" is refused before any job.
     */
    public static function valid(string $schedule): bool
    {
        $fields = explode(' ', $schedule);
        if (count($fields) !== 5 || in_array('', $fields, true)) {
            return false;
        }
        foreach ($fields as $index => $field) {
            [$low, $high] = self::RANGES[$index];
            foreach (explode(',', $field) as $part) {
                if (preg_match('/\A(?:\*|([0-9]{1,2})(?:-([0-9]{1,2}))?)(?:\/([0-9]{1,2}))?\z/D', $part, $m) !== 1) {
                    return false;
                }
                $start = ($m[1] ?? '') === '' ? null : (int) $m[1];
                $end = ($m[2] ?? '') === '' ? null : (int) $m[2];
                $step = ($m[3] ?? '') === '' ? null : (int) $m[3];
                if (($start !== null && ($start < $low || $start > $high)) || ($end !== null && ($end < (int) $start || $end > $high))
                    || ($step !== null && ($step < 1 || $step > $high))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** One printable line of up to 1000 characters, not starting with "-" nor padded with spaces, as the server takes it. */
    public static function commandValid(string $command): bool
    {
        return $command !== '' && mb_strlen($command) <= 1000 && preg_match('/\A[^\p{C}]+\z/uD', $command) === 1
            && !str_starts_with($command, '-') && trim($command) === $command;
    }

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
