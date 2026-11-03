<?php

declare(strict_types=1);

namespace Larena\Env\Certificates;

/**
 * Whether the server keeps a DNS provider's token for its certificates (ssl:dns-status, simai-env 1.36.0): with one,
 * a certificate is checked through DNS, before the domain points at the server and also for every subdomain. The
 * answer never holds the token.
 */
final readonly class DnsCertificates
{
    public const string CLOUDFLARE = 'cloudflare';

    public function __construct(
        public bool $configured,
        public ?string $updatedAt,
    ) {
    }

    /** @param array<array-key, mixed> $data the answer: schema simai-ssl-dns/1, providers [{provider, configured, updated_at}] */
    public static function fromData(array $data): ?self
    {
        if (($data['schema'] ?? null) !== 'simai-ssl-dns/1' || !is_array($data['providers'] ?? null)) {
            return null;
        }
        foreach ($data['providers'] as $provider) {
            if (is_array($provider) && ($provider['provider'] ?? null) === self::CLOUDFLARE) {
                return new self(($provider['configured'] ?? null) === true,
                    is_string($provider['updated_at'] ?? null) ? $provider['updated_at'] : null);
            }
        }

        return new self(false, null);
    }
}
