@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.services.title'))
@section('heading', __('larena-env::admin.services.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.services.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The server's services (Panel plan 5.1), state first: how many work in the head, a stopped one at the top (the controller
         puts it there) in its colour; short rows with large buttons, for an emergency from a phone. --}}
    <div class="grid g-2">
        <section class="larena-panel" style="margin: 0;" data-env-services="{{ $allows['restart'] || $allows['reload'] ? 'on' : 'read' }}" aria-labelledby="env-services">
            <div class="flex flex-wrap items-cross-center content-main-between g-1 p-2 p-bottom-1">
                <h2 id="env-services" class="m-0">{{ __('larena-env::admin.services.list') }}</h2>
                @if ($services !== [])
                    <sf-badge data-env-services-summary status="{{ count(array_filter($services, static fn ($service): bool => !$service->isActive())) === 0 ? 'success' : 'error' }}" appearance="tonal"
                        text="{{ count(array_filter($services, static fn ($service): bool => !$service->isActive())) === 0 ? __('larena-env::admin.services.all_running', ['total' => (string) count($services)]) : __('larena-env::admin.services.some_stopped', ['stopped' => (string) count(array_filter($services, static fn ($service): bool => !$service->isActive())), 'total' => (string) count($services)]) }}"></sf-badge>
                @endif
            </div>
            @if ($services === [])
                <p class="p-x-2 p-bottom-2 m-0" role="alert">{{ $reasonText ?? __('larena-env::admin.services.none') }}</p>
            @else
                @if (!$allows['restart'] && !$allows['reload'])
                    <p class="p-x-2 m-0 m-bottom-1 color-on-surface-variant" data-env-services-unsupported>{{ __('larena-env::admin.services.read_only') }}</p>
                @endif
                <ul class="m-0 p-0" style="list-style: none;">
                    @foreach ($services as $service)
                        <li class="flex flex-wrap items-cross-center g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant"
                            data-env-service="{{ $service->name }}" data-env-service-state="{{ $service->active }}">
                            <div class="grow min-w-0" style="flex-basis: 12rem;">
                                <div class="flex flex-wrap items-cross-center g-1">
                                    <span class="mono" style="overflow-wrap: anywhere;">{{ $service->name }}</span>
                                    <sf-badge status="{{ $service->isActive() ? 'success' : 'error' }}" appearance="tonal" @if (!$service->isActive()) role="alert" @endif
                                        text="{{ $service->isActive() ? __('larena-env::admin.services.running') : __('larena-env::admin.services.stopped', ['state' => $service->active]) }}"></sf-badge>
                                </div>
                                @if ($service->since !== null || ($service->enabled !== null && $service->enabled !== 'enabled'))
                                    <div class="color-on-surface-variant text-small">
                                        @if ($service->since !== null)
                                            <span data-env-service-since>{{ __('larena-env::admin.services.since', ['since' => $service->since]) }}</span>
                                        @endif
                                        @if ($service->enabled !== null && $service->enabled !== 'enabled')
                                            <span data-env-service-disabled>@if ($service->since !== null) · @endif{{ __('larena-env::admin.services.not_at_boot') }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-cross-center g-1">
                                @if ($allows['restart'])
                                    <form method="post" action="{{ route('larena.env.admin.services.plan', [], false) }}" class="m-0" data-env-service-op="restart">
                                        @csrf
                                        <input type="hidden" name="op" value="restart">
                                        <input type="hidden" name="name" value="{{ $service->name }}">
                                        <button type="submit" class="larena-button{{ $service->isActive() ? '' : ' larena-button-primary' }}" style="min-height: 2.75rem;">{{ __('larena-env::admin.services.restart') }}</button>
                                    </form>
                                @endif
                                @if ($allows['reload'] && $service->canReload)
                                    <form method="post" action="{{ route('larena.env.admin.services.plan', [], false) }}" class="m-0" data-env-service-op="reload">
                                        @csrf
                                        <input type="hidden" name="op" value="reload">
                                        <input type="hidden" name="name" value="{{ $service->name }}">
                                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.services.reload') }}</button>
                                    </form>
                                @endif
                                @if ($allows['log'])
                                    <a class="flex items-cross-center p-x-1" style="min-height: 2.75rem;" href="{{ route('larena.env.admin.services', [], false) }}?log={{ urlencode($service->name) }}#env-service-log"
                                        data-env-service-log-link="{{ $service->name }}" @if ($logOf === $service->name) aria-current="true" @endif>{{ __('larena-env::admin.services.journal') }}</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="p-2 m-0 border-top-1 border-top-solid border-outline-variant color-on-surface-variant text-small">{{ __('larena-env::admin.services.checked_first') }}</p>
            @endif
        </section>

        @if ($logOf !== null)
            <section class="larena-panel p-2" style="margin: 0;" data-env-service-log="{{ $logOf }}" aria-labelledby="env-service-log">
                <h2 id="env-service-log" class="m-top-0">{{ __('larena-env::admin.services.journal_of', ['name' => $logOf]) }}</h2>
                @if ($log === null)
                    <p class="m-0" role="alert">{{ __('larena-env::admin.services.journal_unreadable') }}</p>
                @elseif ($log === [])
                    <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.services.journal_empty') }}</p>
                @else
                    <pre class="m-0 p-1 mono text-small" tabindex="0" style="max-width: 100%; max-height: 32rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere;">{{ implode("\n", $log) }}</pre>
                @endif
            </section>
        @endif
    </div>
@endsection
