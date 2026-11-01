<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/**
 * What the site card says of a domain's DNS: the zone that holds it, the records of the domain and its www, and
 * whether the domain points to this server.
 */
final readonly class SiteDns
{
    /** The domain's A and AAAA all name this server's addresses. */
    public const string HERE = 'here';
    /** Some of them name another address. */
    public const string ELSEWHERE = 'elsewhere';
    /** The domain has no address record of its own. */
    public const string NONE = 'none';
    /** The server's addresses are not given, or the domain is an alias: the panel cannot tell. */
    public const string UNKNOWN = 'unknown';

    /** @param list<DnsRecord> $records */
    public function __construct(
        public string $state,
        public ?DnsZone $zone = null,
        public array $records = [],
        public string $points = self::UNKNOWN,
        public ?string $reason = null,
    ) {
    }

    /**
     * @param list<DnsRecord> $records the zone's records
     */
    public static function of(string $domain, DnsZone $zone, array $records, ?DnsConnected $connection): self
    {
        $domain = strtolower($domain);
        $own = array_values(array_filter($records, static fn (DnsRecord $record): bool => in_array($record->name, [$domain, 'www.'.$domain], true)));
        $addresses = array_values(array_filter($own, static fn (DnsRecord $record): bool => $record->name === $domain && in_array($record->type, ['A', 'AAAA'], true)));
        $alias = array_filter($own, static fn (DnsRecord $record): bool => $record->name === $domain && $record->type === 'CNAME') !== [];
        $points = match (true) {
            $alias => self::UNKNOWN,
            $addresses === [] => self::NONE,
            $connection === null || !$connection->hasAddress() => self::UNKNOWN,
            default => array_filter($addresses, static fn (DnsRecord $record): bool => $record->content !== ($record->type === 'A' ? $connection->serverIpv4 : $connection->serverIpv6)) === []
                ? self::HERE : self::ELSEWHERE,
        };

        return new self('ok', $zone, $own, $points);
    }
}
