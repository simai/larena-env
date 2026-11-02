<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Larena\Env\History\MetricHistory;
use Larena\Env\Server\Bytes;
use Larena\Env\Server\ServerMetrics;
use Larena\Env\Warnings\PanelWarnings;

/**
 * The server's measures as the overview's tiles: processor, memory, swap and each disk, each in percent with the colour
 * of its threshold (the same ones the warnings use) and its line over the day where the history has one.
 */
final class ServerMetricTiles
{
    public const int LOAD_PERCENT = 90;
    public const int LOAD_ERROR_PERCENT = 100;
    public const int MEMORY_PERCENT = 90;
    public const int MEMORY_ERROR_PERCENT = 95;

    private function __construct()
    {
    }

    /**
     * @param callable(string, array<string, string>=): string $text
     *
     * @return list<array{key: string, label: string, value: string, percent: ?int, tone: string, points: ?list<array{0: float, 1: float}>, sub: string}>
     */
    public static function of(ServerMetrics $metrics, ?MetricHistory $day, callable $text): array
    {
        $lines = $day->lines ?? [];
        $tiles = [];
        if ($metrics->load !== null) {
            $percent = $metrics->loadPercent();
            $tiles[] = ['key' => 'load', 'label' => $text('metrics.processor'), 'value' => $percent === null ? number_format($metrics->load[0], 2) : $percent.'%',
                'percent' => $percent, 'tone' => ServerMetrics::tone($percent, self::LOAD_PERCENT, self::LOAD_ERROR_PERCENT), 'points' => $lines['load'] ?? null,
                'sub' => $text('metrics.load_of', ['load' => implode(' · ', array_map(static fn (float $value): string => number_format($value, 2), $metrics->load)),
                    'cores' => (string) ($metrics->cores ?? '—')])];
        }
        if ($metrics->memoryTotal !== null && $metrics->memoryUsed !== null && $metrics->memoryTotal > 0) {
            $tiles[] = self::share('memory', $text('metrics.memory'), $metrics->memoryUsed, $metrics->memoryTotal, self::MEMORY_PERCENT, self::MEMORY_ERROR_PERCENT,
                $lines['memory'] ?? null, $text);
        }
        if ($metrics->swapTotal !== null && $metrics->swapUsed !== null && $metrics->swapTotal > 0) {
            $tiles[] = self::share('swap', $text('metrics.swap'), $metrics->swapUsed, $metrics->swapTotal, PanelWarnings::SWAP_PERCENT, PanelWarnings::SWAP_ERROR_PERCENT, null, $text);
        }
        foreach ($metrics->disks as $disk) {
            $tile = self::share('disk:'.$disk['mount'], $text('metrics.disk', ['mount' => $disk['mount']]), $disk['used'], $disk['total'],
                PanelWarnings::DISK_PERCENT, PanelWarnings::DISK_ERROR_PERCENT, $lines['disk:'.$disk['mount']] ?? null, $text);
            $tile['sub'] .= ' · '.$text('metrics.free', ['free' => Bytes::human(max(0, $disk['total'] - $disk['used']))]);
            $tiles[] = $tile;
        }

        return $tiles;
    }

    /**
     * @param callable(string, array<string, string>=): string $text
     * @param list<array{0: float, 1: float}>|null $points
     *
     * @return array{key: string, label: string, value: string, percent: ?int, tone: string, points: ?list<array{0: float, 1: float}>, sub: string}
     */
    private static function share(string $key, string $label, int $used, int $total, int $warning, int $error, ?array $points, callable $text): array
    {
        $percent = $total > 0 ? Bytes::percent($used, $total) : null;

        return ['key' => $key, 'label' => $label, 'value' => $percent === null ? '—' : $percent.'%', 'percent' => $percent,
            'tone' => ServerMetrics::tone($percent, $warning, $error), 'points' => $points,
            'sub' => $text('metrics.of', ['used' => Bytes::human($used), 'total' => Bytes::human($total)])];
    }
}
