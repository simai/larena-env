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
            <dl class="m-0" style="display: block;" data-env-facts>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.dns.provider') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.dns.providers.'.$connected->provider) }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-dns-token-tail><dt class="color-on-surface-variant">{{ __('larena-env::admin.dns.token') }}</dt><dd class="m-0 min-w-0 text-end">•••• {{ $connected->tokenTail }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.dns.token_status') }}</dt><dd class="m-0 min-w-0 text-end">{{ $connected->tokenStatus }}{{ $connected->tokenExpiresOn === null ? '' : ' · '.__('larena-env::admin.dns.token_expires', ['time' => \Larena\Env\Server\Moment::human($connected->tokenExpiresOn)]) }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.dns.verified_at') }}</dt><dd class="m-0 min-w-0 text-end">{{ \Larena\Env\Server\Moment::human($connected->verifiedAt) }}</dd></div>
            </dl>
            @if ($reasonText !== null)
                <p role="alert">{{ $reasonText }}</p>
            @elseif ($zones !== null)
                <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.dns.token_zones') }}</h3>
                @if ($zones === [])
                    <p>{{ __('larena-env::admin.dns.zones_none') }}</p>
                @else
                    <ul class="m-0 p-0" style="list-style: none;" data-env-dns-token-zones>
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
        @if ($certificatesSupported ?? false)
            {{-- Certificates through DNS (simai-env 1.36.0): the server keeps its own copy of this token, root-only. --}}
            <section class="larena-panel larena-panel-padded" id="env-dns-certificates" data-env-dns-certificates="{{ $certificates === null ? 'unknown' : ($certificates->configured ? 'stored' : 'none') }}" aria-labelledby="env-dns-certificates-title">
                <h2 id="env-dns-certificates-title">{{ __('larena-env::admin.dns.certificates.title') }}</h2>
                <p>{{ __('larena-env::admin.dns.certificates.explain') }}</p>
                @if ($certificates === null)
                    <p role="alert">{{ __('larena-env::admin.dns.certificates.unknown') }}</p>
                @elseif ($certificates->configured)
                    <p data-env-dns-certificates-state>{{ $certificates->updatedAt === null ? __('larena-env::admin.dns.certificates.stored') : __('larena-env::admin.dns.certificates.stored_at', ['time' => \Larena\Env\Server\Moment::human($certificates->updatedAt)]) }}</p>
                @else
                    <p data-env-dns-certificates-state>{{ __('larena-env::admin.dns.certificates.none') }}</p>
                @endif
                @if ($certificatesAllowed ?? false)
                    <div class="flex flex-wrap g-1">
                        <form method="post" action="{{ route('larena.env.admin.dns.certificates.plan', [], false) }}" class="m-0" data-env-dns-certificates-op="store">
                            @csrf
                            <input type="hidden" name="op" value="store">
                            <button type="submit" class="larena-button">{{ $certificates?->configured ? __('larena-env::admin.dns.certificates.store_again') : __('larena-env::admin.dns.certificates.store') }}</button>
                        </form>
                        @if ($certificates?->configured)
                            <form method="post" action="{{ route('larena.env.admin.dns.certificates.plan', [], false) }}" class="m-0" data-env-dns-certificates-op="remove">
                                @csrf
                                <input type="hidden" name="op" value="remove">
                                <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.certificates.remove') }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            </section>
        @endif
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
