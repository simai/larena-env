<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/** A record as the provider keeps it. `content` is the record's value in zone-file words. */
final readonly class DnsRecord
{
    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public string $content,
        public int $ttl,
        public bool $proxied = false,
        public ?int $priority = null,
    ) {
    }

    /** The record as the change log keeps it, to be put back. */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'name' => $this->name, 'content' => $this->content,
            'ttl' => $this->ttl, 'proxied' => $this->proxied, 'priority' => $this->priority];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['type'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['content'] ?? ''),
            (int) ($data['ttl'] ?? 1),
            (bool) ($data['proxied'] ?? false),
            isset($data['priority']) ? (int) $data['priority'] : null,
        );
    }

    /** The same record to write again, as when a change is undone. */
    public function asInput(): DnsRecordInput
    {
        return new DnsRecordInput($this->type, $this->name, $this->content, $this->ttl, $this->proxied, $this->priority);
    }

    /** One line a person reads: `A example.com → 192.0.2.1`. */
    public function line(): string
    {
        return $this->asInput()->line();
    }
}
