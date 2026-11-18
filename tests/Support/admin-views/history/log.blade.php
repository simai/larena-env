{{-- A stand-in for larena/admin's log viewer (larena-admin::history.log) in the panel's own tests: the same rows. --}}
<ul data-larena-log>
    @foreach ($rows as $row)
        <li data-larena-log-row="{{ $row['badge']['status'] ?? 'text' }}">@if (($row['at'] ?? '') !== '')<sf-time datetime="{{ $row['at'] }}"></sf-time>@else{{ $row['time'] ?? '' }}@endif
            @if (is_array($row['badge'] ?? null))<sf-badge status="{{ $row['badge']['status'] }}" text="{{ $row['badge']['text'] }}"></sf-badge>@endif
            <span data-larena-log-title>{{ $row['title'] }}</span> <span>{{ $row['note'] ?? '' }}</span>
            <details data-larena-log-raw><code>{{ $row['raw'] }}</code></details></li>
    @endforeach
</ul>
