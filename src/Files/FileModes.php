<?php

declare(strict_types=1);

namespace Larena\Env\Files;

/**
 * The permissions the panel offers, within the gateway's limits (Panel plan 6.1): files 0400–0664, folders 0500–0775,
 * never world-writable and never a special bit; the group keeps read, because nginx reads isolated sites through it.
 */
final class FileModes
{
    public const array FILE = ['0400', '0440', '0600', '0640', '0644', '0660', '0664'];
    public const array DIR = ['0500', '0550', '0700', '0750', '0755', '0770', '0775'];

    private function __construct()
    {
    }

    public static function allowed(string $mode, bool $directory): bool
    {
        if (preg_match('/\A0?[0-7]{3}\z/D', $mode) !== 1) {
            return false;
        }
        $value = (int) octdec($mode);
        if (($value & 0o002) !== 0) {
            return false;
        }

        return $directory
            ? $value >= 0o500 && $value <= 0o775 && ($value & 0o500) === 0o500
            : $value >= 0o400 && $value <= 0o664 && ($value & 0o400) === 0o400 && ($value & 0o111) === 0;
    }
}
