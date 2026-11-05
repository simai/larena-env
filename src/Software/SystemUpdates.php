<?php

declare(strict_types=1);

namespace Larena\Env\Software;

/**
 * The system's waiting package updates (software:list's `system`, and software:update's plan, simai-env 1.38.0): how
 * many, how many from the security pocket, each package with its versions, the ones kept back, whether the server waits
 * for a reboot and how old the package index is.
 */
final readonly class SystemUpdates
{
    /**
     * @param list<array{name: string, from: ?string, to: ?string, security: bool}> $packages
     * @param list<string> $keptBack
     * @param list<string> $rebootPackages
     */
    public function __construct(
        public int $updates,
        public int $security,
        public array $packages,
        public array $keptBack,
        public bool $rebootRequired,
        public array $rebootPackages,
        public ?int $indexAgeSeconds,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $strings = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, static fn (mixed $item): bool => is_string($item) && $item !== '')) : [];
        $packages = [];
        foreach (is_array($data['packages'] ?? null) ? $data['packages'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && $package['name'] !== '') {
                $packages[] = [
                    'name' => $package['name'],
                    'from' => is_string($package['from'] ?? null) ? $package['from'] : null,
                    'to' => is_string($package['to'] ?? null) ? $package['to'] : null,
                    'security' => ($package['security'] ?? false) === true,
                ];
            }
        }
        $count = static fn (string $key, int $fallback): int => is_int($data[$key] ?? null) && $data[$key] >= 0 ? $data[$key] : $fallback;
        $age = $data['index_age_seconds'] ?? null;

        return new self(
            updates: $count('updates', count($packages)),
            security: $count('security', count(array_filter($packages, static fn (array $package): bool => $package['security']))),
            packages: $packages,
            keptBack: $strings($data['kept_back'] ?? null),
            rebootRequired: ($data['reboot_required'] ?? false) === true,
            rebootPackages: $strings($data['reboot_packages'] ?? null),
            indexAgeSeconds: is_int($age) && $age >= 0 ? $age : null,
        );
    }

    /** The index's age in whole minutes, for «проверено N мин назад». */
    public function indexAgeMinutes(): ?int
    {
        return $this->indexAgeSeconds === null ? null : intdiv($this->indexAgeSeconds, 60);
    }
}
