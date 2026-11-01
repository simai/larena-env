@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.dns.connection_title'))
@section('heading', __('larena-env::admin.dns.connection_heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.dns.connection_description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" data-env-dns-connection="{{ $connected === null ? 'none' : 'connected' }}" aria-labelledby="env-dns-connection">
        <h2 id="env-dns-connection">{{ __('larena-env::admin.dns.provider') }}</h2>
        @if ($connected === null)
            <p data-env-dns-connect>{{ __('larena-env::admin.dns.not_connected') }}</p>
            <p>{{ __('larena-env::admin.dns.token_help') }}</p>
        @else
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-env::admin.dns.provider') }}</dt><dd>{{ __('larena-env::admin.dns.providers.'.$connected->provider) }}</dd></div>
                <div data-env-dns-token-tail><dt>{{ __('larena-env::admin.dns.token') }}</dt><dd>•••• {{ $connected->tokenTail }}</dd></div>
                <div><dt>{{ __('larena-env::admin.dns.token_status') }}</dt><dd>{{ $connected->tokenStatus }}{{ $connected->tokenExpiresOn === null ? '' : ' · '.__('larena-env::admin.dns.token_expires', ['time' => \Larena\Env\Server\Moment::human($connected->tokenExpiresOn)]) }}</dd></div>
                <div><dt>{{ __('larena-env::admin.dns.verified_at') }}</dt><dd>{{ \Larena\Env\Server\Moment::human($connected->verifiedAt) }}</dd></div>
            </dl>
            @if ($reasonText !== null)
                <p role="alert">{{ $reasonText }}</p>
            @elseif ($zones !== null)
                <h3>{{ __('larena-env::admin.dns.token_zones') }}</h3>
                @if ($zones === [])
                    <p>{{ __('larena-env::admin.dns.zones_none') }}</p>
                @else
                    <ul class="larena-list" data-env-dns-token-zones>
                        @foreach ($zones as $zone)
                            <li>{{ $zone->name }} · {{ $zone->editable() ? __('larena-env::admin.dns.zone_editable') : __('larena-env::admin.dns.zone_read_only') }}@if ($zone->permissions !== []) · {{ implode(', ', $zone->permissions) }}@endif</li>
                        @endforeach
                    </ul>
                @endif
            @endif
        @endif
        <form method="post" action="{{ route('larena.env.admin.dns.connection.save', [], false) }}" class="larena-form" data-env-dns-token-form>
            @csrf
            <div class="larena-field">
                <label for="env-dns-token">{{ $connected === null ? __('larena-env::admin.dns.token_new') : __('larena-env::admin.dns.token_replace') }}</label>
                <input type="password" id="env-dns-token" name="token" autocomplete="off" required maxlength="200">
            </div>
            <div class="larena-form-actions">
                <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.connect') }}</button>
            </div>
        </form>
    </section>

    @if ($connected !== null)
        <section class="larena-panel larena-panel-padded" data-env-dns-addresses aria-labelledby="env-dns-addresses">
            <h2 id="env-dns-addresses">{{ __('larena-env::admin.dns.addresses') }}</h2>
            <p>{{ __('larena-env::admin.dns.addresses_help') }}</p>
            <form method="post" action="{{ route('larena.env.admin.dns.connection.addresses', [], false) }}" class="larena-form">
                @csrf
                <div class="larena-field">
                    <label for="env-dns-ipv4">{{ __('larena-env::admin.dns.ipv4') }}</label>
                    <input type="text" id="env-dns-ipv4" name="ipv4" value="{{ $connected->serverIpv4 }}" maxlength="15" inputmode="decimal">
                </div>
                <div class="larena-field">
                    <label for="env-dns-ipv6">{{ __('larena-env::admin.dns.ipv6') }}</label>
                    <input type="text" id="env-dns-ipv6" name="ipv6" value="{{ $connected->serverIpv6 }}" maxlength="45">
                </div>
                <div class="larena-form-actions">
                    <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.addresses_save') }}</button>
                </div>
            </form>
        </section>
        <section class="larena-panel larena-panel-padded" aria-labelledby="env-dns-disconnect">
            <h2 id="env-dns-disconnect">{{ __('larena-env::admin.dns.disconnect') }}</h2>
            <p>{{ __('larena-env::admin.dns.disconnect_explain') }}</p>
            <form method="post" action="{{ route('larena.env.admin.dns.connection.disconnect', [], false) }}" data-env-dns-disconnect>
                @csrf
                <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.disconnect') }}</button>
            </form>
        </section>
    @endif
@endsection
