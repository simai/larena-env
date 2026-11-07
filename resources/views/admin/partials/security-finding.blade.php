{{-- One finding of the security page (SecurityFindings): the same check on several sites is one line with each site
     under it and its own button, so the page counts what the overview counts (critics of 0.4.1). --}}
<li id="env-security-check-{{ $finding['key'] }}" class="flex flex-wrap items-cross-center g-2 p-y-2 border-top-1 border-top-solid border-outline-variant"
    data-env-security-finding="{{ $finding['key'] }}" data-env-security-status="{{ $finding['state'] === 'now' ? 'risk' : 'warn' }}"
    @if (count($finding['checks']) === 1) data-env-security-check="{{ $finding['checks'][0]->id }}" @endif>
    <sf-badge appearance="tonal" status="{{ $finding['state'] === 'now' ? 'error' : 'warning' }}" text="{{ __('larena-env::admin.security.statuses.'.($finding['state'] === 'now' ? 'risk' : 'warn')) }}"></sf-badge>
    <div class="grow min-w-0" style="flex-basis: 16rem;">
        <div style="font-weight: 600; overflow-wrap: anywhere;">
            {{ $words[$finding['checks'][0]->id]['title'] }}@if (count($finding['sites']) === 1) · {{ $finding['sites'][0] }}@elseif (count($finding['sites']) > 1) · {{ __('larena-env::admin.actions.sites', ['count' => (string) count($finding['sites'])]) }}@endif
        </div>
        <div class="color-on-surface-variant" style="overflow-wrap: anywhere;">
            {{ $words[$finding['checks'][0]->id]['line'] }}
        </div>
        @if (array_filter($finding['checks'], static fn ($check): bool => !($fixes[$check->id] ?? false)) !== [])
            <details data-env-security-how>
                <summary style="cursor: pointer;">{{ __('larena-env::admin.security.how') }}</summary>
                <p class="m-0 p-y-1">{{ $words[$finding['checks'][0]->id]['how'] }}</p>
            </details>
        @endif
        @if (count($finding['checks']) > 1)
            <ul class="m-0 m-t-1 p-0" style="list-style: none;" data-env-security-sites>
                @foreach ($finding['checks'] as $check)
                    <li class="flex flex-wrap items-cross-center content-main-between g-2 p-y-1" data-env-security-check="{{ $check->id }}" data-env-security-status="{{ $check->status }}">
                        <span class="min-w-0" style="overflow-wrap: anywhere;">{{ $check->domain }}</span>
                        @include('larena-env::admin.partials.security-check-action', ['check' => $check, 'panel' => $finding['panel']])
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @if (count($finding['checks']) === 1)
        @include('larena-env::admin.partials.security-check-action', ['check' => $finding['checks'][0], 'panel' => $finding['panel']])
    @endif
</li>
