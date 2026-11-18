@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.tab_title', ['title' => __('larena-env::admin.panel_access.title')]))
@section('heading', __('larena-env::admin.panel_access.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.panel_access.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Two cards «значение · Изменить» (study part 8, PX-82; owner decision dec-a3a50baf4b3d): the panel's address and who
         may open it, each the Framework's description list (PanelAccessCards) with «Изменить» that opens the change in
         place (?edit=address, ?edit=access); a change is planned, confirmed and then acts at once — who may open the panel
         is undone by the server unless kept within 2 minutes, a new address unless confirmed on it within 5 (PX-90). --}}
    @if (($panelAddress ?? null) !== null)
        <section class="larena-panel p-2" style="margin: 0 0 1rem;" id="env-panel-address" data-env-panel-address="{{ $panelAddress->domain !== null ? 'domain' : 'ip' }}" aria-label="{{ __('larena-env::admin.panel_access.cards.address') }}">
            @if ($panelAddress->url !== null)
                <a href="{{ $panelAddress->url }}" class="sr-only" data-env-panel-address-url>{{ $panelAddress->url }}</a>
            @endif
            @include('larena-env::admin.partials.facts', ['items' => \Larena\Env\Admin\PanelAccessCards::address($panelAddress, in_array(true, $addressAllows, true), ($show ?? null) === 'fingerprint'),
                'label' => __('larena-env::admin.panel_access.cards.address'), 'mark' => 'panel-address'])
            @if (($edit ?? null) === 'address')
                <div class="p-x-1 p-top-1" data-env-panel-address-edit>
                    @if ($addressAllows['domain'] && $panelAddress->domain === null)
                        <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-2" data-env-panel-address-op="domain">
                            @csrf
                            <input type="hidden" name="op" value="domain">
                            <h3 class="text-1 m-0">{{ __('larena-env::admin.panel_address.attach') }}</h3>
                            <p data-env-domain-point>{{ $panelAddress->ip !== null ? __('larena-env::admin.point_domain.ip', ['ip' => $panelAddress->ip]) : __('larena-env::admin.point_domain.unknown') }}</p>
                            <p class="color-on-surface-variant">{{ __('larena-env::admin.panel_address.dns_hint') }}</p>
                            <div class="flex flex-wrap g-2 items-cross-end">
                                <label class="grow" style="flex-basis: 14rem;"><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.panel_address.domain') }}</span>
                                    <input type="text" name="domain" required class="larena-input larena-control-floor" style="inline-size: 100%;" placeholder="panel.example.com" autocomplete="off"></label>
                                <label class="grow" style="flex-basis: 14rem;"><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.panel_address.email') }}</span>
                                    <input type="email" name="email" class="larena-input larena-control-floor" style="inline-size: 100%;" autocomplete="email"></label>
                                <button type="submit" class="larena-button larena-button-primary larena-control-floor">{{ __('larena-env::admin.panel_address.attach_go') }}</button>
                            </div>
                        </form>
                    @elseif ($addressAllows['domain-remove'] && $panelAddress->domain !== null)
                        <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-2" data-env-panel-address-op="domain-remove">
                            @csrf
                            <input type="hidden" name="op" value="domain-remove">
                            <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_address.operations.domain-remove') }}</button>
                        </form>
                    @endif
                    @if ($addressAllows['port'] && $panelAddress->ipAccess)
                        <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-2 flex flex-wrap g-2 items-cross-end" data-env-panel-address-op="port">
                            @csrf
                            <input type="hidden" name="op" value="port">
                            <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.panel_address.port') }}</span>
                                <input type="number" name="port" min="100" max="65535" required class="larena-input larena-control-floor" style="inline-size: 9rem;" value="{{ $panelAddress->port ?? 8500 }}"></label>
                            <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_address.port_go') }}</button>
                        </form>
                    @endif
                    @if ($addressAllows['ip-access'] && ($panelAddress->ipAccess ? $panelAddress->domainSsl : true))
                        <form method="post" action="{{ route('larena.env.admin.panel.address.plan', [], false) }}" class="m-0 m-t-2" data-env-panel-address-op="ip-access">
                            @csrf
                            <input type="hidden" name="op" value="ip-access">
                            <input type="hidden" name="enabled" value="{{ $panelAddress->ipAccess ? 'no' : 'yes' }}">
                            <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_address.'.($panelAddress->ipAccess ? 'ip_turn_off' : 'ip_turn_on')) }}</button>
                        </form>
                    @endif
                    <p class="m-0 m-t-2 text-small color-on-surface-variant" data-env-settings-when="address">{{ __('larena-env::admin.panel_access.cards.when_address') }}</p>
                    <p class="m-0 m-t-1"><a href="{{ route('larena.env.admin.panel.access', [], false) }}" class="larena-button larena-control-floor" data-env-edit-cancel>{{ __('larena-env::admin.panel_access.cards.cancel') }}</a></p>
                </div>
            @endif
        </section>
    @endif

    {{-- Who reaches the panel (Panel plan 5.3), the system level's: kept only when confirmed in time, from the panel itself. --}}
    <section class="larena-panel p-2" style="margin: 0;" id="env-panel-access" data-env-panel-access="{{ !$supported ? 'unsupported' : ($status?->panelAccess === null ? 'unknown' : (is_string($status->panelAccess) ? 'any' : 'list')) }}" aria-label="{{ __('larena-env::admin.panel_access.cards.who') }}">
        @if (!$supported)
            <p class="m-0 p-1" data-env-panel-access-unsupported>{{ __('larena-env::admin.panel_access.unsupported') }}</p>
        @elseif ($status === null)
            <p class="m-0 p-1" role="alert">{{ __('larena-env::admin.firewall.unreadable') }}</p>
        @else
            @include('larena-env::admin.partials.timed-change', ['change' => $pending, 'canKeep' => $allows['keep']])
            @include('larena-env::admin.partials.facts', ['items' => \Larena\Env\Admin\PanelAccessCards::access($status->panelAccess, $address, $addressListed, $status->panelAccess !== null && $allows['access']),
                'label' => __('larena-env::admin.panel_access.cards.who'), 'mark' => 'panel-access'])
            @if (is_array($status->panelAccess))
                <span hidden data-env-panel-access-list>{{ implode(' ', $status->panelAccess) }}</span>
            @else
                <span hidden data-env-panel-access-any></span>
            @endif
            <span hidden data-env-panel-access-you="{{ $address }}"></span>
            @if (!$addressListed)
                <div class="p-x-1 p-top-1" role="alert" data-env-panel-access-outside>
                    <p class="m-0">{{ __('larena-env::admin.panel_access.you_outside') }}</p>
                </div>
            @endif
            @if ($status->panelAccess !== null && $allows['access'] && ((($edit ?? null) === 'access') || !$addressListed))
                <div class="p-x-1 p-top-1" data-env-panel-access-edit>
                    @if (($edit ?? null) === 'access')
                        <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" class="larena-form" data-env-panel-access-form>
                            @csrf
                            <input type="hidden" name="op" value="access">
                            <div class="larena-field">
                                <label for="env-panel-allow">{{ __('larena-env::admin.panel_access.allow') }}</label>
                                <textarea id="env-panel-allow" name="allow" rows="4" spellcheck="false" autocomplete="off" style="width: 100%; min-block-size: 0; block-size: 7rem;" placeholder="{{ $address }}">{{ is_array($status->panelAccess) ? implode("\n", $status->panelAccess) : $address }}</textarea>
                                <p>{{ __('larena-env::admin.panel_access.allow_hint') }}</p>
                            </div>
                            <p class="m-0 text-small color-on-surface-variant" data-env-settings-when="access">{{ __('larena-env::admin.panel_access.cards.when_access') }}</p>
                            <div class="flex flex-wrap items-cross-center g-1 m-t-2">
                                <button type="submit" class="larena-button larena-button-primary larena-control-floor">{{ __('larena-env::admin.operations.plan') }}</button>
                                <a href="{{ route('larena.env.admin.panel.access', [], false) }}" class="larena-button larena-control-floor" data-env-edit-cancel>{{ __('larena-env::admin.panel_access.cards.cancel') }}</a>
                            </div>
                        </form>
                    @endif
                    @if ($status->panelAccess !== 'any')
                        {{-- Opening the panel to everyone again stays at hand while your own address is outside the list. --}}
                        <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" class="m-0 m-t-1" data-env-panel-access-everyone>
                            @csrf
                            <input type="hidden" name="op" value="access">
                            <input type="hidden" name="everyone" value="yes">
                            <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.panel_access.open_to_all') }}</button>
                        </form>
                    @endif
                </div>
            @elseif ($allows['access'])
                <p class="m-0 p-x-1 p-top-1 text-small color-on-surface-variant" data-env-settings-when="access">{{ __('larena-env::admin.panel_access.cards.when_access') }}</p>
            @endif
        @endif
    </section>
@endsection
