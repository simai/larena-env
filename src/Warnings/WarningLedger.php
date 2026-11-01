<?php

declare(strict_types=1);

namespace Larena\Env\Warnings;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Which warnings the panel's check has already told people about (Panel plan 4.5). A warning is told when it first
 * appears, again once a day while it lasts, and at once when it gets worse; one that is gone is forgotten, so its
 * return is news again.
 */
final readonly class WarningLedger
{
    public const string TABLE = 'larena_env_warnings';
    public const int REMIND_HOURS = 24;

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Keeps the warnings seen now and answers the ones to tell people about.
     *
     * @param list<PanelWarning> $warnings
     * @param bool $remind whether one that lasts is told again each day; the server's alerts are told once per state
     *
     * @return list<PanelWarning>
     */
    public function due(array $warnings, DateTimeImmutable $now, bool $remind = true): array
    {
        $at = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $remindBefore = $now->modify('-'.self::REMIND_HOURS.' hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $known = [];
        foreach ($this->connection->table(self::TABLE)->get() as $row) {
            $known[(string) $row->warning_key] = $row;
        }
        $due = [];
        $seen = [];
        foreach ($warnings as $warning) {
            $key = $warning->key();
            $seen[] = $key;
            $row = $known[$key] ?? null;
            $worse = $row !== null && (string) $row->severity !== PanelWarning::ERROR && $warning->severity === PanelWarning::ERROR;
            $tell = $row === null || $worse || ($remind && (string) $row->notified_at < $remindBefore);
            if ($tell) {
                $due[] = $warning;
            }
            $this->connection->table(self::TABLE)->updateOrInsert(['warning_key' => $key], [
                'kind' => $warning->kind,
                'severity' => $warning->severity,
                'domain' => $warning->domain,
                'first_seen_at' => $row === null ? $at : (string) $row->first_seen_at,
                'last_seen_at' => $at,
                'notified_at' => $tell ? $at : (string) $row->notified_at,
            ]);
        }
        $gone = array_diff(array_keys($known), $seen);
        if ($gone !== []) {
            $this->connection->table(self::TABLE)->whereIn('warning_key', array_values($gone))->delete();
        }

        return $due;
    }
}
