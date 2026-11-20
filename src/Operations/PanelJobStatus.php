<?php

declare(strict_types=1);

namespace Larena\Env\Operations;

use UnexpectedValueException;

/** One started action: what it is, who started it and where it stands. */
final readonly class PanelJobStatus
{
    /** @param array<string, string> $args */
    public function __construct(
        public string $jobId,
        public JobState $state,
        public string $command,
        public array $args,
        public ?int $exitCode,
        public ?string $createdAt,
        public ?string $actor,
        // A change the server undoes by itself unless it is kept in time: every timed set command answers its
        // change_id and expires_at in its job's result (the firewall, who reaches the panel, nginx directives).
        public ?\Larena\Env\Firewall\TimedChange $change = null,
        // A value the server gives once, in the first answer after the job ended (wp:install's administrator password,
        // simai-env 1.56.0): shown on that screen only, never kept by the panel. Later answers name it in `shown_once`.
        public ?JobSecret $secret = null,
        /** @var list<string> */
        public array $shownOnce = [],
        // A site's database browser (site:db-browser, simai-env 1.58.0): its address given once, the same way.
        public ?DbBrowserLink $browser = null,
        // The job in words (simai-env 1.60.0): when it started and ended (UTC), its title in the panel's language, what it
        // was over, who started it (a person, a schedule, the server itself), its state in words, its steps from the
        // command's own progress, where it stands («2 из 5: …»), how it ended, and whether it can still be stopped.
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?string $title = null,
        /** @var array{kind: string, id: ?string}|null */
        public ?array $object = null,
        public string $actorKind = 'person',
        public ?string $stateWords = null,
        /** @var list<array{id: string, title: ?string, state: string, started: ?string, finished: ?string}> */
        public array $steps = [],
        /** @var array{current: int, total: int, title: ?string}|null */
        public ?array $progress = null,
        /** @var array{code: string, words: string, reason: ?string}|null */
        public ?array $outcome = null,
        /** @var array{cancellable: bool, leaves: ?string}|null */
        public ?array $cancel = null,
        // A site's database connection (site:db-credentials, simai-env 1.59.0): its password given once, the same way.
        public ?DbCredentials $credentials = null,
        // The files with passwords a pack or unpack left out (file:archive / file:extract `without-secrets`, simai-env
        // 1.62.2): at most 30 paths as the server keeps them, and how many in all.
        /** @var array{paths: list<string>, total: int}|null */
        public ?array $secretSkipped = null,
        // How many replaced files a write, an upload or an unpacking put into the site's trash (simai-env 1.64.0
        // previous_trash_id, trashed / trash_ids): the panel then says the previous version is there.
        public int $replacedIntoTrash = 0,
    ) {
    }

    /** How long it ran, in seconds: to its end, or until now while it runs; null when the server gave no start. */
    public function seconds(?\DateTimeImmutable $now = null): ?int
    {
        $start = self::time($this->startedAt);
        $end = $this->finishedAt !== null ? self::time($this->finishedAt) : ($this->state === JobState::Running ? ($now ?? new \DateTimeImmutable())->getTimestamp() : null);

        return $start === null || $end === null ? null : max(0, $end - $start);
    }

    /** Whether the server says the job can be stopped now; a server before 1.60.0 does not say, and a running job then may be. */
    public function cancellable(): bool
    {
        return $this->state === JobState::Running && ($this->cancel === null || $this->cancel['cancellable']);
    }

    private static function time(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UnexpectedValueException
     */
    public static function fromData(array $data): self
    {
        $jobId = $data['job_id'] ?? null;
        $state = is_string($data['state'] ?? null) ? JobState::tryFrom($data['state']) : null;
        $command = $data['command'] ?? null;
        if (!is_string($jobId) || $state === null || !is_string($command)) {
            throw new UnexpectedValueException('job_field_invalid');
        }
        $args = [];
        foreach (is_array($data['args'] ?? null) ? $data['args'] : [] as $name => $value) {
            if (is_string($value)) {
                $args[(string) $name] = $value;
            }
        }
        $actor = is_array($data['actor'] ?? null) ? ($data['actor']['user'] ?? null) : null;

        return new self(
            jobId: $jobId,
            state: $state,
            command: $command,
            args: $args,
            exitCode: is_int($data['exit_code'] ?? null) ? $data['exit_code'] : null,
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : null,
            actor: is_string($actor) ? $actor : null,
            change: is_array($data['result'] ?? null) ? \Larena\Env\Firewall\TimedChange::fromData($data['result']) : null,
            secret: is_array($data['result'] ?? null) ? JobSecret::fromData($data['result']) : null,
            shownOnce: is_array($data['result'] ?? null) && is_array($data['result']['shown_once'] ?? null)
                ? array_values(array_filter($data['result']['shown_once'], 'is_string')) : [],
            browser: is_array($data['result'] ?? null) ? DbBrowserLink::fromData($data['result']) : null,
            startedAt: self::moment($data['started_at'] ?? null),
            finishedAt: self::moment($data['finished_at'] ?? null),
            title: self::words($data['title'] ?? null),
            object: is_array($data['object'] ?? null) && is_string($data['object']['kind'] ?? null)
                ? ['kind' => $data['object']['kind'], 'id' => is_string($data['object']['id'] ?? null) ? $data['object']['id'] : null] : null,
            actorKind: is_array($data['actor'] ?? null) && in_array($data['actor']['kind'] ?? null, ['person', 'schedule', 'server'], true) ? $data['actor']['kind'] : 'person',
            stateWords: self::words($data['state_words'] ?? null),
            steps: self::steps($data['steps'] ?? null),
            progress: self::progress($data['progress'] ?? null),
            outcome: is_array($data['outcome'] ?? null) && is_string($data['outcome']['code'] ?? null)
                ? ['code' => $data['outcome']['code'], 'words' => self::words($data['outcome']['words'] ?? null) ?? $data['outcome']['code'],
                    'reason' => self::words($data['outcome']['reason'] ?? null)] : null,
            cancel: is_array($data['cancel'] ?? null) && is_bool($data['cancel']['cancellable'] ?? null)
                ? ['cancellable' => $data['cancel']['cancellable'], 'leaves' => self::words($data['cancel']['leaves'] ?? null)] : null,
            credentials: is_array($data['result'] ?? null) ? DbCredentials::fromData($data['result']) : null,
            secretSkipped: is_array($data['result'] ?? null) ? self::secretSkipped($data['result']) : null,
            replacedIntoTrash: is_array($data['result'] ?? null) ? self::replacedIntoTrash($data['result']) : 0,
        );
    }

    /** @param array<array-key, mixed> $result */
    private static function replacedIntoTrash(array $result): int
    {
        if (is_string($result['previous_trash_id'] ?? null) && $result['previous_trash_id'] !== '') {
            return 1;
        }
        if (is_array($result['trash_ids'] ?? null)) {
            return count(array_filter($result['trash_ids'], 'is_string'));
        }

        return is_int($result['trashed'] ?? null) ? max(0, $result['trashed']) : 0;
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array{paths: list<string>, total: int}|null
     */
    private static function secretSkipped(array $result): ?array
    {
        if (!is_array($result['secret_skipped'] ?? null)) {
            return null;
        }
        $paths = array_values(array_filter(array_slice($result['secret_skipped'], 0, 30),
            static fn (mixed $path): bool => is_string($path) && $path !== '' && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1));
        $total = is_int($result['secret_skipped_total'] ?? null) ? max($result['secret_skipped_total'], count($paths)) : count($paths);

        return ['paths' => array_map(static fn (string $path): string => mb_substr($path, 0, 200), $paths), 'total' => $total];
    }

    private static function words(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 500) : null;
    }

    /** An ISO 8601 moment as the server writes it (`2026-11-18T10:00:00Z`), else null. */
    private static function moment(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})\z/D', $value) === 1 ? $value : null;
    }

    /** @return list<array{id: string, title: ?string, state: string, started: ?string, finished: ?string}> */
    private static function steps(mixed $steps): array
    {
        $kept = [];
        foreach (is_array($steps) ? array_slice($steps, 0, 200) : [] as $index => $step) {
            if (!is_array($step) || !in_array($step['state'] ?? null, ['waiting', 'running', 'done', 'failed', 'skipped'], true)) {
                continue;
            }
            $id = $step['id'] ?? $index + 1;
            $kept[] = ['id' => is_int($id) || is_string($id) ? (string) $id : (string) ($index + 1), 'title' => self::words($step['title'] ?? null),
                'state' => $step['state'], 'started' => self::moment($step['started_at'] ?? null), 'finished' => self::moment($step['finished_at'] ?? null)];
        }

        return $kept;
    }

    /** @return array{current: int, total: int, title: ?string}|null */
    private static function progress(mixed $progress): ?array
    {
        if (!is_array($progress) || !is_int($progress['current'] ?? null) || !is_int($progress['total'] ?? null) || $progress['total'] < 1) {
            return null;
        }

        return ['current' => max(0, min($progress['current'], $progress['total'])), 'total' => $progress['total'], 'title' => self::words($progress['title'] ?? null)];
    }
}
