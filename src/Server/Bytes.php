<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** A size in bytes as a person reads it: 2.8 GB, 46.8 MB. */
final class Bytes
{
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    private function __construct()
    {
    }

    public static function human(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count(self::UNITS) - 1) {
            $size /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($size, $size < 10 ? 1 : 0, '.', '')).' '.self::UNITS[$unit];
    }

    /** How full, in whole percent. */
    public static function percent(int $used, int $total): int
    {
        return $total > 0 ? (int) round($used * 100 / $total) : 0;
    }
}
