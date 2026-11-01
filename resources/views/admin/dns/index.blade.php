@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.dns.title'))
@section('heading', __('larena-env::admin.dns.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.dns.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" data-env-dns-zones="{{ $connected === null ? 'not_connected' : ($zones === null ? 'failed' : count($zones)) }}" aria-labelledby="env-dns-zones">
        <h2 id="env-dns-zones">{{ __('larena-env::admin.dns.zones') }}</h2>
        @if ($connected === null)
            <p data-env-dns-connect>{{ __('larena-env::admin.dns.not_connected') }}</p>
            <p><a href="{{ route('larena.env.admin.dns.connection', [], false) }}">{{ __('larena-env::admin.dns.connect_link') }}</a></p>
        @elseif ($reasonText !== null)
            <p role="alert">{{ $reasonText }}</p>
        @elseif ($zones === [])
            <p>{{ __('larena-env::admin.dns.zones_none') }}</p>
        @else
            <table class="larena-table larena-table-stack">
                <thead><tr><th>{{ __('larena-env::admin.dns.zone') }}</th><th>{{ __('larena-env::admin.dns.zone_status') }}</th><th>{{ __('larena-env::admin.dns.zone_rights') }}</th></tr></thead>
                <tbody>
                @foreach ($zones as $zone)
                    <tr data-env-dns-zone="{{ $zone->name }}">
                        <td data-label="{{ __('larena-env::admin.dns.zone') }}"><a href="{{ route('larena.env.admin.dns.zone', ['zone' => $zone->id], false) }}">{{ $zone->name }}</a></td>
                        <td data-label="{{ __('larena-env::admin.dns.zone_status') }}">{{ $zone->status }}</td>
                        <td data-label="{{ __('larena-env::admin.dns.zone_rights') }}">{{ $zone->editable() ? __('larena-env::admin.dns.zone_editable') : __('larena-env::admin.dns.zone_read_only') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
