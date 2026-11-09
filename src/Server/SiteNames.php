<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/**
 * The names a site answers on (site:names, simai-env 1.53.0, as recorded in ENV's v1.53.0 set): one row each, the
 * certificate they share, the most extra names the server takes, and the name it suggests adding (www.<domain> while it
 * is not a name yet).
 */
final readonly class SiteNames
{
    /** The server's schema id of the answer: a data value, compared as it is. */
    public const string SCHEMA = 'simai-site-names/1';

    /** The most extra names a site may have in simai-env 1.53.0; the answer carries the server's own (`max`). */
    public const int MOST = 20;

    /**
     * @param list<SiteName> $names
     * @param list<string> $suggest
     * @param int $most the most extra names the server takes (`max`): the main domain and every subdomain are not counted
     */
    public function __construct(
        public string $domain,
        public array $names,
        public ?SiteCertificate $certificate,
        public array $suggest,
        public int $most = self::MOST,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException an answer the contract does not have: the block says it cannot show the names
     */
    public static function fromData(array $data): self
    {
        $domain = $data['domain'] ?? null;
        $rows = $data['names'] ?? null;
        if (($data['schema'] ?? null) !== self::SCHEMA || !SiteName::isHost($domain) || !is_array($rows) || !array_is_list($rows) || $rows === []) {
            throw new UnexpectedValueException('site_names_invalid');
        }
        $domain = strtolower((string) $domain);
        $names = [];
        $primary = 0;
        foreach ($rows as $row) {
            $name = is_array($row) ? SiteName::fromData($row) : throw new UnexpectedValueException('site_names_invalid');
            // One row per name, and the main domain is the answer's own domain, once.
            if (isset($names[$name->name]) || ($name->kind === SiteName::PRIMARY && $name->name !== $domain)) {
                throw new UnexpectedValueException('site_names_invalid');
            }
            $primary += $name->kind === SiteName::PRIMARY ? 1 : 0;
            $names[$name->name] = $name;
        }
        $certificate = $data['certificate'] ?? null;
        $suggest = $data['suggest'] ?? [];
        $most = $data['max'] ?? null;
        if ($primary !== 1 || ($certificate !== null && !is_array($certificate)) || !is_array($suggest) || !array_is_list($suggest)
            || !is_int($most) || $most < 1) {
            throw new UnexpectedValueException('site_names_invalid');
        }
        $suggested = [];
        foreach ($suggest as $one) {
            if (!SiteName::isHost($one)) {
                throw new UnexpectedValueException('site_names_invalid');
            }
            // A name the site already has is not offered again, whatever the server suggests.
            if (!isset($names[strtolower($one)])) {
                $suggested[] = strtolower($one);
            }
        }

        return new self($domain, array_values($names), $certificate === null ? null : SiteCertificate::fromData($certificate), array_values(array_unique($suggested)), $most);
    }

    /**
     * Whether the site has as many extra names as the server takes: nothing more is offered. The server counts the extra
     * names only (simai-env 1.53.0 refuses one more as name_limit); the main domain and every subdomain are not counted.
     */
    public function full(): bool
    {
        return count(array_filter($this->names, static fn (SiteName $name): bool => $name->kind === SiteName::EXTRA)) >= $this->most;
    }
}
