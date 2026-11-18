<?php

declare(strict_types=1);

namespace Larena\Env\Admin;

use Illuminate\Support\HtmlString;
use Larena\Admin\Runtime\AdminPlannedAction;
use Larena\Env\Operations\JobState;
use Larena\Env\Operations\PanelJobStatus;

/**
 * How an action ended, in the panel's words, for the admin's «Действие с планом» (PX-6): what became of the object, and
 * the next step — the list after a site is gone, the site over https once it has a certificate, HTTPS after WordPress.
 * The admin says it once in the corner (sf-toast); nothing is said while the action runs.
 */
final class JobOutcome
{
    /** The words of the end, by command (`job.done.<command>`); any other says «Готово». */
    private const array DONE = ['site:remove', 'site:db-drop', 'site:redirect-add', 'site:redirect-remove', 'ssl:letsencrypt', 'wp:install', 'profile:enable', 'profile:disable', 'site:db-browser', 'site:db-browser-close',
        'site:db-create', 'site:db-rotate', 'site:db-credentials'];

    private function __construct()
    {
    }

    /** The end's heading in words: what became, or that it did not work. */
    public static function heading(PanelJobStatus $status): string
    {
        if ($status->state === JobState::Succeeded) {
            $key = in_array($status->command, self::DONE, true) ? str_replace(':', '-', $status->command) : 'other';

            $id = $status->args['id'] ?? '';
            // A kind of site by its name («Node.js»), not its id.
            $name = $id !== '' ? self::text('new_site.profiles.'.$id) : '';

            return self::text('job.done.'.$key, ['domain' => $status->args['domain'] ?? '', 'id' => $name !== '' && !str_contains($name, 'new_site.profiles.') ? $name : $id]);
        }

        return self::text('job.states.'.$status->state->value);
    }

    /**
     * The next step: [address, words], or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function next(PanelJobStatus $status): ?array
    {
        $domain = is_string($status->args['domain'] ?? null) && $status->args['domain'] !== '' ? $status->args['domain'] : null;
        if ($status->state !== JobState::Succeeded) {
            return $domain !== null && $status->command !== 'site:remove' ? [self::site($domain), self::text('job.next.site')] : null;
        }

        return match (true) {
            $status->command === 'site:remove' => [route('larena.env.admin.index', [], false), self::text('job.next.sites')],
            // A kind switched on goes back to «Новый сайт» with it chosen.
            $status->command === 'profile:enable' && is_string($status->args['id'] ?? null) => [route('larena.env.admin.sites.new', [], false).'?profile='.rawurlencode($status->args['id']), self::text('job.next.new_site')],
            $status->command === 'ssl:letsencrypt' && $domain !== null => ['https://'.$domain.'/', self::text('job.next.open_site')],
            // The next step opens its action at once (PX-15): the site with the certificate's question open.
            $status->command === 'wp:install' && $domain !== null => [self::site($domain).'?tab=overview&action=certificate-issue', self::text('job.next.https')],
            // A new database password (simai-env 1.59.0) is shown by asking for the connection details, once.
            $status->command === 'site:db-rotate' && $domain !== null => [self::site($domain).'?tab=database&action=database-credentials', self::text('job.next.credentials')],
            in_array($status->command, ['site:db-create', 'site:db-credentials'], true) && $domain !== null => [self::site($domain).'?tab=database', self::text('job.next.database')],
            $domain !== null => [self::site($domain), self::text('job.next.site')],
            default => null,
        };
    }

    /** The mark the admin reads once the action is over; nothing while it runs. */
    public static function mark(PanelJobStatus $status): HtmlString
    {
        if (!$status->state->isOver() || !class_exists(AdminPlannedAction::class)) {
            return new HtmlString('');
        }
        $next = self::next($status);
        $succeeded = $status->state === JobState::Succeeded;
        // A site that is gone leaves its page: closing the panel goes to the list.
        $leave = $succeeded && $status->command === 'site:remove' ? route('larena.env.admin.index', [], false) : '';

        return AdminPlannedAction::outcome($status->jobId, $succeeded, self::heading($status), $next[0] ?? '', $next[1] ?? '', $leave,
            $succeeded ? '' : self::text('job.failed_text'));
    }

    /**
     * The run's progress on the Framework's sf-progress-run (larena/admin AdminPlannedAction::progress, PX-7, PX-11): the
     * server's steps (simai-env 1.60.0) in its words, where it stands («2 из 5: Создаётся база»), a step not reached yet
     * by its number; without steps a bar without a per cent while it runs; once over, what became in words, the reason of
     * a failure as the server says it.
     */
    public static function progress(PanelJobStatus $status): HtmlString
    {
        if (!class_exists(AdminPlannedAction::class)) {
            return new HtmlString('');
        }
        $state = match ($status->state) {
            JobState::Running => 'running',
            JobState::Succeeded => 'done',
            JobState::Cancelled => 'cancelled',
            default => 'failed',
        };
        $result = match ($status->state) {
            JobState::Running => '',
            JobState::Succeeded => self::heading($status),
            JobState::Busy => self::text('job.states.busy'),
            JobState::Cancelled => self::text('job.states.cancelled'),
            default => $status->outcome['words'] ?? self::text('job.failed_text'),
        };
        $label = $status->title ?? (is_string($status->args['domain'] ?? null) && $status->args['domain'] !== ''
            ? self::text('job.progress_of', ['domain' => $status->args['domain']]) : self::text('job.progress'));

        return AdminPlannedAction::progress($label, $state, self::steps($status), $result,
            (string) \Larena\Env\Server\Moment::iso($status->startedAt ?? $status->createdAt), (string) \Larena\Env\Server\Moment::iso($status->finishedAt),
            self::where($status), $status->progress === null ? null : count(array_filter($status->steps, static fn (array $step): bool => $step['state'] === 'done')),
            $status->progress['total'] ?? null);
    }

    /**
     * The steps as sf-progress-run takes them: each by its words, one not reached yet as «Шаг 3».
     *
     * @return list<array{id: string, title: string, state: string, started?: string, finished?: string}>
     */
    public static function steps(PanelJobStatus $status): array
    {
        $steps = [];
        foreach ($status->steps as $step) {
            $steps[] = ['id' => 'step-'.$step['id'], 'title' => $step['title'] ?? self::text('job.step_waiting', ['number' => $step['id']]), 'state' => $step['state']]
                + ($step['started'] !== null ? ['started' => (string) \Larena\Env\Server\Moment::iso($step['started'])] : [])
                + ($step['finished'] !== null ? ['finished' => (string) \Larena\Env\Server\Moment::iso($step['finished'])] : []);
        }

        return $steps;
    }

    /** Where a running job stands in words: «2 из 5: Создаётся база»; empty without steps or once over. */
    public static function where(PanelJobStatus $status): string
    {
        if ($status->state !== JobState::Running || $status->progress === null) {
            return '';
        }
        $replace = ['current' => (string) $status->progress['current'], 'total' => (string) $status->progress['total']];

        return $status->progress['title'] !== null ? self::text('job.where', $replace + ['title' => $status->progress['title']]) : self::text('job.where_bare', $replace);
    }

    /** How often a running job's page reads it again, in milliseconds (every 2–3 s, owner 2026-11-18). */
    public const int INTERVAL_MS = 2500;
    /** How often the page of an update asks while the server (or the panel itself) restarts. */
    public const int RESTART_MS = 3000;

    /**
     * The job's live part (larena/admin AdminLiveRegion): read again (job:status) every few seconds while it runs, not
     * only when the page is opened; said in a few words. It names its run, so the indicator of background actions does
     * not say the same end a second time.
     */
    public static function live(PanelJobStatus $status, string $url): HtmlString
    {
        return self::holder(!$status->state->isOver(), $url, self::heading($status), self::INTERVAL_MS);
    }

    /** The part of an update's job while the server does not answer for it: asked again until the panel is back. */
    public static function restarting(string $url, string $said): HtmlString
    {
        return self::holder(true, $url, $said, self::RESTART_MS);
    }

    private static function holder(bool $live, string $url, string $said, int $interval): HtmlString
    {
        if (!class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class)) {
            return new HtmlString('');
        }
        return new HtmlString(\Larena\Admin\Runtime\AdminLiveRegion::open('env-job', $live, $url, $said, $interval, true));
    }

    /** The state in the server's words (simai-env 1.60.0 `state_words`: Выполняется, Готово, Ошибка, Отменено, Занято), else the panel's. */
    public static function stateWords(PanelJobStatus $status): string
    {
        return $status->stateWords ?? self::text('job.state_words.'.$status->state->value);
    }

    /** The state as sf-badge's activity: a spinner while it runs, an icon of its own once over. */
    public static function activity(PanelJobStatus $status): string
    {
        return match ($status->state) {
            JobState::Running => 'running',
            JobState::Succeeded => 'done',
            JobState::Busy => 'waiting',
            JobState::Cancelled => 'stopped',
            JobState::Failed => 'failed',
        };
    }

    /**
     * Why a failed job failed when the server gives no words of it (a server before 1.60.0, a job lost): the last lines
     * of its output that speak of an error, else its last lines.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function failureLines(array $lines, int $most = 6): array
    {
        $lines = array_values(array_filter(array_map(static fn (string $line): string => rtrim($line), $lines), static fn (string $line): bool => trim($line) !== ''));
        $errors = array_values(array_filter($lines, static fn (string $line): bool => preg_match('/error|errno|fail|fatal|denied|refused|exception|ошибк|не удалось|невозможно/iu', $line) === 1));

        return array_slice($errors !== [] ? $errors : $lines, -$most);
    }

    /** The commands «Повторить» never plans again: what carried a file or a secret the server keeps no copy of. */
    private const array NOT_AGAIN = ['file:write', 'file:upload', 'self:alerts-channel', 'ssl:dns-credentials', 'site:deploy-archive'];

    /**
     * Whether «Повторить» plans this job again: one that ended without success, whose arguments the server kept whole
     * (nothing «(stored)», nothing carried as a file).
     */
    public static function canAgain(PanelJobStatus $status): bool
    {
        if (!in_array($status->state, [JobState::Failed, JobState::Cancelled, JobState::Busy], true)
            || in_array($status->command, self::NOT_AGAIN, true) || \Larena\Env\Gateway\PanelCommands::attachmentLimit($status->command) !== null) {
            return false;
        }
        foreach ($status->args as $value) {
            if ($value === '(stored)') {
                return false;
            }
        }

        return true;
    }

    public static function liveEnd(): HtmlString
    {
        return new HtmlString(class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class) ? \Larena\Admin\Runtime\AdminLiveRegion::close('env-job') : '');
    }

    private static function site(string $domain): string
    {
        return route('larena.env.admin.site', ['domain' => $domain], false);
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, array $replace = []): string
    {
        return function_exists('__') ? (string) __('larena-env::admin.'.$key, $replace) : $key;
    }
}
