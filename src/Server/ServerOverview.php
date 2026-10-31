<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use UnexpectedValueException;

/** The first screen's data: the server's status and its sites, read from a host description (simai-host/1). */
final readonly class ServerOverview
{
    private const string HOST_SCHEMA = 'simai-host/1';

    /**
     * @param list<ServiceState> $services
     * @param list<string> $phpVersions
     * @param list<SiteSummary> $sites
     */
    public function __construct(
        public string $generatedAt,
        public string $envVersion,
        public string $osId,
        public string $osVersion,
        public array $services,
        public array $phpVersions,
        public array $sites,
    ) {
    }

    /**
     * The overview without the sites on these domains: the panel leaves its own site out of the sites it manages.
     *
     * @param list<string> $domains
     */
    public function withoutSites(array $domains): self
    {
        $domains = array_map('strtolower', $domains);

        return new self($this->generatedAt, $this->envVersion, $this->osId, $this->osVersion, $this->services, $this->phpVersions,
            array_values(array_filter($this->sites, static fn (SiteSummary $site): bool => !in_array(strtolower($site->domain), $domains, true))));
    }

    /**
     * @param array<array-key, mixed> $host
     *
     * @throws UnexpectedValueException
     */
    public static function fromHostDescription(array $host): self
    {
        if (($host['schema'] ?? null) !== self::HOST_SCHEMA) {
            throw new UnexpectedValueException('host_schema_unknown');
        }
        $generatedAt = $host['generated_at'] ?? null;
        $envVersion = $host['simai_env']['version'] ?? null;
        $osId = $host['os']['id'] ?? null;
        $osVersion = $host['os']['version'] ?? null;
        if (!is_string($generatedAt) || !is_string($envVersion) || !is_string($osId) || !is_string($osVersion)) {
            throw new UnexpectedValueException('host_field_invalid');
        }
        $services = [];
        foreach (is_array($host['services'] ?? null) ? $host['services'] : [] as $name => $state) {
            if (!is_string($state)) {
                throw new UnexpectedValueException('host_service_invalid');
            }
            $services[] = new ServiceState((string) $name, $state);
        }
        $sites = [];
        if (!is_array($host['sites'] ?? null)) {
            throw new UnexpectedValueException('host_sites_invalid');
        }
        foreach ($host['sites'] as $site) {
            if (!is_array($site)) {
                throw new UnexpectedValueException('host_sites_invalid');
            }
            $sites[] = SiteSummary::fromDescription($site);
        }

        return new self($generatedAt, $envVersion, $osId, $osVersion, $services,
            array_values(array_filter(is_array($host['php_versions'] ?? null) ? $host['php_versions'] : [], 'is_string')), $sites);
    }
}
