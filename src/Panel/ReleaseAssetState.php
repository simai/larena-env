<?php

declare(strict_types=1);

namespace Larena\Env\Panel;

/**
 * The admin finds the Framework bundle through two small records under storage/. storage/ is shared between releases
 * and starts empty, while the bundle itself lives in the release's public/, so each release carries its records and puts
 * them in place when they differ: after an install, an upgrade or a rollback.
 */
final class ReleaseAssetState
{
    private const string ACTIVE = 'sf-active.json';
    private const string RECEIPT = 'sf-route-receipt.json';

    private function __construct()
    {
    }

    public static function sync(string $releaseDirectory, string $storageDirectory): bool
    {
        $active = @file_get_contents($releaseDirectory.'/'.self::ACTIVE);
        $receipt = @file_get_contents($releaseDirectory.'/'.self::RECEIPT);
        if ($active === false || $receipt === false) {
            return false;
        }
        if (@file_get_contents($storageDirectory.'/'.self::ACTIVE) === $active && is_file($storageDirectory.'/'.self::RECEIPT)) {
            return true;
        }
        if (!is_dir($storageDirectory) && !@mkdir($storageDirectory, 0775, true) && !is_dir($storageDirectory)) {
            return false;
        }

        // The receipt first: the state is what readers compare it with.
        return self::write($storageDirectory.'/'.self::RECEIPT, $receipt) && self::write($storageDirectory.'/'.self::ACTIVE, $active);
    }

    private static function write(string $path, string $bytes): bool
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $bytes) !== strlen($bytes) || !@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
