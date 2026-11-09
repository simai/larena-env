{{-- One recommended action: its state, what it is, and its one click (a fix planned and confirmed, or its screen). --}}
<li class="flex flex-wrap items-cross-center g-2 p-y-2 border-top-1 border-top-solid border-outline-variant" data-env-action="{{ $item['key'] }}" data-env-action-state="{{ $item['state'] }}">
    <sf-badge appearance="tonal" status="{{ $item['state'] === 'now' ? 'error' : ($item['state'] === 'done' ? 'success' : 'warning') }}" text="{{ __('larena-env::admin.actions.states.'.$item['state']) }}"></sf-badge>
    <div class="grow min-w-0" style="flex-basis: 14rem;">
        <div style="font-weight: 600; overflow-wrap: anywhere;">{{ $item['title'] }}</div>
        @if ($item['line'] !== '')
            <div class="color-on-surface-variant" style="overflow-wrap: anywhere;">{{ $item['line'] }}</div>
        @endif
    </div>
    @if ($item['action'] !== null && $item['action']['kind'] === 'form')
        <form method="post" action="{{ $item['action']['url'] }}" class="m-0" data-env-action-do="form">
            @csrf
            @foreach ($item['action']['fields'] ?? [] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="larena-button {{ $item['state'] === 'now' ? 'larena-button-primary' : '' }} larena-control-floor" aria-label="{{ $item['action']['label'] }}: {{ $item['title'] }}">{{ $item['action']['label'] }}</button>
        </form>
    @elseif ($item['action'] !== null)
        <a class="larena-button {{ $item['state'] === 'now' ? 'larena-button-primary' : '' }} larena-control-floor" href="{{ $item['action']['url'] }}" data-env-action-do="link" aria-label="{{ $item['action']['label'] }}: {{ $item['title'] }}">{{ $item['action']['label'] }}</a>
    @endif
</li>
