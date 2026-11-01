<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/**
 * One change a plan shows before it is confirmed: a record made, changed or removed, with the record as it is now
 * (`before`) and as it will be (`after`). It is kept in the person's session between the plan and the confirmation.
 */
final readonly class DnsChange
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';
    public const string DELETE = 'delete';

    public function __construct(
        public string $action,
        public string $zoneId,
        public string $zoneName,
        public ?DnsRecord $before,
        public ?DnsRecordInput $after,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'before' => $this->before?->toArray(),
            'after' => $this->after === null ? null : [
                'type' => $this->after->type, 'name' => $this->after->name, 'content' => $this->after->content,
                'ttl' => $this->after->ttl, 'proxied' => $this->after->proxied, 'priority' => $this->after->priority,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $after = is_array($data['after'] ?? null) ? DnsRecord::fromArray($data['after'])->asInput() : null;

        return new self(
            (string) ($data['action'] ?? ''),
            (string) ($data['zone_id'] ?? ''),
            (string) ($data['zone_name'] ?? ''),
            is_array($data['before'] ?? null) ? DnsRecord::fromArray($data['before']) : null,
            $after,
        );
    }
}
