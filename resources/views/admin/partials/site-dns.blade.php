{{-- The domain's DNS records through the panel's DNS provider, inside «Домены и HTTPS» (owner decision 2026-11-05). --}}
    <section class="larena-panel larena-panel-padded" data-env-dns="{{ $siteDns?->state ?? 'not_connected' }}" aria-labelledby="env-site-dns">
        <h2 id="env-site-dns" class="text-2">{{ __('larena-env::admin.dns.site_title') }}</h2>
        @if ($siteDns === null || $siteDns->state === 'not_connected')
            <p data-env-dns-connect>{{ __('larena-env::admin.dns.site_not_connected') }}</p>
            @if ($wholeServer ?? false)
                <p><a href="{{ route('larena.env.admin.dns.connection', [], false) }}">{{ __('larena-env::admin.dns.connect_link') }}</a></p>
            @endif
        @elseif ($siteDns->state === 'zone_missing')
            <p data-env-dns-zone-missing>{{ __('larena-env::admin.dns.site_zone_missing', ['domain' => $domain]) }}</p>
        @elseif ($siteDns->state === 'failed')
            <p role="alert">{{ __('larena-env::admin.dns.reasons.'.($siteDns->reason ?? 'unknown'), ['reason' => (string) $siteDns->reason]) }}</p>
        @else
            <p data-env-dns-points="{{ $siteDns->points }}" @if ($siteDns->points === 'elsewhere') role="alert" @endif>{{ __('larena-env::admin.dns.points.'.$siteDns->points, ['domain' => $domain]) }}</p>
            <p>{{ __('larena-env::admin.dns.site_zone', ['zone' => $siteDns->zone->name]) }}</p>
            @if ($siteDns->records === [])
                <p data-env-dns-empty>{{ __('larena-env::admin.dns.site_records_none') }}</p>
            @else
                <table class="larena-table larena-table-stack" data-env-dns-records>
                    <thead><tr><th>{{ __('larena-env::admin.dns.type') }}</th><th>{{ __('larena-env::admin.dns.name') }}</th><th>{{ __('larena-env::admin.dns.content') }}</th><th>{{ __('larena-env::admin.dns.ttl') }}</th></tr></thead>
                    <tbody>
                    @foreach ($siteDns->records as $record)
                        <tr data-env-dns-record="{{ $record->type }}"><td data-label="{{ __('larena-env::admin.dns.type') }}">{{ $record->type }}</td><td data-label="{{ __('larena-env::admin.dns.name') }}">{{ $record->name }}</td><td data-label="{{ __('larena-env::admin.dns.content') }}" style="overflow-wrap: anywhere;">{{ $record->content }}@if ($record->proxied) · {{ __('larena-env::admin.dns.proxied') }}@endif</td><td data-label="{{ __('larena-env::admin.dns.ttl') }}">{{ $record->ttl === 1 ? __('larena-env::admin.dns.ttl_auto') : $record->ttl }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if ($siteDns->points !== 'here' && $siteDns->zone->editable())
                <form method="post" action="{{ route('larena.env.admin.site.dns.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-dns-point>
                    @csrf
                    <div class="m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.dns.point_explain', ['domain' => $domain])])</div>
                    <div class="larena-form-actions">
                        <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.point') }}</button>
                    </div>
                </form>
            @endif
            @if ($wholeServer ?? false)
                <p><a href="{{ route('larena.env.admin.dns.zone', ['zone' => $siteDns->zone->id], false) }}">{{ __('larena-env::admin.dns.zone_link', ['zone' => $siteDns->zone->name]) }}</a></p>
            @endif
        @endif
    </section>
