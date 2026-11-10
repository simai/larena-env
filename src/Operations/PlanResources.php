<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

/**
 * What a new site or a restored Bitrix copy asks of the server, as their plans carry it (`resources`, simai-env 1.56.0):
 * free disk where the site lives and how much the archive needs (three times its size), the server's memory and the
 * kind's minimum and recommended memory, and the server's warnings. With a restore, the archive it found.
 */
final readonly class PlanResources
{
    /**
     * @param list<string> $warnings the warnings' codes (ram_below_recommended, size_unknown, disk_unknown)
     * @param ?array{base: string, count: int, total_bytes: ?int, encrypted: bool} $archive
     */
    public function __construct(
        public ?int $diskFreeBytes,
        public int $neededBytes,
        public ?int $ramMb,
        public int $ramMinMb,
        public int $ramRecommendedMb,
        public bool $ok,
        public array $warnings,
        public ?array $archive = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed>|null $archive
     */
    public static function fromData(array $data, ?array $archive = null): self
    {
        $int = static fn (string $name): ?int => is_int($data[$name] ?? null) && $data[$name] >= 0 ? $data[$name] : null;
        $warnings = [];
        foreach (is_array($data['warnings'] ?? null) ? $data['warnings'] : [] as $warning) {
            $code = is_array($warning) ? ($warning['code'] ?? null) : $warning;
            if (is_string($code) && preg_match('/\A[a-z_]{1,64}\z/D', $code) === 1) {
                $warnings[] = $code;
            }
        }
        $found = null;
        if (is_array($archive) && is_string($archive['base'] ?? null)) {
            $found = ['base' => $archive['base'], 'count' => is_int($archive['count'] ?? null) ? $archive['count'] : 1,
                'total_bytes' => is_int($archive['total_bytes'] ?? null) ? $archive['total_bytes'] : null, 'encrypted' => ($archive['encrypted'] ?? false) === true];
        }

        return new self($int('disk_free_bytes'), $int('needed_bytes') ?? 0, $int('ram_mb'), $int('ram_min_mb') ?? 0, $int('ram_recommended_mb') ?? 0,
            ($data['ok'] ?? true) !== false, $warnings, $found);
    }
}
