<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

/**
 * A record to write, checked before the provider is asked: a value the provider would refuse, or one that would point
 * a name somewhere it cannot go, is refused here in words. `ttl` 1 means the provider's automatic one.
 */
final readonly class DnsRecordInput
{
    public const array TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'SRV'];
    /** Only these go through the provider's proxy. */
    private const array PROXIABLE = ['A', 'AAAA', 'CNAME'];
    private const string HOST = '/\A(?=.{1,253}\z)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]\z/D';

    public function __construct(
        public string $type,
        public string $name,
        public string $content,
        public int $ttl = 1,
        public bool $proxied = false,
        public ?int $priority = null,
    ) {
    }

    /**
     * The record as a form sent it, its name written in the zone (`@`, `www`, or the full name).
     *
     * @param array<string, mixed> $fields
     */
    public static function fromForm(array $fields, DnsZone $zone): self
    {
        $type = strtoupper(trim((string) ($fields['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new DnsFailed('record_type_invalid');
        }
        $ttl = trim((string) ($fields['ttl'] ?? '')) === '' ? 1 : (int) $fields['ttl'];
        $priority = trim((string) ($fields['priority'] ?? '')) === '' ? null : (int) $fields['priority'];

        return (new self(
            $type,
            self::fullName((string) ($fields['name'] ?? ''), $zone),
            trim((string) ($fields['content'] ?? '')),
            $ttl,
            in_array(strtolower((string) ($fields['proxied'] ?? '')), ['1', 'yes', 'on', 'true'], true),
            $priority,
        ))->checked($zone);
    }

    /** `@` is the zone itself; a short name is under the zone; a full name must be in it. */
    public static function fullName(string $name, DnsZone $zone): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '' || $name === '@') {
            return $zone->name;
        }

        return $zone->covers($name) ? $name : $name.'.'.$zone->name;
    }

    /** The record itself, refused in words when the provider would refuse it or it does not belong to the zone. */
    public function checked(DnsZone $zone): self
    {
        if (!in_array($this->type, self::TYPES, true)) {
            throw new DnsFailed('record_type_invalid');
        }
        $name = $this->name;
        $wildcard = str_starts_with($name, '*.') ? substr($name, 2) : $name;
        if (!$zone->covers($name) || preg_match(self::HOST, $wildcard) !== 1) {
            throw new DnsFailed('record_name_invalid');
        }
        if ($this->ttl !== 1 && ($this->ttl < 60 || $this->ttl > 86400)) {
            throw new DnsFailed('record_ttl_invalid');
        }
        if ($this->proxied && !in_array($this->type, self::PROXIABLE, true)) {
            throw new DnsFailed('record_proxied_invalid');
        }
        $content = $this->content;
        $valid = match ($this->type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'CNAME' => self::host($content) && strtolower(rtrim($content, '.')) !== $name,
            'MX' => self::host($content) && $this->priority !== null && $this->priority >= 0 && $this->priority <= 65535,
            'TXT' => $content !== '' && strlen($content) <= 2048 && preg_match('/\A[\x20-\x7E]+\z/D', $content) === 1,
            'CAA' => preg_match('/\A(0|128) (issue|issuewild|iodef) "?[\x21\x23-\x7E]{1,255}"?\z/D', $content) === 1,
            'SRV' => str_starts_with($name, '_') && $this->priority !== null && $this->priority >= 0 && $this->priority <= 65535
                && self::srv($content) !== null,
        };
        if (!$valid) {
            throw new DnsFailed('record_content_invalid');
        }

        return $this;
    }

    /**
     * The record as the provider's API takes it. CAA and SRV are written by their parts.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = ['type' => $this->type, 'name' => $this->name, 'ttl' => $this->ttl];
        if (in_array($this->type, self::PROXIABLE, true)) {
            $payload['proxied'] = $this->proxied;
        }
        if ($this->type === 'CAA') {
            [$flags, $tag, $value] = explode(' ', $this->content, 3);
            $payload['data'] = ['flags' => (int) $flags, 'tag' => $tag, 'value' => trim($value, '"')];
        } elseif ($this->type === 'SRV') {
            [$weight, $port, $target] = self::srv($this->content) ?? [0, 0, ''];
            $payload['data'] = ['priority' => $this->priority, 'weight' => $weight, 'port' => $port, 'target' => $target];
        } else {
            $payload['content'] = $this->content;
            if ($this->type === 'MX') {
                $payload['priority'] = $this->priority;
            }
        }

        return $payload;
    }

    public function line(): string
    {
        $priority = $this->priority !== null && in_array($this->type, ['MX', 'SRV'], true) ? $this->priority.' ' : '';

        return $this->type.' '.$this->name.' → '.$priority.$this->content;
    }

    /** The same name and value, whatever the provider's id: a change that changes nothing is not planned. */
    public function sameAs(DnsRecord $record): bool
    {
        return $record->type === $this->type && strcasecmp(rtrim($record->name, '.'), $this->name) === 0
            && $record->content === $this->content && $record->ttl === $this->ttl && $record->proxied === $this->proxied
            && ($record->priority === $this->priority || !in_array($this->type, ['MX', 'SRV'], true));
    }

    private static function host(string $value): bool
    {
        return preg_match(self::HOST, strtolower(rtrim($value, '.'))) === 1;
    }

    /** @return array{0:int,1:int,2:string}|null `weight port target` */
    private static function srv(string $content): ?array
    {
        if (preg_match('/\A(\d{1,5}) (\d{1,5}) (\S+)\z/D', $content, $parts) !== 1
            || (int) $parts[1] > 65535 || (int) $parts[2] > 65535 || !self::host($parts[3])) {
            return null;
        }

        return [(int) $parts[1], (int) $parts[2], strtolower(rtrim($parts[3], '.'))];
    }
}
