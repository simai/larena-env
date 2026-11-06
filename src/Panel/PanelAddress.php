<?php

declare(strict_types=1);

namespace Larena\Env\Panel;

/**
 * Where the panel answers (panel:address, simai-panel-address/1, simai-env 1.51.0): by the server's address and port with
 * a self-signed certificate, and by a domain attached later with Let's Encrypt; `url` is the one to use.
 */
final readonly class PanelAddress
{
    public const string SCHEMA = 'simai-panel-address/1';

    /**
     * @param list<string> $addresses
     * @param array{kind: ?string, sha256: ?string, expires: ?string}|null $ipCertificate
     */
    public function __construct(
        public ?string $ip,
        public array $addresses,
        public ?int $port,
        public bool $ipAccess,
        public ?string $ipUrl,
        public ?array $ipCertificate,
        public ?string $domain,
        public bool $domainSsl,
        public ?string $domainUrl,
        public ?string $url,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }
        $url = static fn (mixed $value): ?string => is_string($value) && preg_match('#\Ahttps?://[A-Za-z0-9.:\[\]-]{1,260}/?\z#D', $value) === 1 ? rtrim($value, '/') : null;
        $text = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $cert = is_array($data['ip_certificate'] ?? null) ? $data['ip_certificate'] : null;

        return new self(
            ip: $text($data['ip'] ?? null),
            addresses: array_values(array_filter(is_array($data['addresses'] ?? null) ? $data['addresses'] : [], 'is_string')),
            port: is_int($data['port'] ?? null) ? $data['port'] : null,
            ipAccess: ($data['ip_access'] ?? false) === true,
            ipUrl: $url($data['ip_url'] ?? null),
            ipCertificate: $cert === null ? null : ['kind' => $text($cert['kind'] ?? null), 'sha256' => $text($cert['sha256'] ?? null), 'expires' => $text($cert['expires'] ?? null)],
            domain: is_string($data['domain'] ?? null) && preg_match('/\A[a-z0-9.-]{1,253}\z/D', $data['domain']) === 1 ? $data['domain'] : null,
            domainSsl: ($data['domain_ssl'] ?? false) === true,
            domainUrl: $url($data['domain_url'] ?? null),
            url: $url($data['url'] ?? null),
        );
    }

    /** The address by IP on another port, where the panel is after panel:port. */
    public function ipUrlOnPort(int $port): ?string
    {
        if ($this->ipUrl === null) {
            return null;
        }
        $without = preg_replace('#:[0-9]{1,5}\z#', '', $this->ipUrl);

        return is_string($without) ? $without.':'.$port : null;
    }
}
