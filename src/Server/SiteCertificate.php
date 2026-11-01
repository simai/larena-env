<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** The certificate nginx serves for a site, as `site describe` reports it. */
final readonly class SiteCertificate
{
    /** @param list<string> $names */
    public function __construct(
        public array $names,
        public ?string $issuer,
        public ?string $notAfter,
        public ?int $daysLeft,
        public bool $autoRenew,
        // The last renewal (simai-env 1.30.0): when, and whether it worked; null before the first.
        public ?string $lastRenewAt = null,
        public ?bool $lastRenewOk = null,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $names = [];
        foreach (is_array($data['names'] ?? null) ? $data['names'] : [] as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return new self(
            names: $names,
            issuer: is_string($data['issuer'] ?? null) ? $data['issuer'] : null,
            notAfter: is_string($data['not_after'] ?? null) ? $data['not_after'] : null,
            daysLeft: is_int($data['days_left'] ?? null) ? $data['days_left'] : null,
            autoRenew: ($data['auto_renew'] ?? false) === true,
            lastRenewAt: is_array($data['last_renew'] ?? null) && is_string($data['last_renew']['at'] ?? null) ? $data['last_renew']['at'] : null,
            lastRenewOk: is_array($data['last_renew'] ?? null) && is_bool($data['last_renew']['ok'] ?? null) ? $data['last_renew']['ok'] : null,
        );
    }

    /** A certificate this close to its end is worth saying out loud on the screen. */
    public function endsSoon(): bool
    {
        return $this->daysLeft !== null && $this->daysLeft <= 14;
    }
}
