<?php

declare(strict_types=1);

namespace Larena\Env\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Cloudflare's API v4 with an API token (Bearer). The token never leaves the request's header: it is not logged,
 * not put in an error and not answered. Lists are read page by page up to a bound, so a large account cannot hold
 * a screen forever.
 */
final readonly class CloudflareDnsProvider implements DnsProvider
{
    public const string CODE = 'cloudflare';
    private const string BASE = 'https://api.cloudflare.com/client/v4';
    private const int ZONE_PAGES = 20;
    private const int RECORD_PAGES = 50;

    public function __construct(private Factory $http, private float $timeoutSeconds = 15.0)
    {
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function verify(string $token): DnsTokenStatus
    {
        $result = $this->result(fn (): Response => $this->request($token)->get(self::BASE.'/user/tokens/verify'));

        return new DnsTokenStatus(
            ($result['status'] ?? null) === 'active',
            (string) ($result['status'] ?? 'unknown'),
            isset($result['expires_on']) ? (string) $result['expires_on'] : null,
        );
    }

    public function zones(string $token): array
    {
        $zones = [];
        foreach ($this->pages($token, '/zones', ['per_page' => 50], self::ZONE_PAGES) as $zone) {
            $zones[] = new DnsZone(
                (string) ($zone['id'] ?? ''),
                strtolower((string) ($zone['name'] ?? '')),
                (string) ($zone['status'] ?? ''),
                array_values(array_map('strval', (array) ($zone['permissions'] ?? []))),
            );
        }
        usort($zones, static fn (DnsZone $a, DnsZone $b): int => strcmp($a->name, $b->name));

        return $zones;
    }

    public function records(string $token, string $zoneId): array
    {
        self::id($zoneId);
        $records = [];
        foreach ($this->pages($token, '/zones/'.$zoneId.'/dns_records', ['per_page' => 100], self::RECORD_PAGES) as $record) {
            if (in_array($record['type'] ?? null, DnsRecordInput::TYPES, true)) {
                $records[] = self::record($record);
            }
        }
        usort($records, static fn (DnsRecord $a, DnsRecord $b): int => [$a->name, $a->type, $a->content] <=> [$b->name, $b->type, $b->content]);

        return $records;
    }

    public function create(string $token, string $zoneId, DnsRecordInput $record): DnsRecord
    {
        self::id($zoneId);

        return self::record($this->result(fn (): Response => $this->request($token)->post(self::BASE.'/zones/'.$zoneId.'/dns_records', $record->toPayload())));
    }

    public function update(string $token, string $zoneId, string $recordId, DnsRecordInput $record): DnsRecord
    {
        self::id($zoneId);
        self::id($recordId);

        return self::record($this->result(fn (): Response => $this->request($token)->put(self::BASE.'/zones/'.$zoneId.'/dns_records/'.$recordId, $record->toPayload())));
    }

    public function delete(string $token, string $zoneId, string $recordId): void
    {
        self::id($zoneId);
        self::id($recordId);
        $this->result(fn (): Response => $this->request($token)->delete(self::BASE.'/zones/'.$zoneId.'/dns_records/'.$recordId));
    }

    private function request(string $token): PendingRequest
    {
        if ($token === '' || preg_match('/\A[\x21-\x7E]{20,200}\z/D', $token) !== 1) {
            throw new DnsFailed('token_invalid');
        }

        return $this->http->withToken($token)->acceptJson()->timeout($this->timeoutSeconds);
    }

    /**
     * @param array<string, int> $query
     *
     * @return list<array<string, mixed>>
     */
    private function pages(string $token, string $path, array $query, int $limit): array
    {
        $items = [];
        for ($page = 1; $page <= $limit; ++$page) {
            $response = $this->send(fn (): Response => $this->request($token)->get(self::BASE.$path, $query + ['page' => $page]));
            $result = $this->result($response);
            foreach ($result as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $info = (array) ($response->json('result_info') ?? []);
            if ((int) ($info['total_pages'] ?? 1) <= $page) {
                break;
            }
        }

        return $items;
    }

    /** @return array<mixed> the answer's `result`, or a refusal in the panel's words */
    private function result(Response|\Closure $response): array
    {
        $response = $response instanceof \Closure ? $this->send($response) : $response;
        $body = $response->json();
        if (!is_array($body)) {
            throw new DnsFailed('provider_unreadable');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new DnsFailed('token_refused');
        }
        if (($body['success'] ?? false) !== true) {
            $codes = array_map(static fn ($error): int => (int) (is_array($error) ? ($error['code'] ?? 0) : 0), (array) ($body['errors'] ?? []));
            // 81057, 81058: the same record exists already.
            throw new DnsFailed(array_intersect($codes, [81053, 81057, 81058]) !== [] ? 'record_exists' : 'provider_refused');
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    private function send(\Closure $call): Response
    {
        try {
            return $call();
        } catch (ConnectionException) {
            throw new DnsFailed('provider_unreachable');
        }
    }

    /** @param array<string, mixed> $record */
    private static function record(array $record): DnsRecord
    {
        $type = (string) ($record['type'] ?? '');
        $data = is_array($record['data'] ?? null) ? $record['data'] : [];
        $content = (string) ($record['content'] ?? '');
        $priority = isset($record['priority']) ? (int) $record['priority'] : null;
        if ($type === 'CAA' && $data !== []) {
            $content = ((int) ($data['flags'] ?? 0)).' '.($data['tag'] ?? '').' "'.($data['value'] ?? '').'"';
        } elseif ($type === 'SRV' && $data !== []) {
            $content = ((int) ($data['weight'] ?? 0)).' '.((int) ($data['port'] ?? 0)).' '.($data['target'] ?? '');
            $priority = isset($data['priority']) ? (int) $data['priority'] : $priority;
        }

        return new DnsRecord(
            (string) ($record['id'] ?? ''),
            $type,
            strtolower((string) ($record['name'] ?? '')),
            $content,
            (int) ($record['ttl'] ?? 1),
            (bool) ($record['proxied'] ?? false),
            in_array($type, ['MX', 'SRV'], true) ? $priority : null,
        );
    }

    private static function id(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new DnsFailed('not_found');
        }
    }
}
