<?php

declare(strict_types=1);

namespace Larena\Env\Gateway;

use Illuminate\Contracts\Cache\Repository;
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
