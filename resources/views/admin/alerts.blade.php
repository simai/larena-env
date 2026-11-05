@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.alerts.title'))
@section('heading', __('larena-env::admin.alerts.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.alerts.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Where the server sends its alerts (simai-env 1.31.0), the system level's. The bot token is never shown. State first:
         where they go and whether the last one went, then the change and the test beside it. --}}
    <div class="grid g-2 grid-col-1 lg:grid-col-2" style="align-items: start;">
        <section class="larena-panel p-2" style="margin: 0;" data-env-alerts-channel="{{ $supported ? ($channel['kind'] ?? 'off') : 'unsupported' }}" aria-labelledby="env-alerts-now">
            <div class="flex flex-wrap items-cross-center content-main-between g-1 m-bottom-1">
                <h2 id="env-alerts-now" class="m-0 text-2">{{ __('larena-env::admin.alerts.now') }}</h2>
                @if ($supported)
                    <sf-badge status="{{ ($channel['last_error'] ?? null) !== null ? 'error' : (($channel['kind'] ?? 'off') === 'off' ? 'warning' : 'success') }}" appearance="tonal"
                        text="{{ ($channel['last_error'] ?? null) !== null ? __('larena-env::admin.alerts.state_failing') : __('larena-env::admin.alerts.kinds.'.($channel['kind'] ?? 'off')) }}"></sf-badge>
                @endif
            </div>
            @if (!$supported)
                <p class="m-0 color-on-surface-variant" data-env-alerts-unsupported>{{ __('larena-env::admin.alerts.unsupported') }}</p>
            @else
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.alerts.kind') }}</dt><dd class="m-0" style="text-align: end;">{{ __('larena-env::admin.alerts.kinds.'.($channel['kind'] ?? 'off')) }}</dd></div>
                    @if (($channel['target_hint'] ?? null) !== null)
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.alerts.target_hint') }}</dt><dd class="m-0 min-w-0" style="text-align: end; overflow-wrap: anywhere;">{{ $channel['target_hint'] }}</dd></div>
                    @endif
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-alerts-last-sent><dt class="color-on-surface-variant">{{ __('larena-env::admin.alerts.last_sent') }}</dt><dd class="m-0" style="text-align: end;">{{ ($channel['last_sent_at'] ?? null) === null ? __('larena-env::admin.alerts.never') : \Larena\Env\Server\Moment::human($channel['last_sent_at']) }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-alerts-last-error><dt class="color-on-surface-variant">{{ __('larena-env::admin.alerts.last_error') }}</dt><dd class="m-0 min-w-0{{ ($channel['last_error'] ?? null) !== null ? ' color-error' : '' }}" style="text-align: end; overflow-wrap: anywhere;" @if (($channel['last_error'] ?? null) !== null) role="alert" @endif>{{ $channel['last_error'] ?? __('larena-env::admin.alerts.no_error') }}</dd></div>
                </dl>
                @if ($canTest)
                    <div class="flex flex-wrap items-cross-center g-2 p-top-2 m-top-1 border-top-1 border-top-solid border-outline-variant" aria-labelledby="env-alerts-test">
                        <p class="grow m-0 color-on-surface-variant" style="flex-basis: 14rem;"><strong id="env-alerts-test" class="color-on-surface">{{ __('larena-env::admin.alerts.test') }}.</strong> {{ __('larena-env::admin.alerts.test_explain') }}</p>
                        <form method="post" action="{{ route('larena.env.admin.alerts.test.plan', [], false) }}" class="m-0" data-env-alerts-test>
                            @csrf
                            <button type="submit" class="larena-button">{{ __('larena-env::admin.alerts.test') }}</button>
                        </form>
                    </div>
                @endif
            @endif
        </section>

        @if ($supported)
            <section class="larena-panel p-2" style="margin: 0;" aria-labelledby="env-alerts-change">
                <h2 id="env-alerts-change" class="m-top-0 text-2">{{ __('larena-env::admin.alerts.change') }}</h2>
                <form method="post" action="{{ route('larena.env.admin.alerts.channel.plan', [], false) }}" class="larena-form" data-env-alerts-form>
                    @csrf
                    <div class="larena-field">
                        <label for="env-alerts-kind">{{ __('larena-env::admin.alerts.kind') }}</label>
                        <select id="env-alerts-kind" name="kind" style="min-height: 44px;">
                            @foreach (['webhook', 'telegram', 'off'] as $kind)
                                <option value="{{ $kind }}" @if (($channel['kind'] ?? 'off') === $kind) selected @endif>{{ __('larena-env::admin.alerts.kinds.'.$kind) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-alerts-target">{{ __('larena-env::admin.alerts.target') }}</label>
                        <input type="text" id="env-alerts-target" name="target" maxlength="500" autocomplete="off" inputmode="url">
                    </div>
                    <div class="larena-field" data-env-alerts-token>
                        <label for="env-alerts-token">{{ __('larena-env::admin.alerts.token') }}</label>
                        <input type="password" id="env-alerts-token" name="token" maxlength="100" autocomplete="off">
                    </div>
                    <p class="color-on-surface-variant text-small">{{ __('larena-env::admin.alerts.token_help') }}</p>
                    <div class="larena-form-actions"><button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.alerts.plan') }}</button></div>
                </form>
            </section>
        @endif
    </div>
@endsection
