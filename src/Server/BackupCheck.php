<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * The server's last check of a site's copy (simai-env 1.30.0): when, whether it passed, which copy, and whether a
 * restore test ran on its database dump.
 */
final readonly class BackupCheck
{
    public function __construct(public ?string $at, public bool $ok, public ?string $backup, public bool $restoreTest)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (!is_bool($data['ok'] ?? null)) {
            return null;
        }

        return new self(
            is_string($data['at'] ?? null) ? $data['at'] : null,
            $data['ok'],
            is_string($data['backup'] ?? null) ? $data['backup'] : null,
            ($data['restore_test'] ?? false) === true,
        );
    }
}
