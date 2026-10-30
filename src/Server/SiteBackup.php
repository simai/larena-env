<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * One data backup of a site. The gateway names the directory it lies in; the screen shows only its name, because a
 * host path is of no use in the panel and is left out of the typed data, as everywhere else here.
 */
final readonly class SiteBackup
{
    /** @param list<string> $parts */
    public function __construct(
        public string $name,
        public ?string $createdAt,
        public ?int $sizeBytes,
        public array $parts,
        public bool $checksums,
        public bool $encrypted,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $path = $data['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return null;
        }
        $parts = [];
        foreach (is_array($data['parts'] ?? null) ? $data['parts'] : [] as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            }
        }

        return new self(
            name: basename($path),
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
            sizeBytes: is_int($data['size_bytes'] ?? null) ? $data['size_bytes'] : null,
            parts: $parts,
            checksums: ($data['checksums'] ?? false) === true,
            encrypted: ($data['encrypted'] ?? false) === true,
        );
    }
}
