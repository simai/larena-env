<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/** A time the server wrote (ISO, UTC) as a person reads it: 2026-01-01 00:00 UTC. */
final class Moment
{
    private function __construct()
    {
    }

    public static function human(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        try {
            $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return $value;
        }

        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC';
    }
}
