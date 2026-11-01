<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * The record changes made through the panel, each with the record as it was and as it became, by batch: what one
 * confirmation did. The last batch of a zone can be put back, which is itself a batch.
 */
final readonly class DnsChangeLog
{
    public const string TABLE = 'larena_env_dns_changes';

    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function record(string $batch, DnsChange $change, ?DnsRecord $result, string $actor, ?string $undoOf): void
    {
        $this->connection->table(self::TABLE)->insert([
            'batch' => $batch,
            'zone_id' => $change->zoneId,
            'zone_name' => $change->zoneName,
            'action' => $change->action,
            'before' => $change->before === null ? null : json_encode($change->before->toArray(), JSON_THROW_ON_ERROR),
            'after' => $result === null ? null : json_encode($result->toArray(), JSON_THROW_ON_ERROR),
            'actor' => $actor,
            'undo_of' => $undoOf,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The last confirmation in a zone that was not put back yet, as it was done.
     *
     * @return array{batch: string, at: string, actor: string, changes: list<array{action: string, before: ?DnsRecord, after: ?DnsRecord, zone_name: string}>}|null
     */
    public function last(string $zoneId): ?array
    {
        $latest = $this->connection->table(self::TABLE)->where('zone_id', $zoneId)->orderByDesc('id')->first();
        if ($latest === null || $latest->undone_at !== null) {
            return null;
        }
        $rows = $this->connection->table(self::TABLE)->where('batch', (string) $latest->batch)->orderBy('id')->get();
        $changes = [];
        foreach ($rows as $row) {
            $changes[] = [
                'action' => (string) $row->action,
                'zone_name' => (string) $row->zone_name,
                'before' => $row->before === null ? null : DnsRecord::fromArray((array) json_decode((string) $row->before, true)),
                'after' => $row->after === null ? null : DnsRecord::fromArray((array) json_decode((string) $row->after, true)),
            ];
        }

        return ['batch' => (string) $latest->batch, 'at' => (string) $latest->created_at, 'actor' => (string) $latest->actor, 'changes' => $changes];
    }

    public function markUndone(string $batch): void
    {
        $this->connection->table(self::TABLE)->where('batch', $batch)->update([
            'undone_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }
}
