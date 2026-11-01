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
            <h2>{{ __('larena-env::admin.update.restarting_title') }}</h2>
            <p>{{ __('larena-env::admin.update.restarting') }}</p>
            <p><a href="{{ route('larena.env.admin.job', ['job' => $jobId], false) }}" class="larena-button">{{ __('larena-env::admin.update.reload') }}</a></p>
        </section>
    @elseif ($status === null)
        <section class="larena-panel larena-empty" data-env-state="{{ $refused ? 'refused' : 'failed' }}" role="alert">
            <h2>{{ $refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title') }}</h2>
            <p>{{ $reasonText }}</p>
        </section>
    @else
        <section class="larena-panel larena-panel-padded" data-env-job="{{ $status->jobId }}" data-env-job-state="{{ $status->state->value }}" aria-labelledby="env-job">
            <h2 id="env-job">{{ __('larena-env::admin.job.states.'.$status->state->value) }}</h2>
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-env::admin.plan.command') }}</dt><dd>{{ $status->command }}</dd></div>
                <div><dt>{{ __('larena-env::admin.job.started_at') }}</dt><dd>{{ \Larena\Env\Server\Moment::human($status->createdAt) }}</dd></div>
                <div><dt>{{ __('larena-env::admin.job.started_by') }}</dt><dd>{{ $status->actor ?? '—' }}</dd></div>
                <div><dt>{{ __('larena-env::admin.job.exit_code') }}</dt><dd>{{ $status->exitCode ?? '—' }}</dd></div>
            </dl>
            @if (! $status->state->isOver() && ($updating ?? null) !== null)
                <p data-env-job-not-cancellable>{{ __('larena-env::admin.update.not_cancellable') }}</p>
            @elseif (! $status->state->isOver())
                <form method="post" action="{{ route('larena.env.admin.job.cancel', ['job' => $status->jobId], false) }}" data-env-job-cancel>
                    @csrf
                    <button type="submit">{{ __('larena-env::admin.job.cancel') }}</button>
                </form>
            @endif
        </section>

        <section class="larena-panel larena-panel-padded" data-env-job-log aria-labelledby="env-job-log">
            <h2 id="env-job-log">{{ __('larena-env::admin.job.log') }}</h2>
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
