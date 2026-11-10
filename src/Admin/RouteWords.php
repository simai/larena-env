<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

/**
 * The keys of the panel's words for a creation route's parts (resources/lang, `routes.*`): a step by its gateway command,
 * an open_url step by the address it opens; an input by its name. A part without words keeps the server's title.
 */
final class RouteWords
{
    private function __construct()
    {
    }

    public static function stepKey(string $command, ?string $urlFrom = null): string
    {
        if ($command === 'open_url') {
            return $urlFrom === 'restore_url' ? 'open_restore' : 'open_installer';
        }

        return str_replace([':', '-'], '_', $command);
    }

    public static function inputKey(string $name): string
    {
        return str_replace('-', '_', $name);
    }
}
