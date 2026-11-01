<?php

declare(strict_types=1);

namespace Larena\Env\Files;

use UnexpectedValueException;

/** One entry of a site's folder, as fs:list answers it (simai-fs/1). */
final readonly class FileEntry
{
    public function __construct(
        public string $name,
        public string $type,
        public ?int $size,
        public ?string $mode,
        public ?string $mtime,
        public ?string $owner,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $name = $data['name'] ?? null;
        $type = $data['type'] ?? null;
        if (!is_string($name) || $name === '' || str_contains($name, '/') || !in_array($type, ['file', 'dir', 'link'], true)) {
            throw new UnexpectedValueException('file_entry_invalid');
        }

        return new self($name, $type, is_int($data['size'] ?? null) ? $data['size'] : null,
            is_string($data['mode'] ?? null) ? $data['mode'] : null, is_string($data['mtime'] ?? null) ? $data['mtime'] : null,
            is_string($data['owner'] ?? null) ? $data['owner'] : null);
    }

    public function isDir(): bool
    {
        return $this->type === 'dir';
    }
}
