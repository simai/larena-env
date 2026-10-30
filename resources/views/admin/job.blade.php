@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.job.title'))
@section('heading', __('larena-env::admin.job.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.job.description'))

@section('content')
    @if ($source === 'recorded')
        <section class="larena-panel larena-panel-padded" data-env-source="recorded" role="note">
            <p>{{ __('larena-env::admin.recorded_notice') }}</p>
        </section>
    @endif

    @if ($notice)
        <section class="larena-panel larena-panel-padded" data-env-notice role="alert">
            <p>{{ $notice }}</p>
        </section>
    @endif

    @if ($status === null)
        <section class="larena-panel larena-empty" data-env-state="{{ $refused ? 'refused' : 'failed' }}" role="alert">
            <h2>{{ $refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title') }}</h2>
            <p>{{ $reasonText }}</p>
        </section>
    @else
        <section class="larena-panel larena-panel-padded" data-env-job="{{ $status->jobId }}" data-env-job-state="{{ $status->state->value }}" aria-labelledby="env-job">
            <h2 id="env-job">{{ __('larena-env::admin.job.states.'.$status->state->value) }}</h2>
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-env::admin.plan.command') }}</dt><dd>{{ $status->command }}</dd></div>
                <div><dt>{{ __('larena-env::admin.job.started_at') }}</dt><dd>{{ $status->createdAt ?? '—' }}</dd></div>
                <div><dt>{{ __('larena-env::admin.job.started_by') }}</dt><dd>{{ $status->actor ?? '—' }}</dd></div>
                <div><dt>{{ __('larena-env::admin.job.exit_code') }}</dt><dd>{{ $status->exitCode ?? '—' }}</dd></div>
            </dl>
            @if (! $status->state->isOver())
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
                <pre data-env-job-log-lines>{{ implode("\n", $log->lines) }}</pre>
                @if (! $status->state->isOver())
                    <p><a href="{{ route('larena.env.admin.job', ['job' => $status->jobId], false) }}?after={{ $log->next }}">{{ __('larena-env::admin.job.more') }}</a></p>
                @endif
            @endif
        </section>
    @endif
    <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
@endsection
