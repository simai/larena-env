<?php

declare(strict_types=1);

namespace Larena\Env\History;

use Larena\Env\Server\ServerMetrics;

/**
 * The load history as one chart per measure, the way a hosting provider's dashboard draws it (owner 2026-11-06, after
 * Timeweb's): the processor in percent of its cores, the memory and each disk in gigabytes of the whole, each with its
 * scale in its own units and the time along the bottom, for the period chosen above the charts. The server keeps a day
 * in 5-minute points and a week or a month in hourly ones (simai-metrics-history/1); an hour and six hours are the day's
 * last points. The scale is fixed (0 to the whole) or follows the values, so small changes show.
 */
final readonly class HistoryCharts
{
    /** The periods a person may choose, their length in seconds and the server's period they are read from. */
    public const array PERIODS = [
        'hour' => [3_600, 'day'],
        'six_hours' => [21_600, 'day'],
        'day' => [86_400, 'day'],
        'week' => [604_800, 'week'],
        'month' => [2_592_000, 'month'],
    ];
    public const array SCALES = ['fixed', 'auto'];
    public const int WIDTH = 600;
    public const int HEIGHT = 160;
    private const float GIB = 1_073_741_824.0;

    /**
     * @param list<array{key: string, title: string, capacity: ?string, unit: string, ticks: list<string>, times: list<string>,
     *     line: string, area: string, points: list<array{x: float, y: float, label: string}>, last: string}> $charts
     */
    public function __construct(
        public string $period,
        public string $scale,
        public array $charts,
        public int $count,
        public ?string $from,
        public ?string $to,
    ) {
    }

    public static function period(mixed $asked): string
    {
        return is_string($asked) && isset(self::PERIODS[$asked]) ? $asked : 'day';
    }

    public static function scale(mixed $asked): string
    {
        return is_string($asked) && in_array($asked, self::SCALES, true) ? $asked : 'fixed';
    }

    /**
     * @param array<array-key, mixed> $data the server's history for the period's source (day, week or month)
     * @param callable(string, array<string, string>): string $text the panel's words by key under `history.`
     */
    public static function fromServer(string $period, string $scale, array $data, callable $text): self
    {
        $period = self::period($period);
        $scale = self::scale($scale);
        $span = self::PERIODS[$period][0];
        $points = [];
        foreach (is_array($data['points'] ?? null) ? $data['points'] : [] as $point) {
            if (!is_array($point) || !is_string($point['at'] ?? null)) {
                continue;
            }
            try {
                $at = (new \DateTimeImmutable($point['at'], new \DateTimeZone('UTC')))->getTimestamp();
            } catch (\Exception) {
                continue;
            }
            $points[] = [$at, $point];
        }
        if ($points === []) {
            return new self($period, $scale, [], 0, null, null);
        }
        usort($points, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $end = end($points)[0];
        $start = $end - $span;
        $points = array_values(array_filter($points, static fn (array $point): bool => $point[0] >= $start));

        $number = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? (float) $value : null;
        $series = ['load' => [], 'memory' => []];
        $whole = ['load' => null, 'memory' => null];
        foreach ($points as [$at, $point]) {
            $load = $number($point['load1'] ?? null);
            $cores = $number($point['cpu_cores'] ?? null);
            if ($load !== null && $cores !== null && $cores > 0) {
                $series['load'][] = [$at, min(100.0, $load / $cores * 100)];
                $whole['load'] = $cores;
            }
            $used = $number($point['memory_used_bytes'] ?? null);
            $total = $number($point['memory_total_bytes'] ?? null);
            if ($used !== null && $total !== null && $total > 0) {
                $series['memory'][] = [$at, $used / self::GIB];
                $whole['memory'] = $total / self::GIB;
            }
            foreach (is_array($point['disk'] ?? null) ? $point['disk'] : [] as $disk) {
                $diskUsed = is_array($disk) ? $number($disk['used_bytes'] ?? null) : null;
                $diskTotal = is_array($disk) ? $number($disk['total_bytes'] ?? null) : null;
                if (is_string($disk['mount'] ?? null) && ServerMetrics::ownMount($disk['mount']) && $diskUsed !== null && $diskTotal !== null && $diskTotal > 0) {
                    $series['disk:'.$disk['mount']][] = [$at, $diskUsed / self::GIB];
                    $whole['disk:'.$disk['mount']] = $diskTotal / self::GIB;
                }
            }
        }

        $charts = [];
        foreach ($series as $key => $values) {
            if ($values === []) {
                continue;
            }
            $percent = $key === 'load';
            $capacity = $whole[$key] ?? null;
            $fixedTop = $percent ? 100.0 : ($capacity ?? 1.0);
            $top = $scale === 'fixed' ? $fixedTop : self::niceTop(max(array_column($values, 1)), $fixedTop);
            $unit = $percent ? '%' : (string) $text('history.gb', []);
            $format = static fn (float $value): string => $percent
                ? (string) round($value).' %'
                : self::number($value, $value < 10 ? 1 : 0).' '.$unit;
            $title = $key === 'load' ? $text('history.load', []) : ($key === 'memory' ? $text('history.memory', [])
                : $text('history.disk', ['mount' => substr($key, 5)]));
            $capacityText = $capacity === null ? null : ($percent
                ? $text('history.cores', ['count' => (string) (int) $capacity])
                : self::number($capacity, $capacity < 10 ? 1 : 0).' '.$unit);
            $xy = [];
            foreach ($values as [$at, $value]) {
                $xy[] = [
                    'x' => round(max(0.0, min(1.0, ($at - $start) / $span)) * self::WIDTH, 1),
                    'y' => round(self::HEIGHT - max(0.0, min(1.0, $value / $top)) * self::HEIGHT, 1),
                    'label' => gmdate('d.m H:i', $at).' UTC · '.$format($value),
                ];
            }
            $line = implode(' ', array_map(static fn (array $p): string => $p['x'].','.$p['y'], $xy));
            $first = $xy[0]['x'];
            $lastX = $xy[count($xy) - 1]['x'];
            $charts[] = [
                'key' => $key,
                'title' => $title,
                'capacity' => $capacityText,
                'unit' => $unit,
                // Five steps from the top down to 0, in the chart's units.
                'ticks' => array_map(static fn (int $step): string => $format($top * (4 - $step) / 4), range(0, 4)),
                'times' => self::times($start, $span, $period),
                'line' => $line,
                'area' => $first.','.self::HEIGHT.' '.$line.' '.$lastX.','.self::HEIGHT,
                'points' => $xy,
                'last' => $format(end($values)[1]),
            ];
        }

        return new self($period, $scale, $charts, count($points), gmdate('Y-m-d H:i:s', $points[0][0]), gmdate('Y-m-d H:i:s', $end));
    }

    /** A round top a little above the highest value, never above the whole. */
    private static function niceTop(float $highest, float $whole): float
    {
        if ($highest <= 0.0) {
            return min($whole, 1.0);
        }
        $raw = $highest * 1.15;
        $magnitude = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($step * $magnitude >= $raw) {
                return min($whole, $step * $magnitude);
            }
        }

        return $whole;
    }

    /** @return list<string> six times along the bottom, UTC */
    private static function times(int $start, int $span, string $period): array
    {
        return array_map(static fn (int $step): string => self::time($start + intdiv($span * $step, 5), $period), range(0, 5));
    }

    private static function time(int $at, string $period): string
    {
        return gmdate(in_array($period, ['week', 'month'], true) ? 'd.m' : 'H:i', $at);
    }

    private static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', ' ');
    }
}
