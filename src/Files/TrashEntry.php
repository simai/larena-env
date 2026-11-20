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
        // A file with passwords (simai-env 1.62.2 marks the trash too; the panel's list for an older server): put back only
        // by a person with that right, since a restore elsewhere would hand its text to anyone (ENV, msg-2e9ffbb9cd03).
        public bool $secret = false,
        // Why it is in the trash (simai-env 1.64.0): removed, or overwritten by a write, an upload or an unpacking; null
        // from an older server, where everything in it was removed.
        public ?string $reason = null,
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

        $type = in_array($data['type'] ?? null, FileEntry::TYPES, true) ? (string) $data['type'] : 'file';

        return new self($id, $path, $name, $type, is_int($data['size'] ?? null) ? $data['size'] : null, FileEntry::moment($data['deleted_at'] ?? null),
            FileEntry::moment($data['expires_at'] ?? null), ($data['secret'] ?? null) === true || ($type === 'file' && FileEntry::secret($name)),
            in_array($data['reason'] ?? null, ['removed', 'overwritten'], true) ? (string) $data['reason'] : null);
    }
}
