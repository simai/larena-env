<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/**
 * A PHP version on the server (php:list, simai-php/1, simai-env 1.37.0): whether its PHP-FPM runs, the sites on it,
 * whether it is the system's own and the one behind the `php` command, its package's version, the fixes waiting for it,
 * and whether the server would remove it (no site, not the system's, not the `php` command's).
 */
final readonly class PhpVersion
{
    /**
     * @param list<string> $sites
     * @param list<string> $updates the package names with a fix waiting
     */
    public function __construct(
        public string $version,
        public bool $fpmActive,
        public array $sites,
        public bool $native,
        public bool $cliDefault,
        public ?string $packageVersion,
        public array $updates,
        public bool $removable,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        $version = $data['version'] ?? null;
        if (!is_string($version) || preg_match('/\A[5-9]\.[0-9]\z/D', $version) !== 1) {
            return null;
        }
        $strings = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, static fn (mixed $item): bool => is_string($item) && $item !== '')) : [];

        return new self($version, ($data['fpm_active'] ?? false) === true, $strings($data['sites'] ?? null), ($data['native'] ?? false) === true,
            ($data['cli_default'] ?? false) === true, is_string($data['package_version'] ?? null) && $data['package_version'] !== '' ? $data['package_version'] : null,
            $strings($data['updates'] ?? null), ($data['removable'] ?? false) === true);
    }
}
