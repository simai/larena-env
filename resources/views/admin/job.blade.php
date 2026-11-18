@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.job.title'))
@section('heading', __('larena-env::admin.job.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.job.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The run of «Действие с планом» (larena/admin AdminPlannedAction): after the confirmation the side panel over the site
         shows this part in place (X-Larena-Fragment: action); it reads itself again while the job runs (a live region) and,
         once over, says what became and the next step, which the admin also says in the corner. This address stays for the
         journal and the notifications. --}}
    {{ \Larena\Admin\Runtime\AdminPlannedAction::open('env-job') }}
    @if ($status === null && ($updating ?? null) !== null && ! $refused)
        {{-- An update replaces the gate and the panel while its job runs: the server, or the panel itself, may not answer
             for a while. The part asks again every few seconds (a failed answer later and later) until the job is read
             again, then shows how it ended; the button reads it at once. --}}
        {{ \Larena\Env\Admin\JobOutcome::restarting(route('larena.env.admin.job', ['job' => $jobId], false), __('larena-env::admin.update.restarting_title')) }}
        <section class="larena-panel larena-panel-padded" data-env-job-restarting="{{ $updating }}" role="status">
            <h2 class="text-2">{{ __('larena-env::admin.update.restarting_title') }}</h2>
            <p>{{ __('larena-env::admin.update.restarting') }}</p>
            <p><a href="{{ route('larena.env.admin.job', ['job' => $jobId], false) }}" class="larena-button">{{ __('larena-env::admin.update.reload') }}</a></p>
        </section>
        {{ \Larena\Env\Admin\JobOutcome::liveEnd() }}
    @elseif ($status === null)
        <section class="larena-panel larena-empty" data-env-state="{{ ($missing ?? false) ? 'missing' : ($refused ? 'refused' : 'failed') }}" role="alert">
            <h2 class="text-2">{{ ($missing ?? false) ? __('larena-env::admin.job.missing_title') : ($refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title')) }}</h2>
            <p>{{ ($missing ?? false) ? __('larena-env::admin.job.missing') : $reasonText }}</p>
        </section>
    @else
        {{ \Larena\Env\Admin\JobOutcome::live($status, route('larena.env.admin.job', ['job' => $status->jobId], false)) }}
        <section class="larena-panel larena-panel-padded" data-env-job="{{ $status->jobId }}" data-env-job-state="{{ $status->state->value }}" aria-labelledby="env-job">
            {{-- The state, large and in words (owner 2026-11-18): the server's own (simai-env 1.60.0 state_words —
                 Выполняется, Готово, Ошибка, Отменено, Занято) on the Framework's badge, spinning while it runs. --}}
            <p class="m-0 m-bottom-1" data-env-job-state-words="{{ $status->state->value }}">
                <sf-badge size="1" appearance="tonal" activity="{{ \Larena\Env\Admin\JobOutcome::activity($status) }}" text="{{ \Larena\Env\Admin\JobOutcome::stateWords($status) }}"></sf-badge>
            </p>
            {{-- What became, in words (PX-6): «Сайт demo.test удалён», not «Готово» with who started it. --}}
            <h2 id="env-job" class="text-2">{{ $status->state->isOver() ? \Larena\Env\Admin\JobOutcome::heading($status) : ($status->title ?? __('larena-env::admin.job.states.running')) }}</h2>
            {{-- The run on the Framework's sf-progress-run (PX-7, PX-11): no steps from the server yet, so a bar without a per
                 cent while it runs, what became once over; «Свернуть» leaves it to the corner. --}}
            {{ \Larena\Env\Admin\JobOutcome::progress($status) }}
            @if (! $status->state->isOver())
                {{-- Where it stands in the server's words (simai-env 1.60.0): «2 из 5: Создаётся база». --}}
                <p class="m-top-1" data-env-job-running>@if (\Larena\Env\Admin\JobOutcome::where($status) !== '')<span data-env-job-where>{{ \Larena\Env\Admin\JobOutcome::where($status) }}</span>. @endif{{ __('larena-env::admin.job.running') }}</p>
            @elseif ($status->state->value === 'failed' && ($status->outcome['words'] ?? null) !== null && $status->outcome['words'] !== $status->outcome['code'])
                {{-- Why it failed, as the server says it (its last error, not «see the log»): the run above says it already. --}}
            @elseif ($status->state->value === 'failed' && \Larena\Env\Admin\JobOutcome::failureLines($log->lines ?? []) !== [])
                {{-- No words of the server's (an older one, a lost job): the last lines of its output that speak of the error. --}}
                <div class="m-top-1" role="alert" data-env-job-failure-lines>
                    <p class="m-0 m-bottom-1">{{ __('larena-env::admin.job.failure_lines') }}</p>
                    <pre class="m-0" tabindex="0" style="max-width: 100%; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere;">{{ implode("\n", \Larena\Env\Admin\JobOutcome::failureLines($log->lines ?? [])) }}</pre>
                </div>
            @endif
            @if ($status->state->isOver() && \Larena\Env\Admin\JobOutcome::canAgain($status) && ($canAgain ?? false))
                {{-- «Повторить» (owner 2026-11-18): the same command with the same values, planned again — the plan and its
                     confirmation in place (the side panel of «История» too), nothing runs before it is confirmed. --}}
                <form method="post" action="{{ route('larena.env.admin.job.again', ['job' => $status->jobId], false) }}" class="m-0 m-top-1" data-env-job-again data-larena-action-form>
                    @csrf
                    <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.job.again') }}</button>
                </form>
            @endif
            @if (($configFailed ?? null) !== null)
                <p role="alert" data-env-job-config-failed="{{ $configFailed }}">{{ __('larena-env::admin.services.config_failed', ['name' => $configFailed]) }}</p>
            @endif
            @if (($change ?? null) !== null)
                @include('larena-env::admin.partials.timed-change', ['change' => $change, 'canKeep' => $canKeep ?? false, 'now' => $now ?? new \DateTimeImmutable(), 'changeDomain' => $status->args['domain'] ?? null])
            @endif
            {{-- WordPress's administrator (wp:install, simai-env 1.56.0): the server gives the password in this one answer and
                 keeps no copy; the panel keeps none either. Later answers only say it was shown. Copied with one press; the
                 side panel asks before it closes over it (data-larena-action-keep). --}}
            @if ($status->secret !== null)
                <div class="larena-panel larena-panel-padded m-y-2" role="alert" data-env-job-secret data-larena-action-keep="{{ __('larena-env::admin.job.secret_keep') }}">
                    <h3 class="m-top-0 text-medium">{{ __('larena-env::admin.routes.password.title') }}</h3>
                    <p>{{ __('larena-env::admin.routes.password.save_now') }}</p>
                    <dl class="m-0" style="display: block;" data-env-facts>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.password.user') }}</dt><dd class="m-0 min-w-0 text-end mono">{{ $status->secret->user }}</dd></div>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.password.password') }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;" data-env-job-password>{{ $status->secret->password }}</dd></div>
                    </dl>
                    <p class="flex flex-wrap g-1 m-top-1">
                        <sf-button-copy value="{{ $status->secret->password }}" text="{{ __('larena-env::admin.job.copy_password') }}" label="{{ __('larena-env::admin.job.copy_password') }}" appearance="outline" data-env-job-copy></sf-button-copy>
                        @if ($status->secret->loginUrl !== null)
                            <a href="{{ $status->secret->loginUrl }}" target="_blank" rel="noopener noreferrer" class="larena-button" data-env-job-login>{{ __('larena-env::admin.routes.password.login') }}</a>
                        @endif
                    </p>
                </div>
            @elseif (in_array('admin_password', $status->shownOnce, true))
                <p role="note" data-env-job-secret-shown>{{ __('larena-env::admin.routes.password.shown') }}</p>
            @endif
            {{-- A site's database browser (site:db-browser, simai-env 1.58.0): the server gives its address in this one answer
                 and never in the log; the panel keeps none. It opens in a new tab with no referrer; later answers only say it
                 was shown, and the site's «База данных» says until when it stays open. --}}
            @if ($status->browser !== null)
                <div class="larena-panel larena-panel-padded m-y-2" role="alert" data-env-job-browser data-larena-action-keep="{{ __('larena-env::admin.db_browser.keep') }}">
                    <h3 class="m-top-0 text-medium">{{ __('larena-env::admin.db_browser.ready') }}</h3>
                    <p>{{ __('larena-env::admin.db_browser.once') }}</p>
                    @if ($status->browser->expiresAt !== null && \Larena\Env\Server\Moment::iso($status->browser->expiresAt) !== null)
                        <p data-env-job-browser-until>{{ __('larena-env::admin.db_browser.until_label') }} <sf-time datetime="{{ \Larena\Env\Server\Moment::iso($status->browser->expiresAt) }}" format="time"></sf-time>@if ($status->browser->database !== null) · <span class="mono">{{ $status->browser->database }}</span>@endif</p>
                    @endif
                    <p class="flex flex-wrap g-1 m-top-1">
                        <a href="{{ $status->browser->url }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="larena-button larena-button-primary" data-env-job-browser-open>{{ __('larena-env::admin.db_browser.open_link') }}</a>
                    </p>
                </div>
            @elseif ($status->command === 'site:db-browser' && in_array('browser_url', $status->shownOnce, true))
                <p role="note" data-env-job-browser-shown>{{ __('larena-env::admin.db_browser.shown') }}</p>
            @endif
            {{-- How to connect to the site's database (site:db-credentials, simai-env 1.59.0): the server gives the password in
                 this one answer and never in the log; the panel keeps it nowhere. Copied with one press; the side panel asks
                 before it closes over it. Later answers only say it was shown. --}}
            @if ($status->credentials !== null)
                <div class="larena-panel larena-panel-padded m-y-2" role="alert" data-env-job-credentials data-larena-action-keep="{{ __('larena-env::admin.database.credentials.keep') }}">
                    <h3 class="m-top-0 text-medium">{{ __('larena-env::admin.database.credentials.title') }}</h3>
                    <p><strong>{{ __('larena-env::admin.database.credentials.secret') }}</strong> {{ __('larena-env::admin.database.credentials.once') }}</p>
                    <dl class="m-0" style="display: block;" data-env-facts>
                        @foreach (['engine', 'host', 'port', 'database', 'user'] as $field)
                            @if ($status->credentials->{$field} !== null)
                                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.credentials.'.$field) }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;" data-env-job-credential="{{ $field }}">{{ $field === 'engine' && __('larena-env::admin.new_site.engines.'.$status->credentials->engine) !== 'larena-env::admin.new_site.engines.'.$status->credentials->engine ? __('larena-env::admin.new_site.engines.'.$status->credentials->engine) : $status->credentials->{$field} }}</dd></div>
                            @endif
                        @endforeach
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.credentials.password') }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;" data-env-job-db-password>{{ $status->credentials->password }}</dd></div>
                    </dl>
                    <p class="flex flex-wrap g-1 m-top-1">
                        <sf-button-copy value="{{ $status->credentials->password }}" text="{{ __('larena-env::admin.database.credentials.copy') }}" label="{{ __('larena-env::admin.database.credentials.copy') }}" appearance="outline" data-env-job-credentials-copy></sf-button-copy>
                    </p>
                    <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.database.credentials.tunnel') }}</p>
                </div>
            @elseif ($status->command === 'site:db-credentials' && in_array('db_password', $status->shownOnce, true))
                <p role="note" data-env-job-credentials-shown>{{ __('larena-env::admin.database.credentials.shown') }}</p>
            @endif
            @if ($status->state->isOver() && $status->command === 'site:add' && is_string($status->args['domain'] ?? null) && $status->state->value === 'succeeded')
                <p><a href="{{ route('larena.env.admin.site', ['domain' => $status->args['domain']], false) }}" class="larena-button" data-env-job-next-site>{{ __('larena-env::admin.routes.to_site') }}</a></p>
            @elseif ($status->state->isOver() && \Larena\Env\Admin\JobOutcome::next($status) !== null)
                {{-- The next step (PX-6, PX-15): the list after a removal, the site over https, HTTPS after WordPress. --}}
                <p><a href="{{ \Larena\Env\Admin\JobOutcome::next($status)[0] }}" class="larena-button {{ $status->state->value === 'succeeded' ? 'larena-button-primary' : '' }}" data-env-job-next>{{ \Larena\Env\Admin\JobOutcome::next($status)[1] }}</a></p>
            @endif
            <dl class="m-0" style="display: block;" data-env-facts>
                {{-- The reader's own time (PX-6), the full moment on hover; who started it is the journal's. --}}
                {{-- In the server's own zone (simai-env 1.60.0 self:describe timezone), how long ago in the tooltip. --}}
                @foreach (['started' => $status->startedAt ?? $status->createdAt, 'finished' => $status->finishedAt] as $which => $moment)
                    @if ($moment !== null)
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.job.'.$which.'_at') }}</dt><dd class="m-0 min-w-0 text-end" data-env-job-{{ $which }} title="{{ \Larena\Env\Server\Moment::ago($moment, $now ?? new \DateTimeImmutable(), static fn (string $key, array $replace = []): string => __('larena-env::admin.'.$key, $replace)) }}">@if (\Larena\Env\Server\Moment::iso($moment) !== null)<sf-time datetime="{{ \Larena\Env\Server\Moment::iso($moment) }}" format="datetime" @if (($timezone ?? null) !== null) time-zone="{{ $timezone }}" @endif></sf-time>@else{{ \Larena\Env\Server\Moment::human($moment) }}@endif</dd></div>
                    @endif
                @endforeach
                @if ($status->state->isOver() && $status->seconds() !== null)
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.job.took') }}</dt><dd class="m-0 min-w-0 text-end" data-env-job-took>{{ \Larena\Admin\Http\Controllers\Product\AdminHistoryController::duration($status->seconds(), app('translator')) }}</dd></div>
                @endif
            </dl>
            {{-- One «Подробнее» for a specialist (PX-8): the server's command, its exit code and what it printed; open after a
                 failure, folded otherwise. --}}
            <details class="m-y-2" data-env-job-server @if ($status->state->value === 'failed') open @endif>
                <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.more') }}</summary>
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.command') }}</dt><dd class="m-0 min-w-0 text-end mono">{{ $status->command }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.job.exit_code') }}</dt><dd class="m-0 min-w-0 text-end">{{ $status->exitCode ?? '—' }}</dd></div>
                </dl>
                <div data-env-job-log>
                    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.job.log_details') }}</h3>
                    @if ($log === null)
                        <p>{{ $reasonText ?? __('larena-env::admin.job.no_log') }}</p>
                    @else
                        <pre data-env-job-log-lines tabindex="0" style="max-width: 100%; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere;">{{ implode("\n", $log->lines) }}</pre>
                    @endif
                </div>
            </details>
            @if (! $status->state->isOver() && ($updating ?? null) !== null)
                <p data-env-job-not-cancellable>{{ __('larena-env::admin.update.not_cancellable') }}</p>
            @elseif (! $status->state->isOver() && ! $status->cancellable())
                {{-- The server says it cannot be stopped now, and why (simai-env 1.60.0 cancel). --}}
                <p data-env-job-not-cancellable>{{ $status->cancel['leaves'] ?? __('larena-env::admin.job.not_cancellable') }}</p>
            @elseif (! $status->state->isOver())
                {{-- Stopping asks once and says what stays done (PX-9), in the server's words where it gives them; in the side
                     panel it stays in place. --}}
                <form method="post" action="{{ route('larena.env.admin.job.cancel', ['job' => $status->jobId], false) }}" data-env-job-cancel data-larena-action-form
                    data-larena-confirm-submit="{{ ($status->cancel['leaves'] ?? null) !== null ? __('larena-env::admin.job.cancel_ask_leaves', ['leaves' => $status->cancel['leaves']]) : __('larena-env::admin.job.cancel_ask') }}" data-larena-confirm-accept="{{ __('larena-env::admin.job.cancel') }}" data-larena-confirm-danger="1">
                    @csrf
                    <button type="submit" class="larena-button">{{ __('larena-env::admin.job.cancel') }}</button>
                </form>
            @endif
            {{ \Larena\Env\Admin\JobOutcome::mark($status) }}
        </section>
        {{ \Larena\Env\Admin\JobOutcome::liveEnd() }}
    @endif
    {{ \Larena\Admin\Runtime\AdminPlannedAction::close() }}
    <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
@endsection
