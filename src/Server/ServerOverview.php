<?php

declare(strict_types=1);

namespace Larena\Env\Server;

use Larena\Env\Gateway\PanelCapabilities;
use UnexpectedValueException;

/** The first screen's data: the server's status and its sites, read from a host description (simai-host/1). */
final readonly class ServerOverview
{
    private const string HOST_SCHEMA = 'simai-host/1';

    /**
     * @param list<ServiceState> $services
     * @param list<string> $phpVersions
     * @param list<SiteSummary> $sites
     * @param list<string> $owners the owners of the server (simai-env's isolation), whose spaces people work in
     * @param list<string> $blockedOwners the owners whose space is blocked (simai-env 1.27.0)
     * @param array<string, int> $ownerDiskBytes what each owner's sites take, where all of them are measured (simai-env 1.27.0)
     */
    public function __construct(
        public string $generatedAt,
        public string $envVersion,
        public string $osId,
        public string $osVersion,
        public array $services,
        public array $phpVersions,
        public array $sites,
        public array $owners = [],
        public ?PanelCapabilities $capabilities = null,
        public array $blockedOwners = [],
        public array $ownerDiskBytes = [],
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
            array_values(array_filter($this->sites, static fn (SiteSummary $site): bool => !in_array(strtolower($site->domain), $domains, true))),
            $this->owners, $this->capabilities, $this->blockedOwners, $this->ownerDiskBytes);
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

        $owners = [];
        $blocked = [];
        $disk = [];
        foreach (is_array($host['isolation']['owners'] ?? null) ? $host['isolation']['owners'] : [] as $owner) {
            if (is_array($owner) && is_string($owner['name'] ?? null) && preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $owner['name']) === 1) {
                $owners[] = $owner['name'];
                if (($owner['blocked'] ?? false) === true) {
                    $blocked[] = $owner['name'];
                }
                if (is_int($owner['sites_disk_bytes'] ?? null)) {
                    $disk[$owner['name']] = $owner['sites_disk_bytes'];
                }
            }
        }

        return new self($generatedAt, $envVersion, $osId, $osVersion, $services,
            array_values(array_filter(is_array($host['php_versions'] ?? null) ? $host['php_versions'] : [], 'is_string')), $sites, $owners,
            PanelCapabilities::fromHostDescription($host), $blocked, $disk);
    }
}
