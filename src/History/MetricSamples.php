<?php

declare(strict_types=1);

namespace Larena\Env\History;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Larena\Env\Server\ServerMetrics;

/**
 * The server's load over time (Panel plan 4.6): the panel keeps what the server measured each time it checked
 * (`larena:env-check`, every 5 minutes) for 30 days. The server itself keeps no history; this is the panel's own.
 */
final readonly class MetricSamples
{
    public const string TABLE = 'larena_env_metric_samples';
    public const int RETENTION_DAYS = 30;

    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function record(ServerMetrics $metrics, DateTimeImmutable $at): void
    {
        $this->connection->table(self::TABLE)->insert([
            'sampled_at' => self::format($at),
            'cores' => $metrics->cores,
            'load1' => $metrics->load[0] ?? null,
            'memory_total' => $metrics->memoryTotal,
            'memory_used' => $metrics->memoryUsed,
            'swap_total' => $metrics->swapTotal,
            'swap_used' => $metrics->swapUsed,
            'disks' => json_encode($metrics->disks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Removes what is older than the panel keeps; answers how many went. */
    public function prune(DateTimeImmutable $now, int $days = self::RETENTION_DAYS): int
    {
        return $this->connection->table(self::TABLE)->where('sampled_at', '<', self::format($now->modify('-'.$days.' days')))->delete();
    }

    /**
     * The last day as measured, or the last week by the hour: each line in percent of its whole.
     *
     * @param 'day'|'week' $period
     */
    public function history(string $period, DateTimeImmutable $now): MetricHistory
    {
        $from = $now->modify($period === 'week' ? '-7 days' : '-1 day');
        $rows = $this->connection->table(self::TABLE)
            ->where('sampled_at', '>=', self::format($from))
            ->where('sampled_at', '<=', self::format($now))
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'cores', 'load1', 'memory_total', 'memory_used', 'disks']);
        $buckets = [];
        foreach ($rows as $row) {
            $at = new DateTimeImmutable((string) $row->sampled_at, new DateTimeZone('UTC'));
            $key = $period === 'week' ? $at->format('Y-m-d H') : $at->format('Y-m-d H:i');
            $values = [];
            if (is_numeric($row->cores) && (int) $row->cores > 0 && is_numeric($row->load1)) {
                $values['load'] = min(100.0, (float) $row->load1 / (int) $row->cores * 100);
            }
            if (is_numeric($row->memory_total) && (int) $row->memory_total > 0 && is_numeric($row->memory_used)) {
                $values['memory'] = (int) $row->memory_used / (int) $row->memory_total * 100;
            }
            $disks = json_decode((string) $row->disks, true);
            foreach (is_array($disks) ? $disks : [] as $disk) {
                if (is_array($disk) && is_string($disk['mount'] ?? null) && is_int($disk['total'] ?? null) && $disk['total'] > 0 && is_int($disk['used'] ?? null)) {
                    $values['disk:'.$disk['mount']] = $disk['used'] / $disk['total'] * 100;
                }
            }
            $buckets[$key]['at'] ??= $at;
            foreach ($values as $line => $value) {
                $buckets[$key]['values'][$line][] = $value;
            }
        }
        $span = max(1, $now->getTimestamp() - $from->getTimestamp());
        $lines = [];
        foreach ($buckets as $bucket) {
            $x = ($bucket['at']->getTimestamp() - $from->getTimestamp()) / $span;
            foreach ($bucket['values'] ?? [] as $line => $values) {
                $lines[$line][] = [max(0.0, min(1.0, $x)), array_sum($values) / count($values)];
            }
        }

        return new MetricHistory($period, $lines, count($rows),
            $rows->isEmpty() ? null : (string) $rows->first()->sampled_at, $rows->isEmpty() ? null : (string) $rows->last()->sampled_at);
    }

    private static function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
