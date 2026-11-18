<?php

declare(strict_types=1);

namespace Larena\Env\Files;

/** One entry of a site's trash (simai-file-trash/1, simai-env 1.57.0): what was removed, from where, until when it stays. */
final readonly class TrashEntry
{
    public const string ID = '/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{6}\z/D';

    public function __construct(
        public string $id,
        public ?string $path,
        public string $name,
        public string $type,
        public ?int $size,
        public ?string $deletedAt,
        public ?string $expiresAt,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $id = $data['id'] ?? null;
        if (!is_string($id) || preg_match(self::ID, $id) !== 1) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $path = $text('path');
        $name = $text('name') ?? ($path !== null ? basename($path) : $id);

        return new self($id, $path, $name, in_array($data['type'] ?? null, FileEntry::TYPES, true) ? (string) $data['type'] : 'file',
            is_int($data['size'] ?? null) ? $data['size'] : null, FileEntry::moment($data['deleted_at'] ?? null), FileEntry::moment($data['expires_at'] ?? null));
    }
}
