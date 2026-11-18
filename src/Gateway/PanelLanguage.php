<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

/**
 * The language the server answers its words in (simai-env 1.60.0 `lang`: plans, job titles, steps, states and outcomes):
 * the panel's own, Russian or English. Codes stay codes either way. A server before 1.60.0 leaves the key unread.
 */
final class PanelLanguage
{
    public const array LANGUAGES = ['ru', 'en'];

    private static ?string $chosen = null;

    private function __construct()
    {
    }

    /** The language chosen for every request from now on (a test, a command); null goes back to the panel's locale. */
    public static function use(?string $language): void
    {
        self::$chosen = in_array($language, self::LANGUAGES, true) ? $language : null;
    }

    /** `ru` where the panel speaks Russian, else `en`. */
    public static function current(): string
    {
        if (self::$chosen !== null) {
            return self::$chosen;
        }
        try {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->bound('translator')) {
                $locale = $container->make('translator')->getLocale();

                return is_string($locale) && str_starts_with($locale, 'ru') ? 'ru' : 'en';
            }
        } catch (\Throwable) {
            // No container: the server's default words.
        }

        return 'en';
    }
}
