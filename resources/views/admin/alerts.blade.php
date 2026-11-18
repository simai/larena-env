@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.tab_title', ['title' => __('larena-env::admin.alerts.title')]))
@section('heading', __('larena-env::admin.alerts.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.alerts.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Where the server sends its alerts (simai-env 1.31.0), the system level's, as one card «значение · Изменить» (study
         part 8, PX-82): where they go, whether the last one went, «Изменить» opening the change in place (?edit=channel),
         planned and confirmed, acting at once (PX-90). The bot token is never shown. The test beside it. --}}
    <section class="larena-panel p-2" style="margin: 0;" id="env-alerts-now" data-env-alerts-channel="{{ $supported ? ($channel['kind'] ?? 'off') : 'unsupported' }}" aria-label="{{ __('larena-env::admin.alerts.now') }}">
        @if (!$supported)
            <p class="m-0 p-1 color-on-surface-variant" data-env-alerts-unsupported>{{ __('larena-env::admin.alerts.unsupported') }}</p>
        @else
            @include('larena-env::admin.partials.facts', ['items' => \Larena\Env\Admin\AlertsCard::items($channel, true), 'label' => __('larena-env::admin.alerts.now'), 'mark' => 'alerts'])
            @if (($edit ?? null) === 'channel')
                <form method="post" action="{{ route('larena.env.admin.alerts.channel.plan', [], false) }}" class="larena-form p-x-1 p-top-1" data-env-alerts-form>
                    @csrf
                    <div class="larena-field">
                        <label for="env-alerts-kind">{{ __('larena-env::admin.alerts.kind') }}</label>
                        <select id="env-alerts-kind" name="kind" style="min-height: 44px;">
                            @foreach (['webhook', 'telegram', 'off'] as $kind)
                                <option value="{{ $kind }}" @if (($channel['kind'] ?? 'off') === $kind) selected @endif>{{ __('larena-env::admin.alerts.kinds.'.$kind) }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- What the server sends (PX-43): only urgent by default (owner decision dec-45381ada2c3d). --}}
                    @if ($canChooseLevels ?? false)
                        <div class="larena-field">
                            <label for="env-alerts-levels">{{ __('larena-env::admin.alerts.levels') }}</label>
                            <select id="env-alerts-levels" name="levels" style="min-height: 44px;" data-env-alerts-levels>
                                @foreach (['urgent', 'all'] as $level)
                                    <option value="{{ $level }}" @if (($channel['levels'] ?? 'urgent') === $level) selected @endif>{{ __('larena-env::admin.alerts.level_options.'.$level) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <p class="m-0 text-small color-on-surface-variant" data-env-alerts-levels-later>{{ __('larena-env::admin.alerts.levels_later') }}</p>
                    @endif
                    <div class="larena-field">
                        <label for="env-alerts-target">{{ __('larena-env::admin.alerts.target') }}</label>
                        <input type="text" id="env-alerts-target" name="target" maxlength="500" autocomplete="off" inputmode="url"@if (($canChooseLevels ?? false) && in_array($channel['kind'] ?? 'off', ['webhook', 'telegram'], true)) aria-describedby="env-alerts-target-keep"@endif>
                        {{-- Only «Что слать» changed: the same channel without an address keeps the server's (simai-env 1.63.0). --}}
                        @if (($canChooseLevels ?? false) && in_array($channel['kind'] ?? 'off', ['webhook', 'telegram'], true))
                            <p id="env-alerts-target-keep" class="m-0 text-small color-on-surface-variant" data-env-alerts-target-keep>{{ __('larena-env::admin.alerts.target_keep') }}</p>
                        @endif
                    </div>
                    <div class="larena-field" data-env-alerts-token>
                        <label for="env-alerts-token">{{ __('larena-env::admin.alerts.token') }}</label>
                        <input type="password" id="env-alerts-token" name="token" maxlength="100" autocomplete="off">
                    </div>
                    <div class="color-on-surface-variant text-small m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.alerts.token_help')])</div>
                    <p class="m-0 text-small color-on-surface-variant" data-env-settings-when="alerts">{{ __('larena-env::admin.alerts.card.when') }}</p>
                    <div class="flex flex-wrap items-cross-center g-1 m-t-2">
                        <button type="submit" class="larena-button larena-button-primary larena-control-floor">{{ __('larena-env::admin.alerts.plan') }}</button>
                        <a href="{{ route('larena.env.admin.alerts.channel', [], false) }}" class="larena-button larena-control-floor" data-env-edit-cancel>{{ __('larena-env::admin.alerts.card.cancel') }}</a>
                    </div>
                </form>
            @else
                <p class="m-0 p-x-1 p-top-1 text-small color-on-surface-variant" data-env-settings-when="alerts">{{ __('larena-env::admin.alerts.card.when') }}</p>
            @endif
            @if ($canTest)
                <div class="flex flex-wrap items-cross-center g-2 p-x-1 p-top-2 m-top-1 border-top-1 border-top-solid border-outline-variant">
                    <p class="grow m-0 color-on-surface-variant" style="flex-basis: 14rem;"><strong class="color-on-surface">{{ __('larena-env::admin.alerts.test') }}.</strong> {{ __('larena-env::admin.alerts.test_explain') }}</p>
                    <form method="post" action="{{ route('larena.env.admin.alerts.test.plan', [], false) }}" class="m-0" data-env-alerts-test>
                        @csrf
                        <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.alerts.test') }}</button>
                    </form>
                </div>
            @endif
        @endif
    </section>
@endsection
