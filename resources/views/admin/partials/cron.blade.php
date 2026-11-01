{{-- A site's own scheduled jobs (Panel plan 5.4): each runs as the site's user in its folder, never more often than every minute. --}}
<section class="larena-panel larena-panel-padded" data-env-cron="{{ $cron['jobs'] === null ? 'unreadable' : 'on' }}" aria-labelledby="env-site-cron">
    <h2 id="env-site-cron">{{ __('larena-env::admin.cron.title') }}</h2>
    <p>{{ __('larena-env::admin.cron.explain') }}</p>
    @if ($cron['jobs'] === null)
        <p role="alert">{{ __('larena-env::admin.cron.unreadable') }}</p>
    @elseif ($cron['jobs'] === [])
        <p data-env-cron-none>{{ __('larena-env::admin.cron.none') }}</p>
    @else
        <ul class="larena-list" style="list-style: none; padding-inline-start: 0; display: grid; gap: .75rem;">
            @foreach ($cron['jobs'] as $job)
                <li data-env-cron-job="{{ $job->id }}" data-env-cron-enabled="{{ $job->enabled ? 'yes' : 'no' }}"
                    style="display: grid; gap: .375rem; padding: .75rem; border: 1px solid var(--larena-line); border-radius: .625rem;">
                    <strong data-env-cron-when>{{ $cron['when'][$job->id] ?? $job->schedule }}</strong>
                    <code style="overflow-wrap: anywhere; white-space: pre-wrap;">{{ $job->command }}</code>
                    <span>
                        @if (!$job->enabled)
                            {{ __('larena-env::admin.cron.off') }} ·
                        @endif
                        @if ($job->lastRun === null)
                            {{ __('larena-env::admin.cron.never_ran') }}
                        @else
                            <span @if (!$job->lastRun->succeeded()) role="alert" @endif data-env-cron-last="{{ $job->lastRun->succeeded() ? 'ok' : 'failed' }}">{{ $job->lastRun->succeeded()
                                ? __('larena-env::admin.cron.last_ok', ['time' => \Larena\Env\Server\Moment::human($job->lastRun->startedAt)])
                                : __('larena-env::admin.cron.last_failed', ['time' => \Larena\Env\Server\Moment::human($job->lastRun->startedAt), 'code' => (string) ($job->lastRun->exitCode ?? '—')]) }}</span>
                        @endif
                    </span>
                    <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;">
                        @if ($cron['allows']['runs'])
                            <a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=cron&amp;runs={{ urlencode($job->id) }}#env-cron-runs" data-env-cron-runs-link="{{ $job->id }}"
                                style="display: inline-flex; align-items: center; min-height: 2.75rem;">{{ __('larena-env::admin.cron.runs') }}</a>
                        @endif
                        @foreach ($job->enabled ? ['disable', 'remove'] : ['enable', 'remove'] as $op)
                            @if ($cron['allows'][$op])
                                <form method="post" action="{{ route('larena.env.admin.site.cron.plan', ['domain' => $domain], false) }}" data-env-cron-op="{{ $op }}">
                                    @csrf
                                    <input type="hidden" name="op" value="{{ $op }}">
                                    <input type="hidden" name="id" value="{{ $job->id }}">
                                    <button type="submit" style="min-height: 2.75rem;">{{ __('larena-env::admin.cron.operations.'.$op) }}</button>
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

@if ($cron['allows']['add'])
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
                <input type="text" id="env-cron-command" name="command" required maxlength="1000" autocomplete="off" spellcheck="false" placeholder="php artisan schedule:run">
                <p>{{ __('larena-env::admin.cron.command_hint') }}</p>
            </div>
            <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
        </form>
    </section>
@endif
