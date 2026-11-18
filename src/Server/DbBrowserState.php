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
    /** The reasons the server names when it will not open the browser (simai-env 1.61.0 site:db-browser `refused`). */
    public const array REFUSALS = ['no_database', 'no_php', 'no_https', 'alias', 'suspended', 'no_driver', 'engine_unsupported'];

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
     * What stops the browser on this site, as far as the panel knows it without asking: no database of its own, or no
     * certificate — the browser opens on the site's HTTPS address, so «Открыть базу» would only lead to a refusal three
     * presses later (FASTPANEL comparison 2026-11-18); the hint and «Получить сертификат» are shown at once instead. Every
     * other reason (no PHP, an alias, paused, no driver) the server names when it refuses the plan (simai-env 1.61.0).
     */
    public static function refusal(SiteDetails $site): ?string
    {
        if ($site->databaseEngine === null) {
            return 'no_database';
        }

        return $site->certificate === null ? 'no_https' : null;
    }
}
