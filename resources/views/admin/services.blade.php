@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.services.title'))
@section('heading', __('larena-env::admin.services.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.services.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The server's services (Panel plan 5.1): short rows with large buttons, for an emergency from a phone. --}}
    <section class="larena-panel larena-panel-padded" data-env-services="{{ $allows['restart'] || $allows['reload'] ? 'on' : 'read' }}" aria-labelledby="env-services">
        <h2 id="env-services">{{ __('larena-env::admin.services.list') }}</h2>
        @if ($services === [])
            <p role="alert">{{ $reasonText ?? __('larena-env::admin.services.none') }}</p>
        @else
            @if (!$allows['restart'] && !$allows['reload'])
                <p data-env-services-unsupported>{{ __('larena-env::admin.services.read_only') }}</p>
            @endif
            <ul class="larena-list" style="list-style: none; padding-inline-start: 0; display: grid; gap: .75rem;">
                @foreach ($services as $service)
                    <li data-env-service="{{ $service->name }}" data-env-service-state="{{ $service->state }}"
                        style="display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .75rem; border: 1px solid var(--larena-line); border-radius: .625rem;">
                        <span style="flex: 1 1 10rem; min-width: 0; overflow-wrap: anywhere;">
                            <strong>{{ $service->name }}</strong><br>
                            <span @if (!$service->isActive()) role="alert" @endif>{{ $service->isActive() ? __('larena-env::admin.services.running') : __('larena-env::admin.services.stopped', ['state' => $service->state]) }}</span>
                        </span>
                        @if ($allows['restart'])
                            <form method="post" action="{{ route('larena.env.admin.services.plan', [], false) }}" data-env-service-op="restart">
                                @csrf
                                <input type="hidden" name="op" value="restart">
                                <input type="hidden" name="name" value="{{ $service->name }}">
                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.services.restart') }}</button>
                            </form>
                        @endif
                        @if ($allows['reload'] && \Larena\Env\Http\Controllers\ServicesController::reloads($service->name))
                            <form method="post" action="{{ route('larena.env.admin.services.plan', [], false) }}" data-env-service-op="reload">
                                @csrf
                                <input type="hidden" name="op" value="reload">
                                <input type="hidden" name="name" value="{{ $service->name }}">
                                <button type="submit" style="min-height: 2.75rem;">{{ __('larena-env::admin.services.reload') }}</button>
                            </form>
                        @endif
                        @if ($allows['log'])
                            <a href="{{ route('larena.env.admin.services', [], false) }}?log={{ urlencode($service->name) }}#env-service-log" data-env-service-log-link="{{ $service->name }}"
                                style="display: inline-flex; align-items: center; min-height: 2.75rem;">{{ __('larena-env::admin.services.journal') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p>{{ __('larena-env::admin.services.checked_first') }}</p>
        @endif
    </section>

    @if ($logOf !== null)
        <section class="larena-panel larena-panel-padded" data-env-service-log="{{ $logOf }}" aria-labelledby="env-service-log">
            <h2 id="env-service-log">{{ __('larena-env::admin.services.journal_of', ['name' => $logOf]) }}</h2>
            @if ($log === null)
                <p role="alert">{{ __('larena-env::admin.services.journal_unreadable') }}</p>
            @elseif ($log === [])
                <p>{{ __('larena-env::admin.services.journal_empty') }}</p>
            @else
                <pre tabindex="0" style="max-width: 100%; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere;">{{ implode("\n", $log) }}</pre>
            @endif
        </section>
    @endif
@endsection
