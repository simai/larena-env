@extends('larena-admin::layouts.app')

@section('title', $section === 'overview' ? __('larena-env::admin.overview.title') : __('larena-env::admin.title'))
@section('heading', $section === 'overview' ? __('larena-env::admin.overview.heading') : __('larena-env::admin.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($overview === null)
        <section class="larena-panel larena-empty" data-env-state="{{ $refused ? 'refused' : 'failed' }}" data-env-reason="{{ $reason }}" role="alert">
            <h2>{{ $refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title') }}</h2>
            <p>{{ $reasonText }}</p>
        </section>
    @elseif ($section === 'overview')
        <section class="larena-panel larena-panel-padded" data-env-state="ok" aria-labelledby="env-server-status">
            <h2 id="env-server-status">{{ __('larena-env::admin.status.title') }}</h2>
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-env::admin.status.os') }}</dt><dd>{{ $overview->osId }} {{ $overview->osVersion }}</dd></div>
                <div><dt>{{ __('larena-env::admin.status.env') }}</dt><dd>{{ $overview->envVersion }}</dd></div>
                <div><dt>{{ __('larena-env::admin.status.php') }}</dt><dd>{{ implode(', ', $overview->phpVersions) }}</dd></div>
                <div><dt>{{ __('larena-env::admin.status.generated_at') }}</dt><dd>{{ $overview->generatedAt }}</dd></div>
            </dl>
            <h3>{{ __('larena-env::admin.status.services') }}</h3>
            <dl class="larena-recipe-facts">
                @foreach ($overview->services as $service)
                    <div data-env-service="{{ $service->name }}"><dt>{{ $service->name }}</dt><dd>{{ $service->state }}</dd></div>
                @endforeach
            </dl>
        </section>
    @else
        @if ($canCreate)
            <p data-env-create-site><a class="larena-button" href="{{ route('larena.env.admin.sites.new', [], false) }}">{{ __('larena-env::admin.new_site.action') }}</a></p>
        @endif
        @include('larena-admin::dataview.page', ['dataview' => $sitesView, 'sectionId' => 'env-server-sites', 'owner' => 'larena/env',
            'ariaLabel' => __('larena-env::admin.sites.title'), 'queryUrl' => route('larena.env.admin.index', [], false),
            'matchedCount' => count($overview->sites), 'source' => 'env_server_overview'])
    @endif
@endsection
