<?php

declare(strict_types=1);

namespace Larena\Env\History;

/** One period of the load history, ready to draw: each line's points as (share of the period, percent). */
final readonly class MetricHistory
{
    /** @param array<string, list<array{0: float, 1: float}>> $lines `load`, `memory` and `disk:<mount>` */
    public function __construct(
        public string $period,
        public array $lines,
        public int $count,
        public ?string $from,
        public ?string $to,
    ) {
    }

    /** The periods' lengths in seconds: the server's history keeps a day, a week and a month. */
    public const array SPANS = ['day' => 86_400, 'week' => 604_800, 'month' => 2_592_000];

    /**
     * The load the server kept itself (simai-env 1.31.0, `simai-metrics-history/1`): 5-minute points for a day, hourly
     * ones for a week or a month. The period ends at the server's last point, so a history is never drawn empty because
     * of the clocks.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromServer(string $period, array $data): self
    {
        $points = [];
        foreach (is_array($data['points'] ?? null) ? $data['points'] : [] as $point) {
            if (!is_array($point) || !is_string($point['at'] ?? null)) {
                continue;
            }
            try {
                $at = new \DateTimeImmutable($point['at'], new \DateTimeZone('UTC'));
            } catch (\Exception) {
                continue;
            }
            $points[] = [$at, $point];
        }
        if ($points === []) {
            return new self($period, [], 0, null, null);
        }
        usort($points, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $last = end($points)[0];
        $span = self::SPANS[$period] ?? self::SPANS['day'];
        $from = $last->getTimestamp() - $span;
        $number = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? (float) $value : null;
        $lines = [];
        foreach ($points as [$at, $point]) {
            $x = max(0.0, min(1.0, ($at->getTimestamp() - $from) / $span));
            $load = $number($point['load1'] ?? null);
            $cores = $number($point['cpu_cores'] ?? null);
            if ($load !== null && $cores !== null && $cores > 0) {
                $lines['load'][] = [$x, min(100.0, $load / $cores * 100)];
            }
            $used = $number($point['memory_used_bytes'] ?? null);
            $total = $number($point['memory_total_bytes'] ?? null);
            if ($used !== null && $total !== null && $total > 0) {
                $lines['memory'][] = [$x, $used / $total * 100];
            }
            foreach (is_array($point['disk'] ?? null) ? $point['disk'] : [] as $disk) {
                $diskUsed = is_array($disk) ? $number($disk['used_bytes'] ?? null) : null;
                $diskTotal = is_array($disk) ? $number($disk['total_bytes'] ?? null) : null;
                if (is_string($disk['mount'] ?? null) && $diskUsed !== null && $diskTotal !== null && $diskTotal > 0) {
                    $lines['disk:'.$disk['mount']][] = [$x, $diskUsed / $diskTotal * 100];
                }
            }
        }

        return new self($period, $lines, count($points), $points[0][0]->format('Y-m-d H:i:s'), $last->format('Y-m-d H:i:s'));
    }

    /** The points of a line as an SVG polyline in a box of this size, 0% at the bottom. */
    public static function polyline(array $points, int $width, int $height): string
    {
        return implode(' ', array_map(
            static fn (array $point): string => number_format($point[0] * $width, 1, '.', '').','.number_format($height - min(100.0, max(0.0, $point[1])) / 100 * $height, 1, '.', ''),
            $points,
        ));
    }
}
