<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

use Larena\Env\People\PanelAudit;

/**
 * The panel's DNS (Panel plan, stage 7): connecting a provider, reading zones and records, and changing records the
 * way the panel changes the server — a plan in words, a confirmation, the record as it was kept to be put back, and
 * an entry in the audit log. The token is read only to call the provider.
 */
final readonly class DnsService
{
    public function __construct(
        private DnsProvider $provider,
        private DnsConnection $connection,
        private DnsChangeLog $log,
        private ?PanelAudit $audit = null,
    ) {
    }

    public function connection(): ?DnsConnected
    {
        return $this->connection->current();
    }

    /** @return list<DnsZone> the zones the new token sees, after it is checked and kept */
    public function connect(string $token, string $actor): array
    {
        $token = trim($token);
        $status = $this->provider->verify($token);
        if (!$status->active) {
            throw new DnsFailed('token_inactive');
        }
        $zones = $this->provider->zones($token);
        $this->connection->connect($this->provider->code(), $token, $status, $actor);
        $this->audit?->record('env.dns.connected', $actor, 'env.dns:'.$this->provider->code(), [
            'provider' => $this->provider->code(), 'zones' => (string) count($zones)]);

        return $zones;
    }

    public function disconnect(string $actor): void
    {
        $connected = $this->connection->current();
        $this->connection->disconnect();
        if ($connected !== null) {
            $this->audit?->record('env.dns.disconnected', $actor, 'env.dns:'.$connected->provider, ['provider' => $connected->provider]);
        }
    }

    public function setServerAddresses(?string $ipv4, ?string $ipv6): void
    {
        $this->connection->setServerAddresses($ipv4, $ipv6);
    }

    /** @return list<DnsZone> */
    public function zones(): array
    {
        return $this->provider->zones($this->token());
    }

    public function zone(string $zoneId): DnsZone
    {
        foreach ($this->zones() as $zone) {
            if ($zone->id === $zoneId) {
                return $zone;
            }
        }
        throw new DnsFailed('not_found');
    }

    /** @return list<DnsRecord> */
    public function records(DnsZone $zone): array
    {
        return $this->provider->records($this->token(), $zone->id);
    }

    /**
     * One record made, changed or removed, as a plan.
     *
     * @param array<string, mixed> $fields
     *
     * @return list<DnsChange>
     */
    public function planRecord(DnsZone $zone, string $action, ?string $recordId, array $fields): array
    {
        if (!$zone->editable()) {
            throw new DnsFailed('zone_read_only');
        }
        $records = $this->records($zone);
        $before = null;
        if ($action !== DnsChange::CREATE) {
            foreach ($records as $record) {
                if ($record->id === $recordId) {
                    $before = $record;
                }
            }
            if ($before === null) {
                throw new DnsFailed('not_found');
            }
        }

        return match ($action) {
            DnsChange::CREATE => (static function () use ($fields, $zone, $records): array {
                $after = DnsRecordInput::fromForm($fields, $zone);
                foreach ($records as $record) {
                    if ($after->sameAs($record)) {
                        throw new DnsFailed('nothing_to_change');
                    }
                }

                return [new DnsChange(DnsChange::CREATE, $zone->id, $zone->name, null, $after)];
            })(),
            DnsChange::UPDATE => (static function () use ($fields, $zone, $before): array {
                $after = DnsRecordInput::fromForm($fields + ['type' => $before->type], $zone);
                if ($after->sameAs($before)) {
                    throw new DnsFailed('nothing_to_change');
                }

                return [new DnsChange(DnsChange::UPDATE, $zone->id, $zone->name, $before, $after)];
            })(),
            DnsChange::DELETE => [new DnsChange(DnsChange::DELETE, $zone->id, $zone->name, $before, null)],
            default => throw new DnsFailed('not_allowed'),
        };
    }

    /**
     * Point a site's domain at this server (and its www at the domain): its A to the server's IPv4, its AAAA to the
     * server's IPv6 or none, an alias in their way removed, www as an alias of the domain.
     *
     * @return list<DnsChange>
     */
    public function planPointHere(string $domain): array
    {
        $connected = $this->connection->current();
        if ($connected === null) {
            throw new DnsFailed('not_connected');
        }
        if (!$connected->hasAddress()) {
            throw new DnsFailed('address_missing');
        }
        $zone = $this->zoneFor($domain) ?? throw new DnsFailed('zone_missing');
        if (!$zone->editable()) {
            throw new DnsFailed('zone_read_only');
        }
        $domain = strtolower($domain);
        $records = $this->records($zone);
        $own = static fn (string $name, string $type): array => array_values(array_filter($records,
            static fn (DnsRecord $record): bool => $record->name === $name && $record->type === $type));
        $changes = [];
        foreach ($own($domain, 'CNAME') as $alias) {
            $changes[] = new DnsChange(DnsChange::DELETE, $zone->id, $zone->name, $alias, null);
        }
        foreach (['A' => $connected->serverIpv4, 'AAAA' => $connected->serverIpv6] as $type => $address) {
            $existing = $own($domain, $type);
            if ($address === null) {
                // An address of another server would still send some visitors there.
                foreach ($existing as $record) {
                    $changes[] = new DnsChange(DnsChange::DELETE, $zone->id, $zone->name, $record, null);
                }
                continue;
            }
            $wanted = new DnsRecordInput($type, $domain, $address, $existing[0]->ttl ?? 1, $existing[0]->proxied ?? false);
            if ($existing === []) {
                $changes[] = new DnsChange(DnsChange::CREATE, $zone->id, $zone->name, null, $wanted->checked($zone));
                continue;
            }
            $keep = null;
            foreach ($existing as $record) {
                if ($record->content === $address && $keep === null) {
                    $keep = $record;
                }
            }
            // The record that names this server stays; with none, the first is changed to it. The rest go.
            if ($keep === null) {
                $keep = array_shift($existing);
                $changes[] = new DnsChange(DnsChange::UPDATE, $zone->id, $zone->name, $keep, $wanted->checked($zone));
            }
            foreach ($existing as $record) {
                if ($record !== $keep) {
                    $changes[] = new DnsChange(DnsChange::DELETE, $zone->id, $zone->name, $record, null);
                }
            }
        }
        $www = 'www.'.$domain;
        $wwwRecords = array_values(array_filter($records, static fn (DnsRecord $record): bool => $record->name === $www && in_array($record->type, ['A', 'AAAA', 'CNAME'], true)));
        $alias = new DnsRecordInput('CNAME', $www, $domain, $wwwRecords[0]->ttl ?? 1, $wwwRecords[0]->proxied ?? false);
        if ($wwwRecords === []) {
            $changes[] = new DnsChange(DnsChange::CREATE, $zone->id, $zone->name, null, $alias->checked($zone));
        } else {
            $here = static fn (DnsRecord $record): bool => ($record->type === 'CNAME' && strtolower(rtrim($record->content, '.')) === $domain)
                || ($record->type === 'A' && $record->content === $connected->serverIpv4)
                || ($record->type === 'AAAA' && $record->content === $connected->serverIpv6);
            if (array_filter($wwwRecords, static fn (DnsRecord $record): bool => !$here($record)) !== []) {
                foreach ($wwwRecords as $record) {
                    $changes[] = new DnsChange(DnsChange::DELETE, $zone->id, $zone->name, $record, null);
                }
                $changes[] = new DnsChange(DnsChange::CREATE, $zone->id, $zone->name, null, $alias->checked($zone));
            }
        }
        if ($changes === []) {
            throw new DnsFailed('nothing_to_change');
        }

        return $changes;
    }

    /**
     * Putting back the zone's last confirmation, as a plan: a record made is removed, a record changed is changed
     * back, a record removed is made again.
     *
     * @return list<DnsChange>
     */
    public function planUndo(DnsZone $zone): array
    {
        $last = $this->log->last($zone->id) ?? throw new DnsFailed('nothing_to_undo');
        $records = $this->records($zone);
        $current = static function (DnsRecord $made) use ($records): ?DnsRecord {
            foreach ($records as $record) {
                if ($record->id === $made->id) {
                    return $record;
                }
            }

            return null;
        };
        $changes = [];
        foreach (array_reverse($last['changes']) as $done) {
            $changes[] = match ($done['action']) {
                DnsChange::CREATE => new DnsChange(DnsChange::DELETE, $zone->id, $zone->name,
                    $current($done['after']) ?? throw new DnsFailed('changed_meanwhile'), null),
                DnsChange::UPDATE => new DnsChange(DnsChange::UPDATE, $zone->id, $zone->name,
                    $current($done['after']) ?? throw new DnsFailed('changed_meanwhile'), $done['before']?->asInput()),
                default => new DnsChange(DnsChange::CREATE, $zone->id, $zone->name, null, $done['before']?->asInput()),
            };
        }

        return $changes;
    }

    /** The batch the last confirmation was, the one a plan of putting back puts back. */
    public function lastBatch(DnsZone $zone): ?array
    {
        return $this->log->last($zone->id);
    }

    /**
     * Does what a plan showed, after checking nothing changed since: a record removed or changed meanwhile stops it.
     *
     * @param list<DnsChange> $changes
     *
     * @return string the batch
     */
    public function apply(array $changes, string $actor, ?string $undoOf = null): string
    {
        if ($changes === []) {
            throw new DnsFailed('nothing_to_change');
        }
        $token = $this->token();
        $zoneId = $changes[0]->zoneId;
        $now = [];
        foreach ($this->provider->records($token, $zoneId) as $record) {
            $now[$record->id] = $record;
        }
        foreach ($changes as $change) {
            if ($change->zoneId !== $zoneId) {
                throw new DnsFailed('not_allowed');
            }
            if ($change->before !== null && (!isset($now[$change->before->id]) || !$change->before->asInput()->sameAs($now[$change->before->id]))) {
                throw new DnsFailed('changed_meanwhile');
            }
        }
        $batch = bin2hex(random_bytes(16));
        foreach ($changes as $change) {
            $result = match ($change->action) {
                DnsChange::CREATE => $this->provider->create($token, $zoneId, $change->after ?? throw new DnsFailed('not_allowed')),
                DnsChange::UPDATE => $this->provider->update($token, $zoneId, $change->before->id ?? '', $change->after ?? throw new DnsFailed('not_allowed')),
                DnsChange::DELETE => (function () use ($token, $zoneId, $change): null {
                    $this->provider->delete($token, $zoneId, $change->before->id ?? '');

                    return null;
                })(),
                default => throw new DnsFailed('not_allowed'),
            };
            $this->log->record($batch, $change, $result, $actor, $undoOf);
            $this->audit?->record('env.dns.record_changed', $actor, 'env.dns.zone:'.$change->zoneName, [
                'action' => $change->action,
                'before' => $change->before?->line() ?? '',
                'after' => $result?->line() ?? '',
                'batch' => $batch,
            ]);
        }
        if ($undoOf !== null) {
            $this->log->markUndone($undoOf);
        }

        return $batch;
    }

    /** The site card's DNS: the zone that holds the domain, its records, and whether it points here. */
    public function forSite(string $domain): SiteDns
    {
        $connected = $this->connection->current();
        if ($connected === null || $this->connection->token() === null) {
            return new SiteDns('not_connected');
        }
        try {
            $zone = $this->zoneFor($domain);
            if ($zone === null) {
                return new SiteDns('zone_missing');
            }

            return SiteDns::of($domain, $zone, $this->records($zone), $connected);
        } catch (DnsFailed $failed) {
            return new SiteDns('failed', reason: $failed->reason);
        }
    }

    /** The zone that holds a name: the longest of the token's zones it falls in. */
    public function zoneFor(string $domain): ?DnsZone
    {
        $best = null;
        foreach ($this->zones() as $zone) {
            if ($zone->covers($domain) && ($best === null || strlen($zone->name) > strlen($best->name))) {
                $best = $zone;
            }
        }

        return $best;
    }

    private function token(): string
    {
        return $this->connection->token() ?? throw new DnsFailed('not_connected');
    }
}
