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

    /** The points of a line as an SVG polyline in a box of this size, 0% at the bottom. */
    public static function polyline(array $points, int $width, int $height): string
    {
        return implode(' ', array_map(
            static fn (array $point): string => number_format($point[0] * $width, 1, '.', '').','.number_format($height - min(100.0, max(0.0, $point[1])) / 100 * $height, 1, '.', ''),
            $points,
        ));
    }
}
