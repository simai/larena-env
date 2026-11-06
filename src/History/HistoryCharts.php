<?php

declare(strict_types=1);

namespace Larena\Env\History;

use Larena\Env\Server\ServerMetrics;

/**
 * The load history as one chart per measure, the way a hosting provider's dashboard draws it (owner 2026-11-06, after
 * Timeweb's): the processor in percent, the memory and each disk in gigabytes of the whole, the network in bits a second
 * in and out, the disk's reading and writing in bytes a second; each with its scale in its own units and the time along
 * the bottom, for the period chosen above the charts. The server keeps an hour in 1-minute points, six hours and a day in
 * 5-minute ones, a week or a month in hourly ones (simai-metrics-history/1, simai-env 1.50.0); an older server's hour
 * and six hours are its day's last points. The scale is fixed (0 to the whole) or follows the values, so small changes
 * show; a rate has no whole but the network's link, so it follows its values unless the link is known.
 */
final readonly class HistoryCharts
{
    /** The periods a person may choose: their length in seconds, the server's period they are read from, and the one before 1.50.0. */
    public const array PERIODS = [
        'hour' => [3_600, 'hour', 'day'],
        'six_hours' => [21_600, '6h', 'day'],
        'day' => [86_400, 'day', 'day'],
        'week' => [604_800, 'week', 'week'],
        'month' => [2_592_000, 'month', 'month'],
    ];
    public const array SCALES = ['fixed', 'auto'];
    public const int WIDTH = 600;
    public const int HEIGHT = 160;
    private const float GIB = 1_073_741_824.0;

    /**
     * @param list<array{key: string, title: string, capacity: ?string, unit: string, ticks: list<string>, times: list<string>,
     *     series: list<array{key: string, name: ?string, line: string, area: string}>, hovers: list<array{x: float, label: string}>,
     *     last: string}> $charts
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

    /** @param array<array-key, mixed>|null $data */
    public static function hasPoints(?array $data): bool
    {
        return is_array($data['points'] ?? null) && $data['points'] !== [];
    }

    /**
     * @param array<array-key, mixed> $data the server's history for the period's source
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
        $host = is_array($data['host'] ?? null) ? $data['host'] : [];

        $number = static fn (mixed $value): ?float => is_int($value) || is_float($value) ? (float) $value : null;
        // The processor's busy share where the server measures it (1.50.0), else its load in percent of its cores.
        $busy = array_filter($points, static fn (array $point): bool => $number($point[1]['cpu_percent'] ?? null) !== null) !== [];
        $series = ['load' => ['' => []], 'memory' => ['' => []]];
        $whole = ['load' => null, 'memory' => null];
        foreach ($points as [$at, $point]) {
            $cores = $number($point['cpu_cores'] ?? null);
            if ($cores !== null && $cores > 0) {
                $whole['load'] = $cores;
            }
            $value = $busy ? $number($point['cpu_percent'] ?? null)
                : (($load = $number($point['load1'] ?? null)) !== null && $cores !== null && $cores > 0 ? $load / $cores * 100 : null);
            if ($value !== null) {
                $series['load'][''][] = [$at, max(0.0, min(100.0, $value))];
            }
            $used = $number($point['memory_used_bytes'] ?? null);
            $total = $number($point['memory_total_bytes'] ?? null);
            if ($used !== null && $total !== null && $total > 0) {
                $series['memory'][''][] = [$at, $used / self::GIB];
                $whole['memory'] = $total / self::GIB;
            }
            foreach (is_array($point['disk'] ?? null) ? $point['disk'] : [] as $disk) {
                $diskUsed = is_array($disk) ? $number($disk['used_bytes'] ?? null) : null;
                $diskTotal = is_array($disk) ? $number($disk['total_bytes'] ?? null) : null;
                if (is_string($disk['mount'] ?? null) && ServerMetrics::ownMount($disk['mount']) && $diskUsed !== null && $diskTotal !== null && $diskTotal > 0) {
                    $series['disk:'.$disk['mount']][''][] = [$at, $diskUsed / self::GIB];
                    $whole['disk:'.$disk['mount']] = $diskTotal / self::GIB;
                }
            }
            foreach (['network' => ['in' => 'net_rx_bytes_per_s', 'out' => 'net_tx_bytes_per_s'], 'io' => ['read' => 'disk_read_bytes_per_s', 'write' => 'disk_write_bytes_per_s']] as $key => $fields) {
                foreach ($fields as $name => $field) {
                    $rate = $number($point[$field] ?? null);
                    if ($rate !== null && $rate >= 0) {
                        // The network in bits, as links are sold; the disk in bytes.
                        $series[$key][$name][] = [$at, $key === 'network' ? $rate * 8 : $rate];
                    }
                }
            }
        }

        $charts = [];
        foreach ($series as $key => $lines) {
            $lines = array_filter($lines, static fn (array $values): bool => $values !== []);
            if ($lines === []) {
                continue;
            }
            $highest = max(array_map(static fn (array $values): float => max(array_column($values, 1)), $lines));
            [$unit, $divisor, $fixedTop, $title, $capacity] = match (true) {
                $key === 'load' => ['%', 1.0, 100.0, $text('history.load', []),
                    $whole['load'] === null ? null : $text('history.cores', ['count' => (string) (int) $whole['load']])],
                $key === 'network' => self::network($highest, $scale === 'fixed', $host, $text),
                $key === 'io' => self::io($highest, $host, $text),
                default => [$text('history.gb', []), 1.0, $whole[$key] ?? 1.0,
                    $key === 'memory' ? $text('history.memory', []) : $text('history.disk', ['mount' => substr($key, 5)]),
                    isset($whole[$key]) ? self::number($whole[$key], $whole[$key] < 10 ? 1 : 0).' '.$text('history.gb', []) : null],
            };
            $highest /= $divisor;
            $top = $scale === 'fixed' && $fixedTop !== null ? $fixedTop : self::niceTop($highest, $fixedTop ?? INF);
            $format = $unit === '%'
                ? static fn (float $value): string => (string) round($value).' %'
                // A rate too small for the unit still says it is not zero.
                : static fn (float $value): string => ($value > 0.0 && $value < 0.05 ? '< '.self::number(0.1, 1) : self::number($value, $value < 10 && $value > 0.0 ? 1 : 0)).' '.$unit;
            $named = count($lines) > 1;
            $drawn = [];
            $hovers = [];
            foreach ($lines as $name => $values) {
                $xy = [];
                foreach ($values as [$at, $value]) {
                    $value /= $divisor;
                    $x = round(max(0.0, min(1.0, ($at - $start) / $span)) * self::WIDTH, 1);
                    $xy[] = $x.','.round(self::HEIGHT - max(0.0, min(1.0, $value / $top)) * self::HEIGHT, 1);
                    $hovers[$at]['x'] = $x;
                    $hovers[$at]['parts'][] = ($named ? $text('history.'.$name, []).' ' : '').$format($value);
                }
                $first = strtok($xy[0], ',');
                $last = strtok($xy[count($xy) - 1], ',');
                $line = implode(' ', $xy);
                $drawn[] = [
                    'key' => $name === '' ? $key : (string) $name,
                    'name' => $named ? $text('history.'.$name, []) : null,
                    'line' => $line,
                    'area' => $first.','.self::HEIGHT.' '.$line.' '.$last.','.self::HEIGHT,
                    'now' => ($named ? $text('history.'.$name, []).' ' : '').$format(end($values)[1] / $divisor),
                ];
            }
            ksort($hovers);
            $charts[] = [
                'key' => $key,
                'title' => $title,
                'capacity' => $capacity,
                'unit' => $unit,
                // Five steps from the top down to 0, in the chart's units, with as many decimals as the step needs.
                'ticks' => array_map(static fn (int $step): string => $unit === '%' ? $format($top * (4 - $step) / 4)
                    : self::number($top * (4 - $step) / 4, $step === 4 ? 0 : max(0, min(2, (int) -floor(log10($top / 4))))).' '.$unit, range(0, 4)),
                'times' => self::times($start, $span, $period),
                'series' => array_map(static fn (array $one): array => array_diff_key($one, ['now' => true]), $drawn),
                'hovers' => array_map(static fn (int $at, array $hover): array => ['x' => $hover['x'], 'label' => gmdate('d.m H:i', $at).' UTC · '.implode(' · ', $hover['parts'])],
                    array_keys($hovers), $hovers),
                'last' => implode(' · ', array_column($drawn, 'now')),
            ];
        }

        return new self($period, $scale, $charts, count($points), gmdate('Y-m-d H:i:s', $points[0][0]), gmdate('Y-m-d H:i:s', $end));
    }

    /**
     * The network in megabits or kilobits a second, its whole the link where the server knows it.
     *
     * @param array<array-key, mixed> $host
     * @return array{string, float, ?float, string, ?string}
     */
    private static function network(float $highest, bool $fixed, array $host, callable $text): array
    {
        $link = is_int($host['net_link_bits_per_s'] ?? null) && $host['net_link_bits_per_s'] > 0 ? (float) $host['net_link_bits_per_s'] : null;
        // The unit follows what is drawn: the link on the fixed scale, the values on theirs.
        $mega = max($highest, $fixed ? ($link ?? 0.0) : 0.0) >= 1_000_000;
        $unit = $text($mega ? 'history.mbit' : 'history.kbit', []);
        $divisor = $mega ? 1_000_000.0 : 1_000.0;
        $interface = is_string($host['net_interface'] ?? null) && preg_match('/\A[A-Za-z0-9._@-]{1,32}\z/D', $host['net_interface']) === 1 ? $host['net_interface'] : null;
        $capacity = $link === null ? $interface : trim(($interface ?? '').' '.$text('history.link', ['speed' => self::number($link / $divisor, 0).' '.$unit]));

        return [$unit, $divisor, $link === null ? null : $link / $divisor, $text('history.network', []), $capacity];
    }

    /**
     * The disk's reading and writing in megabytes or kilobytes a second; a disk has no whole to draw against.
     *
     * @param array<array-key, mixed> $host
     * @return array{string, float, ?float, string, ?string}
     */
    private static function io(float $highest, array $host, callable $text): array
    {
        $mega = $highest >= 1_048_576;
        $kind = is_string($host['disk_kind'] ?? null) && in_array($host['disk_kind'], ['nvme', 'ssd', 'hdd', 'virtual'], true) ? $host['disk_kind'] : null;
        $device = is_string($host['disk_device'] ?? null) && preg_match('/\A[A-Za-z0-9._-]{1,32}\z/D', $host['disk_device']) === 1 ? $host['disk_device'] : null;
        $capacity = trim(($kind === null ? '' : $text('history.kinds.'.$kind, [])).' '.($device ?? ''));

        return [$text($mega ? 'history.mbs' : 'history.kbs', []), $mega ? 1_048_576.0 : 1_024.0, null, $text('history.io', []), $capacity === '' ? null : $capacity];
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
