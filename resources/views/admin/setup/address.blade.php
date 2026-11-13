{{-- The panel's address: what it is now, and a domain with Let's Encrypt attached right here. --}}
@if ($address === null)
    <p class="m-0" data-env-setup-unsupported>{{ __('larena-env::admin.setup.unsupported') }}</p>
@else
    <p class="m-0" data-env-setup-now>{{ __('larena-env::admin.panel_address.use') }}
        <b style="overflow-wrap: anywhere;">{{ $address->url ?? $address->domainUrl ?? $address->ipUrl ?? '—' }}</b></p>
    @if ($phase === 'form')
        <p class="m-0" data-env-domain-point>{{ $address->ip !== null ? __('larena-env::admin.point_domain.ip', ['ip' => $address->ip]) : __('larena-env::admin.point_domain.unknown') }}</p>
        <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.panel_address.dns_hint') }}</p>
        <sf-input name="domain" label="{{ __('larena-env::admin.panel_address.domain') }}" placeholder="panel.example.com" default-value="{{ $address->domain ?? '' }}" autocomplete="off" required></sf-input>
        <sf-input name="email" label="{{ __('larena-env::admin.panel_address.email') }}" input-type="email" autocomplete="email" hint="{{ __('larena-env::admin.setup.address.email_hint') }}"></sf-input>
    @elseif ($state === 'done')
        <div><sf-button appearance="link" text="{{ __('larena-env::admin.setup.edit') }}" href="{{ route('larena.env.admin.overview.setup', ['step' => 'address', 'change' => '1'], false) }}"></sf-button></div>
    @endif
@endif
