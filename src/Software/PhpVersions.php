<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/**
 * The server's PHP (php:list, simai-php/1, simai-env 1.37.0): the versions it has, the system's own version, and the
 * versions it could install next to them.
 */
final readonly class PhpVersions
{
    /**
     * @param list<PhpVersion> $versions
     * @param list<string> $installable
     */
    public function __construct(public ?string $native, public array $versions, public array $installable)
    {
    }

    public function has(string $version): ?PhpVersion
    {
        foreach ($this->versions as $item) {
            if ($item->version === $version) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $versions = [];
        foreach (is_array($data['versions'] ?? null) ? $data['versions'] : [] as $item) {
            $made = is_array($item) ? PhpVersion::fromData($item) : null;
            if ($made !== null) {
                $versions[] = $made;
            }
        }
        $installable = is_array($data['installable'] ?? null)
            ? array_values(array_filter($data['installable'], static fn (mixed $version): bool => is_string($version) && preg_match('/\A[5-9]\.[0-9]\z/D', $version) === 1))
            : [];

        return new self(is_string($data['native'] ?? null) ? $data['native'] : null, $versions, $installable);
    }
}
