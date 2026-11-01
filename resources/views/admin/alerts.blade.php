@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.alerts.title'))
@section('heading', __('larena-env::admin.alerts.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.alerts.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Where the server sends its alerts (simai-env 1.31.0), the system level's. The bot token is never shown. --}}
    <section class="larena-panel larena-panel-padded" data-env-alerts-channel="{{ $supported ? ($channel['kind'] ?? 'off') : 'unsupported' }}" aria-labelledby="env-alerts-now">
        <h2 id="env-alerts-now">{{ __('larena-env::admin.alerts.now') }}</h2>
        @if (!$supported)
            <p data-env-alerts-unsupported>{{ __('larena-env::admin.alerts.unsupported') }}</p>
        @else
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-env::admin.alerts.kind') }}</dt><dd>{{ __('larena-env::admin.alerts.kinds.'.($channel['kind'] ?? 'off')) }}</dd></div>
                @if (($channel['target_hint'] ?? null) !== null)
                    <div><dt>{{ __('larena-env::admin.alerts.target_hint') }}</dt><dd style="overflow-wrap: anywhere;">{{ $channel['target_hint'] }}</dd></div>
                @endif
                <div data-env-alerts-last-sent><dt>{{ __('larena-env::admin.alerts.last_sent') }}</dt><dd>{{ ($channel['last_sent_at'] ?? null) === null ? __('larena-env::admin.alerts.never') : \Larena\Env\Server\Moment::human($channel['last_sent_at']) }}</dd></div>
                <div data-env-alerts-last-error><dt>{{ __('larena-env::admin.alerts.last_error') }}</dt><dd style="overflow-wrap: anywhere;" @if (($channel['last_error'] ?? null) !== null) role="alert" @endif>{{ $channel['last_error'] ?? __('larena-env::admin.alerts.no_error') }}</dd></div>
            </dl>
        @endif
    </section>

    @if ($supported)
        <section class="larena-panel larena-panel-padded" aria-labelledby="env-alerts-change">
            <h2 id="env-alerts-change">{{ __('larena-env::admin.alerts.change') }}</h2>
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
                <p>{{ __('larena-env::admin.alerts.token_help') }}</p>
                <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.alerts.plan') }}</button></div>
            </form>
        </section>
        @if ($canTest)
            <section class="larena-panel larena-panel-padded" aria-labelledby="env-alerts-test">
                <h2 id="env-alerts-test">{{ __('larena-env::admin.alerts.test') }}</h2>
                <p>{{ __('larena-env::admin.alerts.test_explain') }}</p>
                <form method="post" action="{{ route('larena.env.admin.alerts.test.plan', [], false) }}" data-env-alerts-test>
                    @csrf
                    <button type="submit" class="larena-button">{{ __('larena-env::admin.alerts.test') }}</button>
                </form>
            </section>
        @endif
    @endif
@endsection
