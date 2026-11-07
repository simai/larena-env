<?php

declare(strict_types=1);

namespace Larena\Env\Setup;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * The server's first setup (ENV task 2026-11-06): six steps on the Framework's wizard, each done inside it
 * (ServerSetupController), each of them can be put off; what is left stays among the overview's recommended actions.
 * A step the server already shows as done needs no mark.
 */
final readonly class ServerSetup
{
    public const string TABLE = 'larena_env_setup';

    /** The steps in order, each with the screen that has more of the same. */
    public const array STEPS = [
        'login' => 'larena.auth.admin.security.index',
        'address' => 'larena.env.admin.panel.access',
        'alerts' => 'larena.env.admin.alerts.channel',
        'protection' => 'larena.env.admin.security',
        'backups' => 'larena.env.admin.storages',
        'updates' => 'larena.env.admin.update',
    ];

    public const string DONE = 'done';
    public const string SKIPPED = 'skipped';
    public const string TODO = 'todo';
    private const string OFFERED = '_offered';

    public function __construct(private ConnectionInterface $connection)
    {
    }

    /** @return array<string, string> the marks people left: step => done|skipped */
    public function marks(): array
    {
        $marks = [];
        foreach ($this->connection->table(self::TABLE)->get() as $row) {
            $step = (string) $row->step;
            if (array_key_exists($step, self::STEPS)) {
                $marks[$step] = (string) $row->state;
            }
        }

        return $marks;
    }

    public function mark(string $step, string $state, DateTimeImmutable $now): void
    {
        if (!array_key_exists($step, self::STEPS) || !in_array($state, [self::DONE, self::SKIPPED], true)) {
            return;
        }
        $this->put($step, $state, $now);
    }

    /** Whether the setup was offered already; the first call says no and remembers it, so the first sign-in gets it once. */
    public function offerOnce(DateTimeImmutable $now): bool
    {
        if ($this->connection->table(self::TABLE)->where('step', self::OFFERED)->exists()) {
            return false;
        }
        $this->put(self::OFFERED, 'yes', $now);

        return true;
    }

    /**
     * Each step's state: done when the server shows it done or a person marked it, else the person's mark or to do.
     *
     * @param array<string, string> $marks
     * @param array<string, bool> $doneOnServer step => the server already has it
     *
     * @return array<string, string>
     */
    public static function states(array $marks, array $doneOnServer): array
    {
        $states = [];
        foreach (array_keys(self::STEPS) as $step) {
            $states[$step] = ($doneOnServer[$step] ?? false) ? self::DONE : ($marks[$step] ?? self::TODO);
        }

        return $states;
    }

    /** @param array<string, string> $states */
    public static function next(array $states, ?string $after = null): ?string
    {
        $passed = $after === null;
        foreach ($states as $step => $state) {
            if ($passed && $state === self::TODO) {
                return $step;
            }
            $passed = $passed || $step === $after;
        }

        return null;
    }

    private function put(string $step, string $state, DateTimeImmutable $now): void
    {
        $this->connection->table(self::TABLE)->updateOrInsert(['step' => $step],
            ['state' => $state, 'marked_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    }
}
