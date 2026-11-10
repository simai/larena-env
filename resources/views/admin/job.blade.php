@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.job.title'))
@section('heading', __('larena-env::admin.job.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.job.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($status === null && ($updating ?? null) !== null && ! $refused)
        {{-- An update replaces the gate and the panel while its job runs: the server may not answer for a moment. --}}
        <section class="larena-panel larena-panel-padded" data-env-job-restarting="{{ $updating }}" role="status">
            <h2 class="text-2">{{ __('larena-env::admin.update.restarting_title') }}</h2>
            <p>{{ __('larena-env::admin.update.restarting') }}</p>
            <p><a href="{{ route('larena.env.admin.job', ['job' => $jobId], false) }}" class="larena-button">{{ __('larena-env::admin.update.reload') }}</a></p>
        </section>
    @elseif ($status === null)
        <section class="larena-panel larena-empty" data-env-state="{{ ($missing ?? false) ? 'missing' : ($refused ? 'refused' : 'failed') }}" role="alert">
            <h2 class="text-2">{{ ($missing ?? false) ? __('larena-env::admin.job.missing_title') : ($refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title')) }}</h2>
            <p>{{ ($missing ?? false) ? __('larena-env::admin.job.missing') : $reasonText }}</p>
        </section>
    @else
        <section class="larena-panel larena-panel-padded" data-env-job="{{ $status->jobId }}" data-env-job-state="{{ $status->state->value }}" aria-labelledby="env-job">
            <h2 id="env-job" class="text-2">{{ __('larena-env::admin.job.states.'.$status->state->value) }}</h2>
            @if (($configFailed ?? null) !== null)
                <p role="alert" data-env-job-config-failed="{{ $configFailed }}">{{ __('larena-env::admin.services.config_failed', ['name' => $configFailed]) }}</p>
            @endif
            @if (($change ?? null) !== null)
                @include('larena-env::admin.partials.timed-change', ['change' => $change, 'canKeep' => $canKeep ?? false, 'now' => $now ?? new \DateTimeImmutable(), 'changeDomain' => $status->args['domain'] ?? null])
            @endif
            {{-- WordPress's administrator (wp:install, simai-env 1.56.0): the server gives the password in this one answer and
                 keeps no copy; the panel keeps none either. Later answers only say it was shown. --}}
            @if ($status->secret !== null)
                <div class="larena-panel larena-panel-padded m-y-2" role="alert" data-env-job-secret>
                    <h3 class="m-top-0 text-medium">{{ __('larena-env::admin.routes.password.title') }}</h3>
                    <p>{{ __('larena-env::admin.routes.password.save_now') }}</p>
                    <dl class="m-0" style="display: block;" data-env-facts>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.password.user') }}</dt><dd class="m-0 min-w-0 text-end mono">{{ $status->secret->user }}</dd></div>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.password.password') }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;" data-env-job-password>{{ $status->secret->password }}</dd></div>
                    </dl>
                    @if ($status->secret->loginUrl !== null)
                        <p><a href="{{ $status->secret->loginUrl }}" target="_blank" rel="noopener noreferrer" class="larena-button" data-env-job-login>{{ __('larena-env::admin.routes.password.login') }}</a></p>
                    @endif
                </div>
            @elseif (in_array('admin_password', $status->shownOnce, true))
                <p role="note" data-env-job-secret-shown>{{ __('larena-env::admin.routes.password.shown') }}</p>
            @endif
            @if ($status->state->isOver() && $status->command === 'site:add' && is_string($status->args['domain'] ?? null) && $status->state->value === 'succeeded')
                <p><a href="{{ route('larena.env.admin.site', ['domain' => $status->args['domain']], false) }}" class="larena-button" data-env-job-next-site>{{ __('larena-env::admin.routes.to_site') }}</a></p>
            @endif
            <dl class="m-0" style="display: block;" data-env-facts>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.command') }}</dt><dd class="m-0 min-w-0 text-end">{{ $status->command }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.job.started_at') }}</dt><dd class="m-0 min-w-0 text-end">{{ \Larena\Env\Server\Moment::human($status->createdAt) }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.job.started_by') }}</dt><dd class="m-0 min-w-0 text-end">{{ $status->actor ?? '—' }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.job.exit_code') }}</dt><dd class="m-0 min-w-0 text-end">{{ $status->exitCode ?? '—' }}</dd></div>
            </dl>
            @if (! $status->state->isOver() && ($updating ?? null) !== null)
                <p data-env-job-not-cancellable>{{ __('larena-env::admin.update.not_cancellable') }}</p>
            @elseif (! $status->state->isOver())
                <form method="post" action="{{ route('larena.env.admin.job.cancel', ['job' => $status->jobId], false) }}" data-env-job-cancel>
                    @csrf
                    <button type="submit" class="larena-button">{{ __('larena-env::admin.job.cancel') }}</button>
                </form>
            @endif
        </section>

        <section class="larena-panel larena-panel-padded" data-env-job-log aria-labelledby="env-job-log">
            <h2 id="env-job-log" class="text-2">{{ __('larena-env::admin.job.log') }}</h2>
            @if ($log === null)
                <p>{{ $reasonText ?? __('larena-env::admin.job.no_log') }}</p>
            @else
                <pre data-env-job-log-lines tabindex="0" style="max-width: 100%; overflow-x: auto;">{{ implode("\n", $log->lines) }}</pre>
                @if (! $status->state->isOver())
                    <p><a href="{{ route('larena.env.admin.job', ['job' => $status->jobId], false) }}?after={{ $log->next }}">{{ __('larena-env::admin.job.more') }}</a></p>
                @endif
            @endif
        </section>
    @endif
    <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
@endsection
