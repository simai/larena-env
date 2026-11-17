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
    private const array DONE = ['site:remove', 'site:db-drop', 'site:redirect-add', 'site:redirect-remove', 'ssl:letsencrypt', 'wp:install'];

    private function __construct()
    {
    }

    /** The end's heading in words: what became, or that it did not work. */
    public static function heading(PanelJobStatus $status): string
    {
        if ($status->state === JobState::Succeeded) {
            $key = in_array($status->command, self::DONE, true) ? str_replace(':', '-', $status->command) : 'other';

            return self::text('job.done.'.$key, ['domain' => $status->args['domain'] ?? '']);
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
