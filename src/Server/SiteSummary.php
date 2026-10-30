<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/** One site as the first screen needs it. Paths, database names and users of the answer are deliberately left out. */
final readonly class SiteSummary
{
    public function __construct(
        public string $domain,
        public string $profile,
        public string $php,
        public string $ssl,
        public ?string $owner,
        public ?string $databaseEngine,
        public ?string $release,
        public ?int $certificateDaysLeft,
    ) {
    }

    /**
     * @param array<array-key, mixed> $site
     *
     * @throws UnexpectedValueException
     */
    public static function fromDescription(array $site): self
    {
        foreach (['domain', 'profile', 'php', 'ssl'] as $required) {
            if (!is_string($site[$required] ?? null) || $site[$required] === '') {
                throw new UnexpectedValueException('site_field_invalid');
            }
        }
        $days = is_array($site['certificate'] ?? null) ? ($site['certificate']['days_left'] ?? null) : null;

        return new self(
            domain: $site['domain'],
            profile: $site['profile'],
            php: $site['php'],
            ssl: $site['ssl'],
            owner: is_string($site['owner'] ?? null) ? $site['owner'] : null,
            databaseEngine: is_array($site['database'] ?? null) && is_string($site['database']['engine'] ?? null) ? $site['database']['engine'] : null,
            release: is_array($site['release'] ?? null) && is_string($site['release']['current'] ?? null) ? $site['release']['current'] : null,
            certificateDaysLeft: is_int($days) ? $days : null,
        );
    }
}
