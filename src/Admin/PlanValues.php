<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * A plan's values in the panel's words (the panel's UX audit, COPY-02): each named as its field is on the form, yes and
 * no in words, and the site left out where the plan already names it. A value the panel has no name for keeps the
 * server's own.
 */
final class PlanValues
{
    /** The values a form of the panel names (`fields.*`); others keep the server's name. */
    public const array NAMED = ['verify', 'email', 'redirect', 'hsts', 'staging', 'challenge', 'wildcard', 'db', 'files', 'php',
        'backup', 'time', 'keep', 'enabled', 'name', 'value', 'login', 'pubkey'];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $args
     * @param list<string> $without the values the plan says in its own words above (a check's id, said by its title)
     * @return list<array{name: string, label: string, value: string}>
     */
    public static function of(array $args, ?string $target, array $without = []): array
    {
        $rows = [];
        foreach ($args as $name => $value) {
            if (($name === 'domain' && $value === $target) || in_array($name, $without, true)) {
                continue;
            }
            $rows[] = ['name' => $name, 'label' => in_array($name, self::NAMED, true) ? self::text('fields.'.$name) ?? $name : $name, 'value' => match ($value) {
                'yes' => self::text('yes') ?? $value,
                'no' => self::text('no') ?? $value,
                default => $value,
            }];
        }

        return $rows;
    }

    private static function text(string $key): ?string
    {
        if (!function_exists('__')) {
            return null;
        }
        $line = (string) __('larena-env::admin.'.$key);

        return $line !== 'larena-env::admin.'.$key ? $line : null;
    }
}
