<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
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
 * runs now and what was done, over which site, by whom and how it ended — read from the server's own list (job:list,
 * its last 100, as the person may see them: an owner sees their own sites' only). A row opens the run's own page in the
 * side panel. The server gives no steps, end time or result in the list yet: what it gives is said, the rest is asked
 * of simai-env.
 */
final readonly class EnvHistorySource implements AdminHistorySource
{
    /** The arguments a specialist reads under «Подробно»: never a file's content, a token or a key. */
    private const array SAFE_ARGS = ['domain', 'name', 'id', 'php', 'target', 'path', 'backup', 'from', 'to', 'code', 'port', 'address',
        'kind', 'mode', 'set', 'check', 'db', 'files', 'version', 'enabled', 'remove-files', 'drop-db', 'drop-db-user'];

    public function __construct(
        private PanelActorResolver $actors,
        private PanelOperations $operations,
        private Translator $translator,
        private ?AdminIdentityLabelResolver $labels = null,
    ) {
    }

    public function entries(Request $request, int $limit): array
    {
        $actor = $this->actor($request);
        $jobs = $actor === null ? null : $this->operations->jobs($actor);
        $entries = [];
        foreach (array_slice($jobs ?? [], 0, max(1, $limit)) as $status) {
            $entries[] = $this->entryOf($status, $actor);
        }

        return $entries;
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
        foreach ($status->args as $name => $value) {
            if (in_array($name, self::SAFE_ARGS, true) && $value !== '') {
                $details[] = [$name, $value];
            }
        }
        $details[] = [$this->text('runs.job'), $status->jobId];

        return new AdminHistoryEntry(
            key: 'job-'.$status->jobId,
            title: self::title($status, $this->translator),
            at: Moment::iso($status->createdAt) ?? '',
            actor: $this->who($status->actor, $actor),
            actorKey: $status->actor ?? 'server',
            object: $domain ?? $this->text('runs.server'),
            objectKey: $domain ?? 'server',
            outcome: $outcome,
            note: match ($status->state) {
                JobState::Busy => $this->text('job.states.busy'),
                JobState::Failed => $this->text('runs.failed'),
                default => '',
            },
            href: route('larena.env.admin.job', ['job' => $status->jobId], false),
            details: $details,
        );
    }

    /** What the job does and over what, in words: «Копия сайта demo.test»; a command without words says the object. */
    public static function title(PanelJobStatus $status, Translator $translator): string
    {
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
        $domain = $replace['domain'] ?? '';

        return (string) $translator->get($domain !== '' ? 'larena-env::admin.runs.other_site' : 'larena-env::admin.runs.other', ['domain' => $domain]);
    }

    /** Who started it, in words: «Вы», a person of the panel by name, or the server itself. */
    private function who(?string $started, PanelActor $actor): string
    {
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
