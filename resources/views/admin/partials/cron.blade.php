{{-- A site's own scheduled jobs (Panel plan 5.4): each runs as the site's user in its folder, never more often than every minute. --}}
<section class="larena-panel larena-panel-padded" data-env-cron="{{ $cron['jobs'] === null ? 'unreadable' : 'on' }}" aria-labelledby="env-site-cron">
    <h2 id="env-site-cron">{{ __('larena-env::admin.cron.title') }}</h2>
    <p>{{ __('larena-env::admin.cron.explain') }}</p>
    <p data-env-cron-hint>{{ __('larena-env::admin.cron.where_and_overlap') }}</p>
    @if ($cron['jobs'] === null)
        <p role="alert">{{ __('larena-env::admin.cron.unreadable') }}</p>
    @elseif ($cron['jobs'] === [])
        <p data-env-cron-none>{{ __('larena-env::admin.cron.none') }}</p>
    @else
        <ul class="m-0 p-0" style="list-style: none;">
            @foreach ($cron['jobs'] as $job)
                <li data-env-cron-job="{{ $job->id }}" data-env-cron-enabled="{{ $job->enabled ? 'yes' : 'no' }}" class="grid g-1 p-y-2 border-top-1 border-top-solid border-outline-variant">
                    <div class="flex flex-wrap items-cross-center g-1">
                        <strong data-env-cron-when>{{ $cron['when'][$job->id] ?? $job->schedule }}</strong>
                        @if (!$job->enabled)
                            <sf-badge appearance="tonal" status="neutral" text="{{ __('larena-env::admin.cron.off') }}"></sf-badge>
                        @endif
                        @if ($job->lastRun !== null)
                            <sf-badge appearance="tonal" status="{{ $job->lastRun->succeeded() ? 'success' : 'error' }}" @if (!$job->lastRun->succeeded()) role="alert" @endif data-env-cron-last="{{ $job->lastRun->succeeded() ? 'ok' : 'failed' }}"
                                text="{{ $job->lastRun->succeeded()
                                ? __('larena-env::admin.cron.last_ok', ['time' => \Larena\Env\Server\Moment::human($job->lastRun->startedAt)])
                                : __('larena-env::admin.cron.last_failed', ['time' => \Larena\Env\Server\Moment::human($job->lastRun->startedAt), 'code' => (string) ($job->lastRun->exitCode ?? '—')]) }}"></sf-badge>
                        @else
                            <span class="text-small color-on-surface-variant">{{ __('larena-env::admin.cron.never_ran') }}</span>
                        @endif
                    </div>
                    <code class="mono text-small" style="overflow-wrap: anywhere; white-space: pre-wrap;">{{ $job->command }}</code>
                    <div class="flex flex-wrap items-cross-center g-1">
                        @if ($cron['allows']['runs'])
                            <a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=cron&amp;runs={{ urlencode($job->id) }}#env-cron-runs" data-env-cron-runs-link="{{ $job->id }}" class="flex items-cross-center" style="min-height: 2.75rem;">{{ __('larena-env::admin.cron.runs') }}</a>
                        @endif
                        @foreach ($job->enabled ? ['disable', 'remove'] : ['enable', 'remove'] as $op)
                            @if ($cron['allows'][$op])
                                <form method="post" action="{{ route('larena.env.admin.site.cron.plan', ['domain' => $domain], false) }}" class="m-0" data-env-cron-op="{{ $op }}">
                                    @csrf
                                    <input type="hidden" name="op" value="{{ $op }}">
                                    <input type="hidden" name="id" value="{{ $job->id }}">
                                    <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.cron.operations.'.$op) }}</button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if ($cron['opened'] !== null)
    <section class="larena-panel larena-panel-padded" data-env-cron-runs="{{ $cron['opened']->id }}" aria-labelledby="env-cron-runs">
        <h2 id="env-cron-runs">{{ __('larena-env::admin.cron.runs_of') }}</h2>
        <p><code style="overflow-wrap: anywhere;">{{ $cron['opened']->command }}</code></p>
        @if ($cron['runs'] === null)
            <p role="alert">{{ __('larena-env::admin.cron.runs_unreadable') }}</p>
        @elseif ($cron['runs'] === [])
            <p>{{ __('larena-env::admin.cron.never_ran') }}</p>
        @else
            @foreach ($cron['runs'] as $run)
                <div data-env-cron-run="{{ $run->succeeded() ? 'ok' : 'failed' }}" style="margin-block-end: .75rem;">
                    <p>{{ $run->succeeded() ? __('larena-env::admin.cron.last_ok', ['time' => \Larena\Env\Server\Moment::human($run->startedAt)])
                        : __('larena-env::admin.cron.last_failed', ['time' => \Larena\Env\Server\Moment::human($run->startedAt), 'code' => (string) ($run->exitCode ?? '—')]) }}</p>
                    @if ($run->output !== [])
                        <pre tabindex="0" style="max-width: 100%; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere;">{{ implode("\n", $run->output) }}</pre>
                    @endif
                </div>
            @endforeach
        @endif
    </section>
@endif

@if ($cron['allows']['add'] && count($cron['jobs'] ?? []) >= \Larena\Env\Cron\CronSchedule::MAX_JOBS)
    <section class="larena-panel larena-panel-padded" data-env-cron-full>
        <p role="status">{{ __('larena-env::admin.reasons.cron_jobs_full') }}</p>
    </section>
@elseif ($cron['allows']['add'])
    <section class="larena-panel larena-panel-padded" aria-labelledby="env-cron-add">
        <h2 id="env-cron-add">{{ __('larena-env::admin.cron.operations.add') }}</h2>
        <form method="post" action="{{ route('larena.env.admin.site.cron.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-cron-op="add">
            @csrf
            <input type="hidden" name="op" value="add">
            <div class="larena-field">
                <label for="env-cron-preset">{{ __('larena-env::admin.cron.when') }}</label>
                <select id="env-cron-preset" name="preset" style="min-height: 2.75rem;">
                    @foreach ($cron['presets'] as $key => $schedule)
                        <option value="{{ $key }}" @if ($key === 'hourly') selected @endif>{{ __('larena-env::admin.cron.presets.'.$key) }}</option>
                    @endforeach
                    <option value="custom">{{ __('larena-env::admin.cron.custom') }}</option>
                </select>
            </div>
            <div class="larena-field">
                <label for="env-cron-custom">{{ __('larena-env::admin.cron.custom_label') }}</label>
                <input type="text" id="env-cron-custom" name="custom" autocomplete="off" spellcheck="false" placeholder="30 2 * * *">
                <p>{{ __('larena-env::admin.cron.custom_hint') }}</p>
            </div>
            <div class="larena-field">
                <label for="env-cron-command">{{ __('larena-env::admin.cron.command') }}</label>
                <input type="text" id="env-cron-command" name="command" required maxlength="1000" pattern="[^\-\s].*" autocomplete="off" spellcheck="false" placeholder="php artisan schedule:run">
                <p>{{ __('larena-env::admin.cron.command_hint') }}</p>
            </div>
            <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
        </form>
    </section>
@endif
