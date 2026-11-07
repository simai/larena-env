@extends('larena-admin::layouts.app')

@section('title', $section === 'overview' ? __('larena-env::admin.overview.title') : __('larena-env::admin.title'))
@section('heading', $section === 'overview' ? __('larena-env::admin.overview.heading') : __('larena-env::admin.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.description'))

@if ($section !== 'overview' && ($canCreate ?? false) && $overview !== null)
    {{-- The main action sits in the page's head, where every admin page keeps it. --}}
    @section('actions')
        <a class="larena-button larena-button-primary" data-env-create-site href="{{ route('larena.env.admin.sites.new', [], false) }}">{{ __('larena-env::admin.new_site.action') }}</a>
    @endsection
@endif

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($overview === null)
        <section class="larena-panel larena-empty" data-env-state="{{ $refused ? 'refused' : 'failed' }}" data-env-reason="{{ $reason }}" role="alert">
            <h2 class="text-2">{{ $refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title') }}</h2>
            <p>{{ $reasonText }}</p>
        </section>
    @elseif ($section === 'overview')
        {{-- State first, details after (owner-approved prototype, 2026-11-02): what needs a person, a waiting update, the
             measures as tiles, then the server and its services, then the load over time. --}}
        @if (($metrics ?? null) !== null)
            <section class="m-bottom-2" data-env-metrics aria-labelledby="env-server-metrics">
                <div class="flex flex-wrap items-cross-center g-1 m-bottom-1">
                    <h2 id="env-server-metrics" class="m-0 text-2">{{ __('larena-env::admin.metrics.title') }}</h2>
                    <span class="color-on-surface-variant">{{ __('larena-env::admin.metrics.checked_at') }} {{ \Larena\Env\Server\Moment::human($metrics->checkedAt) }}</span>
                </div>
                <div class="grid g-2 grid-col-1 md:grid-col-2 xl:grid-col-4">
                    @foreach (\Larena\Env\Admin\ServerMetricTiles::of($metrics, ($history ?? null)['day'] ?? null, static fn (string $key, array $replace = []): string => __('larena-env::admin.'.$key, $replace)) as $tile)
                        @include('larena-env::admin.partials.metric', $tile)
                    @endforeach
                </div>
            </section>
        @endif
        @if (($actions ?? []) !== [])
            {{-- «Рекомендуемые действия» (owner 2026-11-06): what to do now first, then what is advised, the done ones folded;
                 each with its one click, which goes through the usual plan and confirmation. --}}
            <section class="larena-panel larena-panel-padded m-bottom-2" data-env-actions aria-labelledby="env-actions">
                <h2 id="env-actions" class="m-0 text-2">{{ __('larena-env::admin.actions.title') }}</h2>
                <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.actions.summary', ['now' => (string) $actionCounts['now'], 'todo' => (string) $actionCounts['todo'], 'done' => (string) $actionCounts['done']]) }}</p>
                {{-- All the urgent ones and at most five advised are in view; the rest folds (critics 2026-11-07). --}}
                <ul class="m-0 m-t-1 p-0" style="list-style: none;">
                    @foreach (\Larena\Env\Admin\RecommendedActions::inView($actions) as $item)
                        @include('larena-env::admin.partials.recommended-action', ['item' => $item])
                    @endforeach
                </ul>
                @if (($folded = \Larena\Env\Admin\RecommendedActions::folded($actions)) !== [])
                    <details class="m-t-1" data-env-actions-more>
                        <summary style="cursor: pointer; min-height: 2.75rem; display: flex; align-items: center;">{{ __('larena-env::admin.actions.more_list', ['count' => (string) count($folded)]) }}</summary>
                        <ul class="m-0 p-0" style="list-style: none;">
                            @foreach ($folded as $item)
                                @include('larena-env::admin.partials.recommended-action', ['item' => $item])
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if ($actionCounts['done'] > 0)
                    <details class="m-t-1" data-env-actions-done>
                        <summary style="cursor: pointer; min-height: 2.75rem; display: flex; align-items: center;">{{ __('larena-env::admin.actions.done_list', ['count' => (string) $actionCounts['done']]) }}</summary>
                        <ul class="m-0 p-0" style="list-style: none;">
                            @foreach ($actions as $item)
                                @continue($item['state'] !== 'done')
                                @include('larena-env::admin.partials.recommended-action', ['item' => $item])
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        @endif
        @if (($updates ?? []) !== [])
            <section class="larena-panel flex flex-wrap items-cross-center g-2 p-2 m-bottom-2" data-env-update-hint role="note" aria-labelledby="env-update-hint">
                <sf-badge status="info" appearance="tonal" text="{{ __('larena-env::admin.update.hint_title') }}" id="env-update-hint"></sf-badge>
                <ul class="grow min-w-0 m-0 p-0 flex flex-wrap g-2" style="list-style: none; flex-basis: 16rem;">
                    @foreach ($updates as $update)
                        <li data-env-update-hint-target="{{ $update['target'] }}">{{ __('larena-env::admin.update.hint.'.$update['target'], ['available' => $update['available'] ?? '—']) }}</li>
                    @endforeach
                </ul>
                <a class="larena-button" href="{{ route('larena.env.admin.software', [], false) }}#update">{{ __('larena-env::admin.update.hint_open') }}</a>
            </section>
        @endif
        <div class="grid g-2 grid-col-1 lg:grid-col-2 m-bottom-2" style="align-items: start;">
            <section class="larena-panel p-2" style="margin: 0;" data-env-state="ok" aria-labelledby="env-server-status">
                <h2 id="env-server-status" class="m-top-0 text-2">{{ __('larena-env::admin.status.server') }}</h2>
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.status.os') }}</dt><dd class="m-0" style="text-align: end;">{{ $overview->osId }} {{ $overview->osVersion }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.status.env') }}</dt><dd class="m-0" style="text-align: end;">{{ $overview->envVersion }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.status.php') }}</dt><dd class="m-0" style="text-align: end;">{{ implode(', ', $overview->phpVersions) }}</dd></div>
                    @if (($metrics ?? null) !== null && $metrics->cores !== null)
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.metrics.cores') }}</dt><dd class="m-0" style="text-align: end;">{{ $metrics->cores }}</dd></div>
                    @endif
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.status.generated_at') }}</dt><dd class="m-0" style="text-align: end;">{{ \Larena\Env\Server\Moment::human($overview->generatedAt) }}</dd></div>
                </dl>
            </section>
            <section class="larena-panel" style="margin: 0;" aria-labelledby="env-server-services">
                <div class="flex items-cross-center content-main-between g-1 p-2 p-bottom-1">
                    <h2 id="env-server-services" class="m-0 text-2">{{ __('larena-env::admin.status.services') }}</h2>
                    <a href="{{ route('larena.env.admin.services', [], false) }}">{{ __('larena-env::admin.status.all_services') }}</a>
                </div>
                <ul class="m-0 p-0" style="list-style: none;">
                    @foreach ($services ?? $overview->services as $service)
                        <li class="flex items-cross-center content-main-between g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-service="{{ $service->name }}">
                            <span class="truncate" title="{{ $service->name }}">{{ \Larena\Env\Server\HostService::label($service->name) }}</span>
                            <sf-badge status="{{ $service->isActive() ? 'success' : 'error' }}" appearance="tonal"
                                text="{{ $service->isActive() ? __('larena-env::admin.services.running') : __('larena-env::admin.services.stopped') }}"></sf-badge>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
        @if (($charts ?? null) !== null)
            @include('larena-env::admin.partials.charts')
        @else
            @include('larena-env::admin.partials.history')
        @endif
    @else
        @include('larena-admin::dataview.page', ['dataview' => $sitesView, 'sectionId' => 'env-server-sites', 'owner' => 'larena/env',
            'ariaLabel' => __('larena-env::admin.sites.title'), 'queryUrl' => route('larena.env.admin.index', [], false),
            'matchedCount' => count($overview->sites), 'source' => 'env_server_overview'])
    @endif
@endsection
