<?php

declare(strict_types=1);

namespace Larena\Env\Server;

/** The server's load now: processor, memory, swap and disks. No history: the server measures, the panel only shows. */
final readonly class ServerMetrics
{
    /**
     * @param array{0: float, 1: float, 2: float}|null $load the load average over 1, 5 and 15 minutes
     * @param list<array{mount: string, total: int, used: int}> $disks
     */
    public function __construct(
        public ?int $cores,
        public ?array $load,
        public ?int $memoryTotal,
        public ?int $memoryUsed,
        public ?int $swapTotal,
        public ?int $swapUsed,
        public array $disks,
        public ?string $checkedAt,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromData(array $data): self
    {
        $cpu = is_array($data['cpu'] ?? null) ? $data['cpu'] : [];
        $number = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? (float) $value : null;
        $loads = [$number($cpu['load1'] ?? null), $number($cpu['load5'] ?? null), $number($cpu['load15'] ?? null)];
        $bytes = static fn (mixed $block, string $key): ?int => is_array($block) && is_int($block[$key] ?? null) ? $block[$key] : null;
        $disks = [];
        foreach (is_array($data['disk'] ?? null) ? $data['disk'] : [] as $disk) {
            if (is_array($disk) && is_string($disk['mount'] ?? null) && is_int($disk['total_bytes'] ?? null) && is_int($disk['used_bytes'] ?? null)) {
                $disks[] = ['mount' => $disk['mount'], 'total' => $disk['total_bytes'], 'used' => $disk['used_bytes']];
            }
        }

        return new self(
            cores: is_int($cpu['cores'] ?? null) ? $cpu['cores'] : null,
            load: in_array(null, $loads, true) ? null : [$loads[0], $loads[1], $loads[2]],
            memoryTotal: $bytes($data['memory'] ?? null, 'total_bytes'),
            memoryUsed: $bytes($data['memory'] ?? null, 'used_bytes'),
            swapTotal: $bytes($data['swap'] ?? null, 'total_bytes'),
            swapUsed: $bytes($data['swap'] ?? null, 'used_bytes'),
            disks: $disks,
            checkedAt: is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null,
        );
    }

    /** A disk is nearly full at 90% and over: the overview says so first. */
    public static function nearlyFull(int $total, int $used): bool
    {
        return $total > 0 && $used / $total >= 0.9;
    }
}
