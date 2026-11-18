<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * A count with its word in full, «7 дней», never «7 дн.» (the panel's audit of the files, 2026-11-18): the three Russian
 * forms, or the two English ones, as the language file names them under `units` (day_one, day_few, day_many; `rule` ru or en).
 */
final class Plural
{
    private function __construct()
    {
    }

    /** @param array{one?: string, few?: string, many?: string} $forms */
    public static function of(int $count, array $forms, string $rule = 'ru'): string
    {
        $one = $forms['one'] ?? '';
        $few = $forms['few'] ?? $one;
        $many = $forms['many'] ?? $few;
        if ($rule !== 'ru') {
            return $count."\u{00A0}".($count === 1 ? $one : $many);
        }
        $tens = $count % 100;
        $units = $count % 10;
        $word = match (true) {
            $tens >= 11 && $tens <= 14 => $many,
            $units === 1 => $one,
            $units >= 2 && $units <= 4 => $few,
            default => $many,
        };

        // The number and its word stay on one line.
        return $count."\u{00A0}".$word;
    }

    /** Days in the page's language: «7 дней», «1 день», «2 дня»; "7 days", "1 day". */
    public static function days(int $count): string
    {
        $forms = ['one' => (string) __('larena-env::admin.units.day_one'), 'few' => (string) __('larena-env::admin.units.day_few'),
            'many' => (string) __('larena-env::admin.units.day_many')];

        return self::of($count, $forms, (string) __('larena-env::admin.units.rule'));
    }
}
