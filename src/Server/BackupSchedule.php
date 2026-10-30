<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** The daily backup a site has, when it has one: when it runs, how many are kept and where copies go. */
final readonly class BackupSchedule
{
    public function __construct(public ?string $time, public ?int $keep, public ?string $destination)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        return new self(
            time: is_string($data['time'] ?? null) ? $data['time'] : null,
            keep: is_int($data['keep'] ?? null) ? $data['keep'] : null,
            destination: is_string($data['dest'] ?? null) ? $data['dest'] : null,
        );
    }
}
