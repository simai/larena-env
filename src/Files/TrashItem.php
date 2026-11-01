<?php

declare(strict_types=1);

namespace Larena\Env\Files;

use UnexpectedValueException;

/** Something in a site's trash, as fs:trash-list answers it: where it was and when it went there. */
final readonly class TrashItem
{
    public function __construct(
        public string $id,
        public string $path,
        public ?string $deletedAt,
        public ?string $type,
        public ?int $size,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $id = $data['id'] ?? null;
        $path = $data['path'] ?? null;
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $id) !== 1 || !is_string($path) || $path === '') {
            throw new UnexpectedValueException('trash_item_invalid');
        }

        return new self($id, $path, is_string($data['deleted_at'] ?? null) ? $data['deleted_at'] : null,
            is_string($data['type'] ?? null) ? $data['type'] : null, is_int($data['size'] ?? null) ? $data['size'] : null);
    }
}
