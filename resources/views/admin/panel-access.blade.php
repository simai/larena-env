@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.panel_access.title'))
@section('heading', __('larena-env::admin.panel_access.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.panel_access.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Where the panel answers (simai-env 1.51.0): by the server's address and port with a self-signed certificate, by a
         domain with Let's Encrypt; the domain attached or taken away, the port moved, the address by IP turned off once
         the domain has its certificate. The port and the address by IP are kept from the new address in 5 minutes. --}}
    @if (($panelAddress ?? null) !== null)
        <section class="larena-panel larena-panel-padded" data-env-panel-address="{{ $panelAddress->domain !== null ? 'domain' : 'ip' }}" aria-labelledby="env-panel-address">
            <h2 id="env-panel-address" class="text-2">{{ __('larena-env::admin.panel_address.title') }}</h2>
            @if ($panelAddress->url !== null)
                <p class="m-0"><strong>{{ __('larena-env::admin.panel_address.use') }}:</strong> <a href="{{ $panelAddress->url }}" data-env-panel-address-url style="overflow-wrap: anywhere;">{{ $panelAddress->url }}</a></p>
            @endif
            <ul class="m-0 m-t-2 p-0" style="list-style: none;">
                <li class="p-y-2 border-top-1 border-top-solid border-outline-variant" data-env-panel-address-ip="{{ $panelAddress->ipAccess ? 'on' : 'off' }}">
                    <strong>{{ __('larena-env::admin.panel_address.by_ip') }}</strong>
                    @if ($panelAddress->ipAccess && $panelAddress->ipUrl !== null)
                        <a href="{{ $panelAddress->ipUrl }}" style="overflow-wrap: anywhere;">{{ $panelAddress->ipUrl }}</a>
                        @if (($panelAddress->ipCertificate['sha256'] ?? null) !== null)
                            <span class="color-on-surface-variant text-0" style="display: block; overflow-wrap: anywhere;">{{ __('larena-env::admin.panel_address.certificate', ['sha' => $panelAddress->ipCertificate['sha256']]) }}</span>
                        @endif
                    @else
                        <span class="color-on-surface-variant">{{ __('larena-env::admin.panel_address.ip_off') }}</span>
                    @endif
                </li>
                <li class="p-y-2 border-top-1 border-top-solid border-outline-variant" data-env-panel-address-domain="{{ $panelAddress->domain ?? '' }}">
                    <strong>{{ __('larena-env::admin.panel_address.by_domain') }}</strong>
                    @if ($panelAddress->domain === null)
                        <span class="color-on-surface-variant">{{ __('larena-env::admin.panel_address.no_domain') }}</span>
                    @else
                        <a href="{{ $panelAddress->domainUrl ?? 'http://'.$panelAddress->domain }}">{{ $panelAddress->domainUrl ?? $panelAddress->domain }}</a>
                        <span class="color-on-surface-variant">{{ __('larena-env::admin.panel_address.'.($panelAddress->domainSsl ? 'ssl_on' : 'ssl_off')) }}</span>
                    @endif
                </li>
            </ul>
            @if ($addressAllows['domain'] && $panelAddress->domain === null)
                <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-3" data-env-panel-address-op="domain">
                    @csrf
                    <input type="hidden" name="op" value="domain">
                    <h3 class="text-1">{{ __('larena-env::admin.panel_address.attach') }}</h3>
                    <p class="color-on-surface-variant">{{ __('larena-env::admin.panel_address.dns_hint', ['ip' => $panelAddress->ip ?? '—']) }}</p>
                    <div class="flex flex-wrap g-2 items-cross-end">
                        <label class="grow" style="flex-basis: 14rem;"><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.panel_address.domain') }}</span>
                            <input type="text" name="domain" required class="larena-input larena-control-floor" style="inline-size: 100%;" placeholder="panel.example.com" autocomplete="off"></label>
                        <label class="grow" style="flex-basis: 14rem;"><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.panel_address.email') }}</span>
                            <input type="email" name="email" class="larena-input larena-control-floor" style="inline-size: 100%;" autocomplete="email"></label>
                        <button type="submit" class="larena-button larena-button-primary larena-control-floor">{{ __('larena-env::admin.panel_address.attach_go') }}</button>
                    </div>
                </form>
            @elseif ($addressAllows['domain-remove'] && $panelAddress->domain !== null)
                <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-3" data-env-panel-address-op="domain-remove">
                    @csrf
                    <input type="hidden" name="op" value="domain-remove">
                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_address.operations.domain-remove') }}</button>
                </form>
            @endif
            @if ($addressAllows['port'] && $panelAddress->ipAccess)
                <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-3 flex flex-wrap g-2 items-cross-end" data-env-panel-address-op="port">
                    @csrf
                    <input type="hidden" name="op" value="port">
                    <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.panel_address.port') }}</span>
                        <input type="number" name="port" min="100" max="65535" required class="larena-input larena-control-floor" style="inline-size: 9rem;" value="{{ $panelAddress->port ?? 8500 }}"></label>
                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_address.port_go') }}</button>
                </form>
            @endif
            @if ($addressAllows['ip-access'] && ($panelAddress->ipAccess ? $panelAddress->domainSsl : true))
                <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-3" data-env-panel-address-op="ip-access">
                    @csrf
                    <input type="hidden" name="op" value="ip-access">
                    <input type="hidden" name="enabled" value="{{ $panelAddress->ipAccess ? 'no' : 'yes' }}">
                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_address.'.($panelAddress->ipAccess ? 'ip_turn_off' : 'ip_turn_on')) }}</button>
                </form>
            @endif
        </section>
    @endif

    {{-- Who reaches the panel (Panel plan 5.3), the system level's: kept only when confirmed in time, from the panel itself. --}}
    <section class="larena-panel larena-panel-padded" data-env-panel-access="{{ !$supported ? 'unsupported' : ($status?->panelAccess === null ? 'unknown' : (is_string($status->panelAccess) ? 'any' : 'list')) }}" aria-labelledby="env-panel-access">
        <h2 id="env-panel-access" class="text-2">{{ __('larena-env::admin.panel_access.now') }}</h2>
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
                <ul class="m-0 p-0" style="list-style: none;" data-env-panel-access-list>
                    @foreach ($status->panelAccess as $item)
                        <li class="p-y-1 border-top-1 border-top-solid border-outline-variant" style="overflow-wrap: anywhere;">{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
            <p data-env-panel-access-you="{{ $address }}">{{ __('larena-env::admin.panel_access.you', ['address' => $address]) }}</p>
            @if (!$addressListed)
                <p role="alert" data-env-panel-access-outside>{{ __('larena-env::admin.panel_access.you_outside') }}</p>
            @endif
        @endif
    </section>

    @if ($supported && $status !== null && $status->panelAccess !== null && $allows['access'])
        <section class="larena-panel larena-panel-padded" aria-labelledby="env-panel-access-change">
            <h2 id="env-panel-access-change" class="text-2">{{ __('larena-env::admin.panel_access.change') }}</h2>
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
                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_access.open_to_all') }}</button>
                </form>
            @endif
            <p>{{ __('larena-env::admin.timed.after_plan') }}</p>
        </section>
    @endif
@endsection
