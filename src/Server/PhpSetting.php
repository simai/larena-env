<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** One `php.ini` setting of a site: its value and where it comes from (the server's default or the site's own). */
final readonly class PhpSetting
{
    public function __construct(public string $key, public string $value, public ?string $source)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $key = $data['key'] ?? null;
        $value = $data['value'] ?? null;
        if (!is_string($key) || $key === '' || !(is_string($value) || is_int($value) || is_float($value))) {
            return null;
        }

        return new self($key, (string) $value, is_string($data['source'] ?? null) ? $data['source'] : null);
    }
}
