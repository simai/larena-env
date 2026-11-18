<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/**
 * Whether a site's database browser is open and until when (site:db-browser-status, simai-db-browser/1, simai-env 1.58.0).
 * The status never names the address: that one is given once, by the job that opened it.
 */
final readonly class DbBrowserState
{
    public const string SCHEMA = 'simai-db-browser/1';

    /** Why the server would refuse to open one: what the site lacks, said before anyone asks (the plan says plan_failed only). */
    public const array REFUSALS = ['no_database', 'no_php', 'alias', 'suspended', 'no_https'];

    public function __construct(public bool $open, public ?string $expiresAt)
    {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? self::SCHEMA) !== self::SCHEMA || !is_bool($data['open'] ?? null)) {
            return null;
        }
        $expires = $data['expires_at'] ?? null;

        return new self($data['open'], is_string($expires) && $expires !== '' ? $expires : null);
    }

    /**
     * What stops the browser on this site, in the order the server checks it: no database of its own, no PHP, another
     * site's pool (an alias), paused, no HTTPS certificate. Null when nothing the panel knows of does.
     */
    public static function refusal(SiteDetails $site): ?string
    {
        return match (true) {
            $site->databaseEngine === null => 'no_database',
            $site->php === 'none' || $site->php === '' => 'no_php',
            $site->profile === 'alias' => 'alias',
            $site->suspended => 'suspended',
            $site->ssl === 'none' => 'no_https',
            default => null,
        };
    }
}
