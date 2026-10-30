<?php

declare(strict_types=1);

namespace Larena\Env\Panel;

/**
 * What a release of the panel assumes when the server's .env does not say otherwise. Called between loading .env and
 * reading the configuration, so that the packages' configuration sees the same values as a filled-in .env. simai-env
 * writes only the database and the setup token there; the rest of the edition travels with the release.
 */
final class PanelEnvironment
{
    /** @var array<string, string> */
    public const array DEFAULTS = [
        'APP_NAME' => 'Larena Panel',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'mysql',
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'QUEUE_CONNECTION' => 'sync',
        'LARENA_AUTH_ADMIN_ENTRY_ROUTES' => 'true',
        'LARENA_AUTH_ADMIN_LOGIN_MODE' => 'persistent',
        'LARENA_AUTH_ADMIN_REDIRECT' => '/admin/server',
        'LARENA_AUTH_BROWSER_SETUP' => 'true',
        'LARENA_AUTH_LOCAL_TESTING_LOGIN' => 'false',
        'LARENA_AUTH_LOCAL_TESTING_DEMO_CREDENTIALS' => 'false',
        'LARENA_ADMIN_CANONICAL_ENTRY_ROUTES' => 'true',
        'LARENA_ADMIN_INTERNAL_PREVIEW_ROUTES' => 'false',
        'LARENA_ADMIN_WEB_INSTALL' => 'false',
        'LARENA_ADMIN_SITE_PAGES' => 'false',
    ];

    private function __construct()
    {
    }

    public static function apply(string $envFile): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            if (self::read($key) === null) {
                self::set($key, $value);
            }
        }
        if ((self::read('APP_KEY') ?? '') === '') {
            $key = 'base64:'.base64_encode(random_bytes(32));
            // Where the file cannot be written yet (during a deploy), nothing is invented: the next start tries again.
            if ((new EnvFile($envFile))->fill('APP_KEY', $key)) {
                self::set('APP_KEY', $key);
            }
        }
    }

    private static function read(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }

    private static function set(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
