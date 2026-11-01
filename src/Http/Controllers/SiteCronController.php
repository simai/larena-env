<?php

declare(strict_types=1);

namespace Larena\Env\Http\Controllers;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Larena\Env\Cron\CronSchedule;
use Larena\Env\Gateway\PanelActor;
use Larena\Env\Gateway\PanelCapabilities;
use Larena\Env\Http\Middleware\ResolvePanelActor;
use Larena\Env\Http\Support\NoticeRedirect;
use Larena\Env\Operations\PanelOperations;
use Larena\Env\Server\ServerOverviewReader;
use Larena\Env\Server\SiteExtrasReader;

/**
 * A site's own scheduled jobs, its «Планировщик» tab (Panel plan 5.4), at either level: an owner's own sites only,
 * which the server checks too. A job runs as the site's user in the site's folder, never as root, and no more often
 * than every minute. Adding, turning on or off and removing a job are planned and confirmed like any change; a job is
 * named by its id, so the panel sends only an id it found among this site's jobs.
 */
final readonly class SiteCronController
{
    /** The changes and the gateway command of each. */
    public const array OPERATIONS = ['add' => 'cron:job-add', 'remove' => 'cron:job-remove', 'enable' => 'cron:job-enable', 'disable' => 'cron:job-disable'];

    public function __construct(
        private PanelOperations $operations,
        private ServerOverviewReader $overviews,
        private SiteExtrasReader $extras,
        private Factory $views,
        private Translator $translator,
    ) {
    }

    public function plan(Request $request, string $domain): mixed
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $args = $this->arguments($request, $actor, $domain, $op, false);
        if (is_string($args)) {
            return $this->back($request, $domain, $args);
        }
        $result = $this->operations->planCommand($actor, self::OPERATIONS[$op], $args);
        if ($result->plan === null) {
            return $this->back($request, $domain, $result->reason ?? 'answer_shape_invalid');
        }
        $hidden = ['op' => $op];
        foreach ($args as $name => $value) {
            $hidden['args['.$name.']'] = $value;
        }

        return $this->views->make('larena-env::admin.plan', [
            'heading' => (string) $this->translator->get('larena-env::admin.cron.operations.'.$op),
            'eyebrow' => $domain,
            'target' => $domain,
            'source' => $result->source,
            'plan' => $result->plan,
            'runUrl' => route('larena.env.admin.site.cron.run', ['domain' => $domain], false),
            'cancelUrl' => route('larena.env.admin.site', ['domain' => $domain], false).'?tab=cron',
            'hidden' => $hidden,
            'then' => $op === 'add' ? [(string) $this->translator->get('larena-env::admin.cron.runs_as_site')] : [],
        ]);
    }

    public function run(Request $request, string $domain): RedirectResponse
    {
        $actor = ResolvePanelActor::of($request);
        $op = (string) $request->input('op');
        $args = $this->arguments($request, $actor, $domain, $op, true);
        if (is_string($args)) {
            return $this->back($request, $domain, $args);
        }
        $started = $this->operations->startCommand($actor, self::OPERATIONS[$op], $args);
        if ($started->jobId === null) {
            return $this->back($request, $domain, $started->reason ?? 'answer_shape_invalid');
        }

        return new RedirectResponse(route('larena.env.admin.job', ['job' => $started->jobId], false));
    }

    /**
     * The gateway arguments, from the form or, on the confirmation, as the plan named them; or why it is refused: a
     * command the server does not list, a schedule of other than five fields, a command of more than one line, or a
     * job that is not among this site's.
     *
     * @return array<string, string>|string
     */
    private function arguments(Request $request, PanelActor $actor, string $domain, string $op, bool $planned): array|string
    {
        $command = self::OPERATIONS[$op] ?? null;
        $capabilities = $this->overviews->read($actor)->overview->capabilities ?? PanelCapabilities::none();
        if ($command === null || !$capabilities->allows($command)) {
            return 'not_allowed';
        }
        $typed = $planned ? (array) $request->input('args', []) : $request->all();
        $field = static fn (string $name): string => is_string($typed[$name] ?? null) ? trim($typed[$name]) : '';
        if ($op === 'add') {
            if ($planned) {
                $schedule = $field('schedule');
            } else {
                $preset = $field('preset');
                $schedule = $preset === 'custom' ? preg_replace('/\s+/', ' ', $field('custom')) ?? '' : (CronSchedule::PRESETS[$preset] ?? '');
            }
            if (!CronSchedule::valid($schedule)) {
                return 'cron_schedule_invalid';
            }
            $line = $field('command');
            if ($line === '' || preg_match('/[\x00-\x1f\x7f]/', $line) === 1 || mb_strlen($line) > 1000) {
                return 'cron_command_invalid';
            }
            if ($planned && $field('domain') !== $domain) {
                return 'not_allowed';
            }

            return ['domain' => $domain, 'schedule' => $schedule, 'command' => $line];
        }
        $id = $field('id');
        foreach ($this->extras->cronJobs($actor, $domain) ?? [] as $job) {
            if ($job->id === $id) {
                return ['id' => $id];
            }
        }

        return 'cron_unknown_job';
    }

    private function back(Request $request, string $domain, string $reason): RedirectResponse
    {
        $key = 'larena-env::admin.reasons.'.$reason;
        $line = (string) $this->translator->get($key);

        return NoticeRedirect::to($request, route('larena.env.admin.site', ['domain' => $domain], false).'?tab=cron',
            $line !== $key ? $line : (string) $this->translator->get('larena-env::admin.reasons.unknown', ['reason' => $reason]));
    }
}
