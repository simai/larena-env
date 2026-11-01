<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** One PHP extension of a PHP version of the server, and whether it is on. */
final readonly class PhpExtension
{
    public function __construct(public string $name, public bool $enabled)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $name = $data['name'] ?? null;

        return is_string($name) && $name !== '' ? new self($name, ($data['enabled'] ?? false) === true) : null;
    }
}
