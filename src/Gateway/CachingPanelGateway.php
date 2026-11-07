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
        'site:describe', 'site:doctor', 'site:php-ini-list', 'site:nginx-custom-show',
        'php:list', 'php:extensions', 'software:list', 'service:list', 'firewall:status', 'access:list', 'db:list', 'cron:list',
        'ssl:dns-status', 'file:list', 'security:status', 'security:files', 'security:quarantine-list', 'security:geo-status', 'mcp:status', 'backup:storage-list', 'panel:address',
    ];

    /**
     * The reads that are slow on the server (seconds: they ask the package manager or the update source) and change only
     * through an action, which makes them stale at once: kept for `slowTtl` seconds instead of `ttl`.
     */
    public const array SLOW = ['software:list', 'php:list', 'php:extensions', 'self:update-plan', 'self:profiles', 'self:commands'];

    private const string GENERATION = 'larena-env:gateway:generation';

    /** The reads pages asked lately, by person: what the warm-up asks again (simai-env 1.52.0 era, ENV 2026-11-07). */
    private const string READS = 'larena-env:gateway:reads';

    /** At most this many reads are remembered for the warm-up; the least recently used go first. */
    private const int READS_KEPT = 40;

    /** A warmed read lives this long: longer than the minute between two warm-ups, so a page finds it. */
    public const int WARM_SECONDS = 150;

    /** A read is warmed again once it is this old (a slow one, once its own time is nearly over). */
    private const int REWARM_AFTER = 40;

    /** Set for an hour when the server refused a batch (simai-env before 1.52.0). */
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
        $answer = $this->inner->ask($request);
        // A refusal or a failure is said again on the next screen, never kept.
        if ($answer->status === PanelAnswerStatus::Ok) {
            $this->asked[$key] = $answer;
            $this->write($key, $answer, in_array($request->command, self::SLOW, true) ? max($this->ttl, $this->slowTtl) : $this->ttl);
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
        /** @var array<string, list<array{id: string, request: PanelRequest, slow: bool}>> $due by person */
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
            $due[$read['user']."\0".($read['owner'] ?? '')][] = ['id' => $id, 'request' => $request, 'slow' => $slow];
            $count++;
        }
        foreach ($due as $person) {
            foreach (array_chunk($person, PanelBatchGateway::MOST) as $chunk) {
                $answers = $this->askTogether(array_column($chunk, 'request'));
                foreach ($chunk as $index => $one) {
                    if ($answers[$index]->status === PanelAnswerStatus::Ok) {
                        $this->write($this->key($one['request']), $answers[$index], $one['slow'] ? max($this->ttl, $this->slowTtl) : max($this->ttl, self::WARM_SECONDS));
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
        if (count($missing) < 2) {
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
                        $this->write($key, $answer, in_array($chunk[$key]->command, self::SLOW, true) ? max($this->ttl, $this->slowTtl) : $this->ttl);
                    }
                }
            }
        }
    }

    /**
     * The reads in one call where the server can (`batch`), else one by one; a server that refused a batch is asked
     * one by one for an hour.
     *
     * @param list<PanelRequest> $requests
     *
     * @return list<PanelAnswer>
     */
    private function askTogether(array $requests): array
    {
        if (count($requests) > 1 && $this->inner instanceof PanelBatchGateway && !$this->flag(self::NO_BATCH)) {
            $answers = $this->inner->askAll($requests);
            if ($answers !== null) {
                return $answers;
            }
            try {
                $this->cache->put(self::NO_BATCH, true, 3600);
            } catch (Throwable) {
                // Asked as a batch again next time.
            }
        }

        return array_map(fn (PanelRequest $request): PanelAnswer => $this->inner->ask($request), $requests);
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
        if ($request->actor->impersonatedBy !== null) {
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
        } catch (Throwable) {
            // Not kept: the next screen asks the server.
        }
    }
}
