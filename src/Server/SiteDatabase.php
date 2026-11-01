<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** A database of a site: its engine, its size and the names of its users. Never its host or a password. */
final readonly class SiteDatabase
{
    /** @param list<string> $users */
    public function __construct(public string $name, public string $engine, public ?string $site, public ?int $sizeBytes, public array $users)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['name'] ?? null;
        $engine = $data['engine'] ?? null;
        if (!is_string($name) || $name === '' || !is_string($engine) || $engine === '') {
            return null;
        }

        return new self($name, $engine, is_string($data['site'] ?? null) ? $data['site'] : null,
            is_int($data['size_bytes'] ?? null) ? $data['size_bytes'] : null,
            array_values(array_filter(is_array($data['users'] ?? null) ? $data['users'] : [], 'is_string')));
    }
}
