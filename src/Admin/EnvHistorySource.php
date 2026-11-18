<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Larena\Admin\Contracts\AdminHistoryRunning;
use Larena\Admin\Contracts\AdminHistorySource;
use Larena\Admin\Contracts\AdminIdentityLabelResolver;
use Larena\Admin\History\AdminHistoryEntry;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelRefused;
use Larena\Env\Http\Controllers\ServerJobController;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\PanelActorResolver;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobStatus;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\Moment;

/**
 * The server's jobs in the admin's «История» (owner decision dec-d9f352569fd1, PX-47): every run of the panel — what
 * runs now and what was done, over which site, by whom and how it ended, how long it took — read from the server's own
 * list (job:list, page by page through `before`, as the person may see them: an owner sees their own sites' only). The
 * server says it in words since simai-env 1.60.0 (title, steps, outcome, actor.kind); an older one is said from the
 * command. A row opens the run's own page in the side panel. The count of running jobs (`counts.running`) marks
 * «История» in the menu, kept a little while per person so every page does not ask the server.
 */
final readonly class EnvHistorySource implements AdminHistorySource, AdminHistoryRunning
{
    /** How long the menu's mark of running jobs is kept per person, in seconds. */
    public const int RUNNING_SECONDS = 20;
    /** The server's page of the list (job:list limit, at most 100). */
    private const int PAGE = 100;

    /** The arguments a specialist reads under «Подробно»: never a file's content, a token or a key. */
    private const array SAFE_ARGS = ['domain', 'name', 'id', 'php', 'target', 'path', 'backup', 'from', 'to', 'code', 'port', 'address',
        'kind', 'mode', 'set', 'check', 'db', 'files', 'version', 'enabled', 'remove-files', 'drop-db', 'drop-db-user'];

    public function __construct(
        private PanelActorResolver $actors,
        private PanelOperations $operations,
        private Translator $translator,
        private ?AdminIdentityLabelResolver $labels = null,
        private ?\Illuminate\Contracts\Cache\Repository $cache = null,
    ) {
    }

    public function entries(Request $request, int $limit): array
    {
        $actor = $this->actor($request);
        if ($actor === null) {
            return [];
        }
        $limit = max(1, $limit);
        $entries = [];
        $before = null;
        // Page by page («Ещё» of the server), until the history has what it asked for or the server has no more.
        do {
            $page = $this->operations->jobPage($actor, $before, min(self::PAGE, $limit - count($entries)));
            if ($page === null) {
                break;
            }
            if ($before === null && $page->running !== null) {
                $this->remember($actor, $page->running);
            }
            foreach ($page->jobs as $status) {
                if (count($entries) < $limit) {
                    $entries[] = $this->entryOf($status, $actor);
                }
            }
            $before = $page->more ? $page->nextBefore : null;
        } while ($before !== null && count($entries) < $limit);

        return $entries;
    }

    /** How many of the person's jobs run now (simai-env 1.60.0 counts.running), kept a little while; null when unknown. */
    public function running(Request $request): ?int
    {
        $actor = $this->actor($request);
        if ($actor === null) {
            return null;
        }
        $key = $this->runningKey($actor);
        $kept = $this->cache?->get($key);
        if (is_int($kept)) {
            return $kept;
        }
        $page = $this->operations->jobPage($actor, null, 1, 'running');
        $running = $page?->running;
        if ($running === null && $page !== null) {
            // An older server without counts: its running jobs, as listed.
            $running = count(array_filter($page->jobs, static fn (PanelJobStatus $status): bool => $status->state === JobState::Running));
        }
        if ($running !== null) {
            $this->remember($actor, $running);
        }

        return $running;
    }

    private function remember(PanelActor $actor, int $running): void
    {
        $this->cache?->put($this->runningKey($actor), max(0, $running), self::RUNNING_SECONDS);
    }

    private function runningKey(PanelActor $actor): string
    {
        return 'larena-env:history-running:'.hash('sha256', $actor->user."\0".($actor->owner ?? ''));
    }

    /** A job opens its own page (/jobs/{id}); there is no separate card. */
    public function entry(Request $request, string $key): ?AdminHistoryEntry
    {
        return null;
    }

    private function actor(Request $request): ?PanelActor
    {
        $actor = $request->attributes->get(ResolvePanelActor::ATTRIBUTE);
        if ($actor instanceof PanelActor) {
            return $actor;
        }
        try {
            return $this->actors->resolve($request);
        } catch (PanelRefused) {
            // Not a person of the panel: nothing of the server is theirs to see.
            return null;
        }
    }

    private function entryOf(PanelJobStatus $status, PanelActor $actor): AdminHistoryEntry
    {
        $domain = is_string($status->args['domain'] ?? null) && $status->args['domain'] !== '' ? $status->args['domain'] : null;
        if ($domain === null && ($status->object['kind'] ?? null) === 'site' && is_string($status->object['id'] ?? null)) {
            $domain = $status->object['id'];
        }
        $outcome = match ($status->state) {
            JobState::Running => 'running',
            JobState::Succeeded => 'done',
            JobState::Cancelled => 'cancelled',
            default => 'failed',
        };
        $details = [[$this->text('runs.command'), $status->command]];
        if ($status->exitCode !== null) {
            $details[] = [$this->text('job.exit_code'), (string) $status->exitCode];
        }
        if (is_string($status->outcome['code'] ?? null)) {
            $details[] = [$this->text('runs.outcome_code'), $status->outcome['code']];
        }
        foreach ($status->args as $name => $value) {
            if (in_array($name, self::SAFE_ARGS, true) && $value !== '') {
                $details[] = [$name, $value];
            }
        }
        $details[] = [$this->text('runs.job'), $status->jobId];
        // The server's own words of the outcome when it gives them (1.60.0); the panel's, for an older one.
        $said = is_string($status->outcome['words'] ?? null) && $status->outcome['words'] !== '' ? $status->outcome['words'] : null;
        $note = match ($status->state) {
            JobState::Busy => $said ?? $this->text('job.states.busy'),
            JobState::Failed => $said ?? $this->text('runs.failed'),
            JobState::Cancelled => $status->cancel['leaves'] ?? '',
            JobState::Running => JobOutcome::where($status),
            default => '',
        };

        return new AdminHistoryEntry(
            key: 'job-'.$status->jobId,
            title: self::title($status, $this->translator),
            at: Moment::iso($status->startedAt ?? $status->createdAt) ?? '',
            actor: $this->who($status, $actor),
            actorKey: $status->actorKind === 'person' ? ($status->actor ?? 'server') : $status->actorKind,
            object: $domain ?? $this->text('runs.server'),
            objectKey: $domain ?? 'server',
            outcome: $outcome,
            note: $note,
            seconds: $status->state === JobState::Running ? null : $status->seconds(),
            href: route('larena.env.admin.job', ['job' => $status->jobId], false),
            steps: $status->state === JobState::Running ? JobOutcome::steps($status) : [],
            details: $details,
        );
    }

    /** What the job does and over what, in words: «Копия сайта demo.test»; a command without words says the object. */
    public static function title(PanelJobStatus $status, Translator $translator): string
    {
        // The panel's own words first (its sentences name the site); the server's title (1.60.0) for a command it has none for.
        $replace = [];
        foreach ($status->args as $name => $value) {
            if (in_array($name, self::SAFE_ARGS, true)) {
                $replace[str_replace('-', '_', $name)] = $value;
            }
        }
        $key = 'larena-env::admin.runs.commands.'.str_replace([':', '-'], ['.', '_'], $status->command);
        $line = $translator->get($key, $replace);
        if (is_string($line) && $line !== $key && !str_contains($line, ':')) {
            return $line;
        }
        if ($status->title !== null) {
            return $status->title;
        }
        $domain = $replace['domain'] ?? '';

        return (string) $translator->get($domain !== '' ? 'larena-env::admin.runs.other_site' : 'larena-env::admin.runs.other', ['domain' => $domain]);
    }

    /** Who started it, in words: «Вы», a person of the panel by name, the schedule, or the server itself. */
    private function who(PanelJobStatus $status, PanelActor $actor): string
    {
        if ($status->actorKind === 'schedule') {
            return $this->text('runs.by_schedule');
        }
        if ($status->actorKind === 'server') {
            return $this->text('runs.by_server');
        }
        $started = $status->actor;

        return match (ServerJobController::startedBy($started, $actor)) {
            'you' => $this->text('runs.you'),
            'other' => ($started !== null ? $this->labels?->resolve($started) : null) ?? $this->text('runs.someone'),
            'server' => $this->text('runs.by_server'),
            default => '',
        };
    }

    private function text(string $key): string
    {
        return (string) $this->translator->get('larena-env::admin.'.$key);
    }
}
