<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * The daily backup a site has, when it has one: when it runs, how many are kept and whether the server checks each new
 * copy (simai-env 1.30.0; null when the server does not say). Where the copies go is the host's path and is not kept.
 */
final readonly class BackupSchedule
{
    public function __construct(public ?string $time, public ?int $keep, public ?bool $verify = null)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        return new self(
            time: is_string($data['time'] ?? null) ? $data['time'] : null,
            // The server writes the number as a number or as its digits.
            keep: is_int($data['keep'] ?? null) ? $data['keep'] : (is_string($data['keep'] ?? null) && ctype_digit($data['keep']) ? (int) $data['keep'] : null),
            verify: is_bool($data['verify'] ?? null) ? $data['verify'] : null,
        );
    }
}
