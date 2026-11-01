@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.panel_access.title'))
@section('heading', __('larena-env::admin.panel_access.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.panel_access.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Who reaches the panel (Panel plan 5.3), the system level's: kept only when confirmed in time, from the panel itself. --}}
    <section class="larena-panel larena-panel-padded" data-env-panel-access="{{ !$supported ? 'unsupported' : ($status?->panelAccess === null ? 'unknown' : (is_string($status->panelAccess) ? 'any' : 'list')) }}" aria-labelledby="env-panel-access">
        <h2 id="env-panel-access">{{ __('larena-env::admin.panel_access.now') }}</h2>
        @if (!$supported)
            <p data-env-panel-access-unsupported>{{ __('larena-env::admin.panel_access.unsupported') }}</p>
        @elseif ($status === null)
            <p role="alert">{{ __('larena-env::admin.firewall.unreadable') }}</p>
        @else
            @include('larena-env::admin.partials.timed-change', ['change' => $pending, 'canKeep' => $allows['keep']])
            @if ($status->panelAccess === null)
                <p>{{ __('larena-env::admin.panel_access.unknown') }}</p>
            @elseif ($status->panelAccess === 'any')
                <p data-env-panel-access-any>{{ __('larena-env::admin.panel_access.everyone') }}</p>
            @else
                <ul class="larena-list" data-env-panel-access-list>
                    @foreach ($status->panelAccess as $item)
                        <li style="overflow-wrap: anywhere;">{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
            <p data-env-panel-access-you="{{ $address }}">{{ __('larena-env::admin.panel_access.you', ['address' => $address]) }}</p>
            @if (!$addressListed)
                <p role="alert" data-env-panel-access-outside>{{ __('larena-env::admin.panel_access.you_outside') }}</p>
            @endif
        @endif
    </section>

    @if ($supported && $status !== null && $allows['access'])
        <section class="larena-panel larena-panel-padded" aria-labelledby="env-panel-access-change">
            <h2 id="env-panel-access-change">{{ __('larena-env::admin.panel_access.change') }}</h2>
            <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" class="larena-form" data-env-panel-access-form>
                @csrf
                <input type="hidden" name="op" value="access">
                <div class="larena-field">
                    <label for="env-panel-allow">{{ __('larena-env::admin.panel_access.allow') }}</label>
                    <textarea id="env-panel-allow" name="allow" rows="5" spellcheck="false" autocomplete="off" style="width: 100%;" placeholder="{{ $address }}">{{ is_array($status->panelAccess) ? implode("\n", $status->panelAccess) : '' }}</textarea>
                    <p>{{ __('larena-env::admin.panel_access.allow_hint') }}</p>
                </div>
                <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
            </form>
            @if ($status->panelAccess !== 'any')
                <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" data-env-panel-access-everyone>
                    @csrf
                    <input type="hidden" name="op" value="access">
                    <input type="hidden" name="everyone" value="yes">
                    <button type="submit" style="min-height: 2.75rem;">{{ __('larena-env::admin.panel_access.open_to_all') }}</button>
                </form>
            @endif
            <p>{{ __('larena-env::admin.timed.after_plan') }}</p>
        </section>
    @endif
@endsection
