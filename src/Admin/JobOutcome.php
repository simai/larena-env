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
    private const array DONE = ['site:remove', 'site:db-drop', 'site:redirect-add', 'site:redirect-remove', 'ssl:letsencrypt', 'wp:install', 'profile:enable', 'profile:disable'];

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
     * server gives no steps yet, so a bar without a per cent while it runs; once over, what became in words.
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
            default => self::text('job.failed_text'),
        };
        $label = is_string($status->args['domain'] ?? null) && $status->args['domain'] !== ''
            ? self::text('job.progress_of', ['domain' => $status->args['domain']]) : self::text('job.progress');

        return AdminPlannedAction::progress($label, $state, [], $result, (string) \Larena\Env\Server\Moment::iso($status->createdAt));
    }

    /** The job's live part (larena/admin AdminLiveRegion): read again while it runs, said in a few words. */
    public static function live(PanelJobStatus $status, string $url): HtmlString
    {
        return new HtmlString(class_exists(\Larena\Admin\Runtime\AdminLiveRegion::class)
            ? \Larena\Admin\Runtime\AdminLiveRegion::open('env-job', !$status->state->isOver(), $url, self::heading($status)) : '');
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
