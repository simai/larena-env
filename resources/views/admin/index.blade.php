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
        @include('larena-env::admin.partials.warnings')
        <section class="larena-panel larena-panel-padded" data-env-state="ok" aria-labelledby="env-server-status">
            <h2 id="env-server-status">{{ __('larena-env::admin.status.title') }}</h2>
            <dl class="larena-recipe-facts" style="grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1px;">
                <div><dt>{{ __('larena-env::admin.status.os') }}</dt><dd>{{ $overview->osId }} {{ $overview->osVersion }}</dd></div>
                <div><dt>{{ __('larena-env::admin.status.env') }}</dt><dd>{{ $overview->envVersion }}</dd></div>
                <div><dt>{{ __('larena-env::admin.status.php') }}</dt><dd>{{ implode(', ', $overview->phpVersions) }}</dd></div>
                <div><dt>{{ __('larena-env::admin.status.generated_at') }}</dt><dd>{{ \Larena\Env\Server\Moment::human($overview->generatedAt) }}</dd></div>
            </dl>
            <h3>{{ __('larena-env::admin.status.services') }}</h3>
            <dl class="larena-recipe-facts" style="grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1px;">
                @foreach ($overview->services as $service)
                    <div data-env-service="{{ $service->name }}"><dt>{{ $service->name }}</dt><dd>{{ $service->state }}</dd></div>
                @endforeach
            </dl>
        </section>
        @if (($metrics ?? null) !== null)
            <section class="larena-panel larena-panel-padded" data-env-metrics aria-labelledby="env-server-metrics">
                <h2 id="env-server-metrics">{{ __('larena-env::admin.metrics.title') }}</h2>
                <dl class="larena-recipe-facts" style="grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: 1px;">
                    <div><dt>{{ __('larena-env::admin.metrics.cores') }}</dt><dd>{{ $metrics->cores ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.metrics.load') }}</dt><dd>{{ $metrics->load === null ? '—' : implode(' · ', array_map(static fn (float $value): string => number_format($value, 2), $metrics->load)) }}</dd></div>
                    @if ($metrics->memoryTotal !== null && $metrics->memoryUsed !== null)
                        <div><dt>{{ __('larena-env::admin.metrics.memory') }}</dt><dd>{{ __('larena-env::admin.metrics.used', ['used' => \Larena\Env\Server\Bytes::human($metrics->memoryUsed), 'total' => \Larena\Env\Server\Bytes::human($metrics->memoryTotal), 'percent' => (string) \Larena\Env\Server\Bytes::percent($metrics->memoryUsed, $metrics->memoryTotal)]) }}</dd></div>
                    @endif
                    @if ($metrics->swapTotal !== null && $metrics->swapUsed !== null && $metrics->swapTotal > 0)
                        <div><dt>{{ __('larena-env::admin.metrics.swap') }}</dt><dd>{{ __('larena-env::admin.metrics.used', ['used' => \Larena\Env\Server\Bytes::human($metrics->swapUsed), 'total' => \Larena\Env\Server\Bytes::human($metrics->swapTotal), 'percent' => (string) \Larena\Env\Server\Bytes::percent($metrics->swapUsed, $metrics->swapTotal)]) }}</dd></div>
                    @endif
                    @foreach ($metrics->disks as $disk)
                        <div data-env-disk-mount="{{ $disk['mount'] }}"><dt>{{ __('larena-env::admin.metrics.disk', ['mount' => $disk['mount']]) }}</dt><dd>{{ __('larena-env::admin.metrics.used', ['used' => \Larena\Env\Server\Bytes::human($disk['used']), 'total' => \Larena\Env\Server\Bytes::human($disk['total']), 'percent' => (string) \Larena\Env\Server\Bytes::percent($disk['used'], $disk['total'])]) }}</dd></div>
                    @endforeach
                    <div><dt>{{ __('larena-env::admin.metrics.checked_at') }}</dt><dd>{{ \Larena\Env\Server\Moment::human($metrics->checkedAt) }}</dd></div>
                </dl>
            </section>
        @endif
        @include('larena-env::admin.partials.history')
    @else
        @include('larena-env::admin.partials.warnings')
        @if ($canCreate ?? false)
            <p data-env-create-site><a class="larena-button" href="{{ route('larena.env.admin.sites.new', [], false) }}">{{ __('larena-env::admin.new_site.action') }}</a></p>
        @endif
        @include('larena-admin::dataview.page', ['dataview' => $sitesView, 'sectionId' => 'env-server-sites', 'owner' => 'larena/env',
            'ariaLabel' => __('larena-env::admin.sites.title'), 'queryUrl' => route('larena.env.admin.index', [], false),
            'matchedCount' => count($overview->sites), 'source' => 'env_server_overview'])
    @endif
@endsection
