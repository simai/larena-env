{{-- The system's waiting package updates (simai-env 1.38.0): each package with its versions, the security ones marked,
     the ones kept back and the reboot the server waits for. On the overview and in software:update's plan. --}}
@if ($system->packages === [])
    <p class="m-0" data-env-system-packages="0">{{ __('larena-env::admin.software.overview.no_updates') }}</p>
@else
    <ul class="m-0 p-0" style="list-style: none;" data-env-system-packages="{{ count($system->packages) }}">
        @foreach ($system->packages as $package)
            <li class="flex flex-wrap items-cross-center content-main-between g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-system-package="{{ $package['name'] }}">
                <span class="flex flex-wrap items-cross-center g-1 min-w-0">
                    <span class="mono" style="overflow-wrap: anywhere;">{{ $package['name'] }}</span>
                    @if ($package['security'])
                        <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.security_badge') }}" data-env-system-security></sf-badge>
                    @endif
                </span>
                <span class="mono text-small color-on-surface-variant" style="overflow-wrap: anywhere;">{{ $package['from'] ?? '—' }} → {{ $package['to'] ?? '—' }}</span>
            </li>
        @endforeach
    </ul>
@endif
@if ($system->keptBack !== [])
    <p class="m-top-1 m-bottom-0 text-small color-on-surface-variant" data-env-system-kept-back>{{ __('larena-env::admin.software.overview.kept_back', ['packages' => implode(', ', $system->keptBack)]) }}</p>
@endif
@if ($system->rebootRequired)
    <p class="m-top-1 m-bottom-0" role="note" data-env-system-reboot>{{ $system->rebootPackages === []
        ? __('larena-env::admin.software.overview.reboot')
        : __('larena-env::admin.software.overview.reboot_for', ['packages' => implode(', ', $system->rebootPackages)]) }}</p>
@endif
