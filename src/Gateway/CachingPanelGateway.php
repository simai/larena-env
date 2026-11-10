<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Illuminate\Contracts\Cache\Repository;
use Larena\Env\Contracts\PanelBatchGateway;
use Larena\Env\Contracts\PanelFileGateway;
use Larena\Env\Contracts\PanelGateway;
use Throwable;

/**
 * The server's answers to what it is, kept for a short while (owner decision 2026-11-05: a click answers within half a
 * second). Every screen of the panel asks the server again, and each ask starts simai-env, so moving between the tabs
 * of a site waited seconds for the same answers. Here a read of the server's state is answered from the cache for
 * `ttl` seconds per person (an owner sees other answers than the admin); the same read twice in one page is asked
 * once; the slow reads that change only through an action (the programs, PHP, the update's plan) are kept for
 * `slowTtl` seconds. Anything that may change the server, a confirmed action or a look at a job, makes every kept answer stale, so
 * the screen after an action reads the server again. Logs, files' contents, job runs and the progress of a change are
 * never kept. A cache that fails is passed by: the server is asked as before.
 */
final class CachingPanelGateway implements PanelGateway, PanelFileGateway
{
    /** The reads whose answer is the server's state, kept for the ttl. */
    public const array KEPT = [
        'self:describe', 'self:commands', 'self:metrics', 'self:alerts', 'self:metrics-history', 'self:update-plan', 'self:profiles',
        'site:describe', 'site:doctor', 'site:php-ini-list', 'site:nginx-custom-show', 'site:names',
        'php:list', 'php:extensions', 'software:list', 'service:list', 'firewall:status', 'access:list', 'db:list', 'cron:list',
        'ssl:dns-status', 'file:list', 'security:status', 'security:files', 'security:quarantine-list', 'security:geo-status', 'mcp:status', 'backup:storage-list', 'panel:address',
        'logs:nginx',
    ];

    /**
     * The reads that are slow on the server (seconds: they ask the package manager or the update source) and change only
     * through an action, which makes them stale at once: kept for `slowTtl` seconds instead of `ttl`.
     */
    public const array SLOW = ['software:list', 'php:list', 'php:extensions', 'self:update-plan', 'self:profiles', 'self:commands'];

    /**
     * The reads kept for a page opened again but not asked again in the background: a log's search reads up to 20000 lines
     * (seconds on the server), worth keeping for a second look, not worth asking every minute (critics 2026-11-07).
     */
    public const array NOT_WARMED = ['logs:nginx'];

    private const string GENERATION = 'larena-env:gateway:generation';

    /** The reads pages asked lately, by person: what the warm-up asks again (simai-env 1.52.0 era, ENV 2026-11-07). */
    private const string READS = 'larena-env:gateway:reads';

    /** At most this many reads are remembered for the warm-up; the least recently used go first. */
    private const int READS_KEPT = 40;

    /** A warmed read lives this long: longer than the minute between two warm-ups, so a page finds it. */
    public const int WARM_SECONDS = 150;

    /** A read is warmed again once it is this old (a slow one, once its own time is nearly over). */
    private const int REWARM_AFTER = 40;

    /**
     * Set for half a minute when the gateway did not answer at all (ssh or sudo failed, or the server hung): the reads
     * after it fail at once instead of each waiting its timeout (critics 2026-11-07: every page waited 30 s).
     */
    private const string DOWN = 'larena-env:gateway:down';

    private const int DOWN_SECONDS = 30;

    /**
     * When the server first stopped answering, kept while it stays silent: the alert bar says since when, not the time
     * of every page (critics of 0.4.1). It outlives the DOWN mark, which lapses every half a minute so the server is
     * tried again, and goes with the first answer.
     */
    private const string SILENT_SINCE = 'larena-env:gateway:silent-since';

    private const int SILENT_SECONDS = 900;

    /**
     * The last good answer of each kept read, beside the short-lived one: when the server does not answer (a nightly
     * backup on a small server made every call take 4-9 s, test server 2026-11-10), a page shows what the server last
     * said instead of «data unavailable», and the alert bar says since when the server is silent and that the pages may
     * be out of date. Keyed by the same generation, so an action made through the panel never shows the state before it.
     */
    private const string LAST_GOOD = 'larena-env:gateway:last-good:';

    public const int LAST_GOOD_SECONDS = 86400;

    /** The reason the gateway gives when it did not answer at all. */
    private const string UNANSWERED = PanelReason::UNANSWERED;

    /**
     * Set for a quarter of an hour by a confirmed action: a job may change the server well after it started, so the slow
     * reads are kept only for `ttl` meanwhile, never for their long time (critics 2026-11-07).
     */
    private const string CHANGING = 'larena-env:gateway:changing';

    private const int CHANGING_SECONDS = 900;

    /**
     * Set for an hour when the server answered that it does not know `batch` (simai-env before 1.52.0); never because
     * a batch went unanswered: that is the server being down, said by DOWN.
     */
    private const string NO_BATCH = 'larena-env:gateway:no-batch';

    /** @var array<string, PanelAnswer> the answers this request already has */
    private array $asked = [];

    public function __construct(
        private readonly PanelGateway $inner,
        private readonly Repository $cache,
        private readonly int $ttl = 30,
        private readonly int $slowTtl = 600,
    ) {
    }

    public function ask(PanelRequest $request): PanelAnswer
    {
        if (!self::keeps($request)) {
            $answer = $this->inner->ask($request);
            if ($request->confirm || PanelCommands::kind($request->command) === PanelCommands::JOB) {
                $this->forgetAll();
            }
            if ($request->confirm) {
                $this->flagFor(self::CHANGING, self::CHANGING_SECONDS);
            }

            return $answer;
        }
        $key = $this->key($request);
        if (isset($this->asked[$key])) {
            return $this->asked[$key];
        }
        $this->remember($request);
        $kept = $this->read($key);
        if ($kept !== null) {
            return $this->asked[$key] = $kept;
        }
        if ($this->flag(self::DOWN)) {
            return $this->lastGood($key) ?? PanelAnswer::failed($request->requestId, self::UNANSWERED);
        }
        $answer = $this->inner->ask($request);
        $this->note($answer);
        // A refusal or a failure is said again on the next screen, never kept.
        if ($answer->status === PanelAnswerStatus::Ok) {
            $this->asked[$key] = $answer;
            $this->write($key, $answer, $this->ttlFor($request->command, $this->ttl));
        } elseif ($answer->reason === self::UNANSWERED && ($last = $this->lastGood($key)) !== null) {
            return $this->asked[$key] = $last;
        }

        return $answer;
    }

    /**
     * Asks the server past the kept answer and keeps the new one: a person's «Обновить» on a log wants what is there
     * now, not the half-minute-old lines (critics 2026-11-07).
     */
    public function fresh(PanelRequest $request): PanelAnswer
    {
        if (!self::keeps($request)) {
            return $this->ask($request);
        }
        $key = $this->key($request);
        $this->remember($request);
        // A silent server is not asked again on a click: the person waits once, not on every «Обновить».
        if ($this->flag(self::DOWN)) {
            return $this->lastGood($key) ?? PanelAnswer::failed($request->requestId, self::UNANSWERED);
        }
        $answer = $this->inner->ask($request);
        $this->note($answer);
        if ($answer->status === PanelAnswerStatus::Ok) {
            $this->asked[$key] = $answer;
            $this->write($key, $answer, $this->ttlFor($request->command, $this->ttl));
        } else {
            unset($this->asked[$key]);
            if ($answer->reason === self::UNANSWERED && ($last = $this->lastGood($key)) !== null) {
                return $last;
            }
        }

        return $answer;
    }

    public function download(PanelRequest $request, string $into): PanelDownload
    {
        if (!$this->inner instanceof PanelFileGateway) {
            return PanelDownload::of(PanelAnswer::failed($request->requestId, PanelReason::NOT_CONFIGURED));
        }

        return $this->inner->download($request, $into);
    }

    public function source(): string
    {
        return $this->inner->source();
    }

    /** Whether the request is a plain read of the server's state. */
    public static function keeps(PanelRequest $request): bool
    {
        return !$request->confirm && $request->attachment === null && in_array($request->command, self::KEPT, true)
            && PanelCommands::kind($request->command) === PanelCommands::READ;
    }

    /**
     * Asks again the reads pages asked within the last `$usedWithin` seconds whose kept answer is getting old, and
     * keeps the new answers for WARM_SECONDS, so the next page is answered at once (ENV 2026-11-07: every ask starts
     * simai-env, 0.7-1 s each). A read nobody looked at lately is dropped. Returns how many reads were asked.
     */
    public function warm(int $usedWithin = 1200, int $limit = 24): int
    {
        $reads = $this->reads();
        $now = time();
        $keep = [];
        /** @var array<string, list<array{id: string, request: PanelRequest, key: string}>> $due by person */
        $due = [];
        $count = 0;
        uasort($reads, static fn (array $a, array $b): int => $b['used'] <=> $a['used']);
        foreach ($reads as $id => $read) {
            if ($read['used'] < $now - $usedWithin) {
                continue;
            }
            $keep[$id] = $read;
            $slow = in_array($read['command'], self::SLOW, true);
            if ($count >= $limit || $now - $read['warmed'] < ($slow ? max($this->ttl, $this->slowTtl) - 2 * self::REWARM_AFTER : self::REWARM_AFTER)) {
                continue;
            }
            try {
                $request = PanelRequest::read(new PanelActor($read['user'], $read['owner']), $read['command'], $read['args']);
            } catch (Throwable) {
                unset($keep[$id]);
                continue;
            }
            // The key is taken before the server is asked: an action meanwhile makes it stale, never the answer fresh.
            $due[$read['user']."\0".($read['owner'] ?? '')][] = ['id' => $id, 'request' => $request, 'key' => $this->key($request)];
            $count++;
        }
        foreach ($due as $person) {
            foreach (array_chunk($person, PanelBatchGateway::MOST) as $chunk) {
                // A server found down by an earlier chunk is not asked again: each ask would wait its timeout.
                if ($this->flag(self::DOWN)) {
                    break 2;
                }
                $answers = $this->askTogether(array_column($chunk, 'request'));
                foreach ($chunk as $index => $one) {
                    if ($answers[$index]->status === PanelAnswerStatus::Ok) {
                        $this->write($one['key'], $answers[$index], $this->ttlFor($one['request']->command, max($this->ttl, self::WARM_SECONDS)));
                        $keep[$one['id']]['warmed'] = $now;
                    }
                }
            }
        }
        $this->putReads($keep);

        return $count;
    }

    /**
     * Asks at once the reads a page is about to make that are not kept yet, in one call where the server can (simai-env
     * 1.52.0 `batch`): the overview's five reads take one start of simai-env instead of five. The page then asks them
     * as before and finds the answers here.
     *
     * @param list<PanelRequest> $requests
     */
    public function prefetch(array $requests): void
    {
        $missing = [];
        foreach ($requests as $request) {
            if (!self::keeps($request)) {
                continue;
            }
            $key = $this->key($request);
            if (isset($this->asked[$key]) || isset($missing[$key])) {
                continue;
            }
            $this->remember($request);
            $kept = $this->read($key);
            if ($kept !== null) {
                $this->asked[$key] = $kept;
                continue;
            }
            $missing[$key] = $request;
        }
        if (count($missing) < 2 || $this->flag(self::DOWN)) {
            return;
        }
        $byPerson = [];
        foreach ($missing as $key => $request) {
            $byPerson[$request->actor->user."\0".($request->actor->owner ?? '')."\0".($request->actor->impersonatedBy ?? '')][$key] = $request;
        }
        foreach ($byPerson as $person) {
            foreach (array_chunk($person, PanelBatchGateway::MOST, true) as $chunk) {
                if (count($chunk) < 2) {
                    continue;
                }
                $answers = $this->askTogether(array_values($chunk));
                foreach (array_keys($chunk) as $index => $key) {
                    $answer = $answers[$index];
                    if ($answer->status === PanelAnswerStatus::Ok) {
                        $this->asked[$key] = $answer;
                        $this->write($key, $answer, $this->ttlFor($chunk[$key]->command, $this->ttl));
                    }
                }
            }
        }
    }

    /**
     * The reads in one call where the server can (`batch`), else one by one. Only a server that answered it does not
     * know `batch` is asked one by one for an hour. A batch the gateway did not answer at all marks the server down and
     * every read fails at once: asking each again would wait the timeout each, minutes for a page (critics of 0.4.1).
     * The DOWN mark is looked at before every ask, so a hung server costs one wait, not one per read.
     *
     * @param list<PanelRequest> $requests
     *
     * @return list<PanelAnswer>
     */
    private function askTogether(array $requests): array
    {
        if (!$this->flag(self::DOWN) && count($requests) > 1 && $this->inner instanceof PanelBatchGateway && !$this->flag(self::NO_BATCH)) {
            $batch = $this->inner->askAll($requests);
            if ($batch->answers !== null) {
                array_map($this->note(...), $batch->answers);

                return $batch->answers;
            }
            if ($batch->unanswered()) {
                $this->markDown();
            } elseif ($batch->unknownToServer) {
                $this->flagFor(self::NO_BATCH, 3600);
            }
        }

        return array_map(function (PanelRequest $request): PanelAnswer {
            if ($this->flag(self::DOWN)) {
                return PanelAnswer::failed($request->requestId, self::UNANSWERED);
            }
            $answer = $this->inner->ask($request);
            $this->note($answer);

            return $answer;
        }, $requests);
    }

    /**
     * How long an answer is kept: a slow read for its long time, unless an action may be changing the server; then
     * only for `ttl`, also when the warm-up asks for longer.
     */
    private function ttlFor(string $command, int $ttl): int
    {
        if (!in_array($command, self::SLOW, true)) {
            return $ttl;
        }

        return $this->flag(self::CHANGING) ? $this->ttl : max($this->ttl, $this->slowTtl);
    }

    /** Notes what an answer from the server says of the server: down when the gateway did not answer, up otherwise. */
    private function note(PanelAnswer $answer): void
    {
        if ($answer->reason === self::UNANSWERED) {
            $this->markDown();

            return;
        }
        try {
            if ($this->cache->get(self::SILENT_SINCE) !== null) {
                $this->cache->forget(self::SILENT_SINCE);
            }
        } catch (Throwable) {
            // Not forgotten: it lapses by itself.
        }
    }

    /** Marks the server down for DOWN_SECONDS, keeping the moment it first went silent. */
    private function markDown(): void
    {
        $this->flagFor(self::DOWN, self::DOWN_SECONDS);
        try {
            $since = $this->cache->get(self::SILENT_SINCE);
            $this->cache->put(self::SILENT_SINCE, is_int($since) ? $since : time(), self::SILENT_SECONDS);
        } catch (Throwable) {
            // Not kept: the bar says the time of its own look.
        }
    }

    /** Since when the server has not answered the panel (a Unix time), or null while it answers. */
    public static function silentSince(Repository $cache): ?int
    {
        try {
            $since = $cache->get(self::SILENT_SINCE);
        } catch (Throwable) {
            return null;
        }

        return is_int($since) ? $since : null;
    }

    private function flagFor(string $key, int $seconds): void
    {
        try {
            $this->cache->put($key, true, $seconds);
        } catch (Throwable) {
            // Not kept: nothing changes but the waiting.
        }
    }

    private function flag(string $key): bool
    {
        try {
            return $this->cache->get($key) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Every kept answer is stale from now: the server may have changed. */
    public function forgetAll(): void
    {
        $this->asked = [];
        try {
            $this->cache->forever(self::GENERATION, bin2hex(random_bytes(8)));
            // The alert bar's own lists too: the bar says what the page under it says (critics 2026-11-07).
            \Larena\Env\Warnings\EnvAdminAlertSource::forgetKept($this->cache);
        } catch (Throwable) {
            // Without a cache nothing was kept.
        }
    }

    /**
     * Notes the read for the warm-up: the person's own reads (never one seen as someone else), at most once a minute
     * each, so a page writes the list rarely.
     */
    private function remember(PanelRequest $request): void
    {
        if ($request->actor->impersonatedBy !== null || in_array($request->command, self::NOT_WARMED, true)) {
            return;
        }
        $args = $request->args;
        ksort($args);
        $id = sha1(implode("\0", [$request->actor->user, $request->actor->owner ?? '', $request->command, json_encode($args)]));
        $reads = $this->reads();
        $now = time();
        if (isset($reads[$id]) && $reads[$id]['used'] > $now - 60) {
            return;
        }
        $reads[$id] = ['user' => $request->actor->user, 'owner' => $request->actor->owner, 'command' => $request->command,
            'args' => self::texts($args), 'used' => $now, 'warmed' => $reads[$id]['warmed'] ?? $now];
        if (count($reads) > self::READS_KEPT) {
            uasort($reads, static fn (array $a, array $b): int => $b['used'] <=> $a['used']);
            $reads = array_slice($reads, 0, self::READS_KEPT, true);
        }
        $this->putReads($reads);
    }

    /**
     * @param array<mixed> $args
     *
     * @return array<string, string>
     */
    private static function texts(array $args): array
    {
        $texts = [];
        foreach ($args as $name => $value) {
            if (is_scalar($value)) {
                $texts[(string) $name] = (string) $value;
            }
        }

        return $texts;
    }

    /** @return array<string, array{user: string, owner: ?string, command: string, args: array<string, string>, used: int, warmed: int}> */
    private function reads(): array
    {
        try {
            $reads = $this->cache->get(self::READS);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($reads)) {
            return [];
        }
        $valid = [];
        foreach ($reads as $id => $read) {
            if (is_string($id) && is_array($read) && is_string($read['user'] ?? null) && is_string($read['command'] ?? null)
                && is_array($read['args'] ?? null) && is_int($read['used'] ?? null) && is_int($read['warmed'] ?? null)
                && (($read['owner'] ?? null) === null || is_string($read['owner']))) {
                $valid[$id] = ['user' => $read['user'], 'owner' => $read['owner'] ?? null, 'command' => $read['command'],
                    'args' => self::texts($read['args']), 'used' => $read['used'], 'warmed' => $read['warmed']];
            }
        }

        return $valid;
    }

    /** @param array<string, array<string, mixed>> $reads */
    private function putReads(array $reads): void
    {
        try {
            $this->cache->forever(self::READS, $reads);
        } catch (Throwable) {
            // Not kept: the warm-up has less to do.
        }
    }

    private function key(PanelRequest $request): string
    {
        $args = $request->args;
        ksort($args);

        return 'larena-env:gateway:'.sha1(implode("\0", [
            $this->generation(), $this->inner->source(), $request->actor->user, $request->actor->owner ?? '', $request->actor->impersonatedBy ?? '',
            $request->command, json_encode($args, JSON_THROW_ON_ERROR),
        ]));
    }

    private function generation(): string
    {
        try {
            $generation = $this->cache->get(self::GENERATION);
        } catch (Throwable) {
            return '';
        }

        return is_string($generation) ? $generation : '';
    }

    private function read(string $key): ?PanelAnswer
    {
        try {
            $kept = $this->cache->get($key);
        } catch (Throwable) {
            return null;
        }
        // Kept as a string, read back as nothing but an answer.
        $answer = is_string($kept) ? @unserialize($kept, ['allowed_classes' => [PanelAnswer::class, PanelAnswerStatus::class]]) : null;

        return $answer instanceof PanelAnswer ? $answer : null;
    }

    private function write(string $key, PanelAnswer $answer, int $ttl): void
    {
        try {
            $this->cache->put($key, serialize($answer), $ttl);
            // A log's answer says when it was read (receivedAt), so an old one is shown with its time.
            if ($answer->status === PanelAnswerStatus::Ok) {
                $this->cache->put(self::LAST_GOOD.$key, serialize($answer), self::LAST_GOOD_SECONDS);
            }
        } catch (Throwable) {
            // Not kept: the next screen asks the server.
        }
    }

    /** What the server last said to this read, while it is silent; null when it never answered it (or a day ago). */
    private function lastGood(string $key): ?PanelAnswer
    {
        return $this->read(self::LAST_GOOD.$key);
    }
}
