@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.dns.zone_title', ['zone' => $zone->name]))
@section('heading', $zone->name)
@section('eyebrow', __('larena-env::admin.dns.heading'))
@section('description', __('larena-env::admin.dns.zone_description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($last !== null)
        <section class="larena-panel larena-panel-padded" data-env-dns-last aria-labelledby="env-dns-last">
            <h2 id="env-dns-last">{{ __('larena-env::admin.dns.last_change') }}</h2>
            <p>{{ __('larena-env::admin.dns.last_change_by', ['time' => \Larena\Env\Server\Moment::human($last['at']), 'count' => (string) count($last['changes'])]) }}</p>
            <ul class="larena-list">
                @foreach ($last['changes'] as $done)
                    <li>{{ __('larena-env::admin.dns.actions.'.$done['action']) }}: {{ ($done['after'] ?? $done['before'])?->line() }}</li>
                @endforeach
            </ul>
            @if ($zone->editable())
                <form method="post" action="{{ route('larena.env.admin.dns.zone.undo', ['zone' => $zone->id], false) }}" data-env-dns-undo>
                    @csrf
                    <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.undo') }}</button>
                </form>
            @endif
        </section>
    @endif

    <section class="larena-panel larena-panel-padded" data-env-dns-records="{{ count($records) }}" aria-labelledby="env-dns-records">
        <h2 id="env-dns-records">{{ __('larena-env::admin.dns.records') }}</h2>
        @if (!$zone->editable())
            <p role="note">{{ __('larena-env::admin.dns.zone_read_only_explain') }}</p>
        @endif
        @if ($records === [])
            <p>{{ __('larena-env::admin.dns.records_none') }}</p>
        @else
            <table class="larena-table larena-table-stack">
                <thead><tr><th>{{ __('larena-env::admin.dns.type') }}</th><th>{{ __('larena-env::admin.dns.name') }}</th><th>{{ __('larena-env::admin.dns.content') }}</th><th>{{ __('larena-env::admin.dns.ttl') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($records as $record)
                    <tr data-env-dns-record="{{ $record->id }}">
                        <td data-label="{{ __('larena-env::admin.dns.type') }}">{{ $record->type }}</td>
                        <td data-label="{{ __('larena-env::admin.dns.name') }}" style="overflow-wrap: anywhere;">{{ $record->name }}</td>
                        <td data-label="{{ __('larena-env::admin.dns.content') }}" style="overflow-wrap: anywhere;">{{ $record->priority !== null ? $record->priority.' ' : '' }}{{ $record->content }}@if ($record->proxied) · {{ __('larena-env::admin.dns.proxied') }}@endif</td>
                        <td data-label="{{ __('larena-env::admin.dns.ttl') }}">{{ $record->ttl === 1 ? __('larena-env::admin.dns.ttl_auto') : $record->ttl }}</td>
                        <td>
                            @if ($zone->editable())
                                <a href="?edit={{ $record->id }}#env-dns-edit">{{ __('larena-env::admin.dns.change') }}</a>
                                <form method="post" action="{{ route('larena.env.admin.dns.zone.plan', ['zone' => $zone->id], false) }}" data-env-dns-delete="{{ $record->id }}">
                                    @csrf
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="record" value="{{ $record->id }}">
                                    <button type="submit" class="larena-button">{{ __('larena-env::admin.dns.delete') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($zone->editable())
        @foreach ($records as $record)
            @continue($record->id !== $edit)
            <section class="larena-panel larena-panel-padded" id="env-dns-edit" aria-labelledby="env-dns-edit-heading">
                <h2 id="env-dns-edit-heading">{{ __('larena-env::admin.dns.change_record', ['record' => $record->line()]) }}</h2>
                @include('larena-env::admin.dns.record-form', ['action' => 'update', 'record' => $record])
            </section>
        @endforeach
        <section class="larena-panel larena-panel-padded" aria-labelledby="env-dns-add">
            <h2 id="env-dns-add">{{ __('larena-env::admin.dns.add') }}</h2>
            @include('larena-env::admin.dns.record-form', ['action' => 'create', 'record' => null])
        </section>
    @endif
    <p><a href="{{ route('larena.env.admin.dns', [], false) }}">{{ __('larena-env::admin.dns.back') }}</a></p>
@endsection
