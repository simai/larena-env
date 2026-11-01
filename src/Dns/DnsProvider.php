<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/**
 * A DNS service the panel manages zones through, by its API (Panel plan, stage 7; owner decision 2026-10-31: DNS
 * through Cloudflare and others, no DNS server of our own). Cloudflare is the first; another provider is another
 * implementation of this contract. Every call refuses with DnsFailed and a reason the screens say in words.
 */
interface DnsProvider
{
    /** The provider's code, as the connection keeps it. */
    public function code(): string;

    /** Whether the token works, as the provider says it. */
    public function verify(string $token): DnsTokenStatus;

    /** @return list<DnsZone> the zones the token sees */
    public function zones(string $token): array;

    /** @return list<DnsRecord> */
    public function records(string $token, string $zoneId): array;

    public function create(string $token, string $zoneId, DnsRecordInput $record): DnsRecord;

    public function update(string $token, string $zoneId, string $recordId, DnsRecordInput $record): DnsRecord;

    public function delete(string $token, string $zoneId, string $recordId): void;
}
