{{-- What a plan carries beyond its values, drawn inside the question of «Действие с планом» (larena/admin AdminPlannedAction):
     what a restore replaces, what an update changes, the rule a fix puts right, what a new site asks of the server. --}}
@if (($replaces ?? null) !== null)
    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.plan.replaces') }}</h3>
    <p data-env-plan-replaces role="note">{{ $replaces }}</p>
@endif
@if (($update ?? null) !== null)
    @include('larena-env::admin.partials.update-plan', ['update' => $update])
@endif
@if (($system ?? null) !== null)
    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.software.overview.packages') }}</h3>
    @include('larena-env::admin.partials.system-updates', ['system' => $system])
@endif
{{-- The rule of the site's check a fix puts right: what the server found and what it would do, in its own words. --}}
@if (($check ?? null) !== null)
    <h3 class="m-top-2 m-bottom-1 text-medium" data-env-plan-check="{{ $check->id }}">{{ \Larena\Env\Server\SiteDoctor::checkText($check->title, $check->id) }}</h3>
    @if ($check->detail !== null)
        <p class="color-on-surface-variant" data-env-plan-check-detail>{{ __('larena-env::admin.doctor.found', ['detail' => $check->detail]) }}</p>
    @endif
    @if ($check->plan !== null)
        <p data-env-plan-check-plan>{{ $check->plan }}</p>
    @endif
@endif
{{-- What the site asks of the server (simai-env 1.56.0): memory against the kind's minimum and recommendation, and
     for a copy the free disk against three times the archive. --}}
@if (($resources ?? null) !== null)
    <h3 class="m-top-2 m-bottom-1 text-medium" data-env-plan-resources>{{ __('larena-env::admin.routes.resources.title') }}</h3>
    <dl class="m-0" style="display: block;" data-env-facts data-larena-action-details>
        @if ($resources->ramMb !== null && $resources->ramMinMb > 0)
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-ram><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.resources.ram') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.routes.resources.ram_value', ['have' => \Larena\Env\Server\Bytes::human($resources->ramMb * 1048576), 'min' => \Larena\Env\Server\Bytes::human($resources->ramMinMb * 1048576), 'recommended' => \Larena\Env\Server\Bytes::human(max($resources->ramRecommendedMb, $resources->ramMinMb) * 1048576)]) }}</dd></div>
        @endif
        @if ($resources->diskFreeBytes !== null)
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-disk><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.resources.disk') }}</dt><dd class="m-0 min-w-0 text-end">{{ $resources->neededBytes > 0 ? __('larena-env::admin.routes.resources.disk_needed', ['free' => \Larena\Env\Server\Bytes::human($resources->diskFreeBytes), 'needed' => \Larena\Env\Server\Bytes::human($resources->neededBytes)]) : __('larena-env::admin.routes.resources.disk_free', ['free' => \Larena\Env\Server\Bytes::human($resources->diskFreeBytes)]) }}</dd></div>
        @endif
        @if ($resources->archive !== null)
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-archive><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.resources.archive') }}</dt><dd class="m-0 min-w-0 text-end" style="overflow-wrap: anywhere;">{{ __('larena-env::admin.routes.resources.archive_value', ['name' => $resources->archive['base'], 'count' => (string) $resources->archive['count'], 'size' => \Larena\Env\Server\Bytes::human($resources->archive['total_bytes'])]) }}{{ $resources->archive['encrypted'] ? ' · '.__('larena-env::admin.routes.resources.encrypted') : '' }}</dd></div>
        @endif
    </dl>
    @foreach ($resources->warnings as $warning)
        <p role="note" data-env-plan-resource-warning="{{ $warning }}">{{ __('larena-env::admin.routes.resources.warnings.'.$warning) === 'larena-env::admin.routes.resources.warnings.'.$warning ? __('larena-env::admin.routes.resources.warnings.other') : __('larena-env::admin.routes.resources.warnings.'.$warning) }}</p>
    @endforeach
@endif
{{-- A service's stop (simai-env 1.62.0): the sites that lose something while it is stopped, and what each loses. --}}
@if (($serviceStop ?? null) !== null)
    @if ($serviceStop['sites'] === [])
        <p data-env-plan-stop-sites="0">{{ __('larena-env::admin.services.stop_no_sites') }}</p>
    @else
        <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.services.stop_sites') }}</h3>
        <dl class="m-0" style="display: block;" data-env-plan-stop-sites="{{ count($serviceStop['sites']) }}">
            @foreach ($serviceStop['sites'] as $affected)
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-stop-site="{{ $affected['domain'] }}"><dt class="min-w-0" style="overflow-wrap: anywhere;">{{ $affected['domain'] }}</dt><dd class="m-0 min-w-0 text-end color-on-surface-variant">{{ implode(', ', array_map(static fn (string $use): string => __('larena-env::admin.services.uses.'.$use) === 'larena-env::admin.services.uses.'.$use ? $use : __('larena-env::admin.services.uses.'.$use), $affected['uses'])) }}</dd></div>
            @endforeach
        </dl>
    @endif
    @if ($serviceStop['server'])
        <p role="note" data-env-plan-stop-server>{{ __('larena-env::admin.services.stop_server') }}</p>
    @endif
    <p class="color-on-surface-variant" data-env-plan-stop-until>{{ __('larena-env::admin.services.stop_until') }}</p>
@endif
{{-- One site's caches (simai-env 1.57.0): what will be cleared, and what is skipped and why. --}}
@if (($plan ?? null) instanceof \Larena\Env\Operations\PanelPlan && $plan->clears !== [])
    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.cache.title') }}</h3>
    <dl class="m-0" style="display: block;" data-env-plan-clears>
        @foreach ($plan->clears as $clear)
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-clear="{{ $clear['name'] }}" data-env-plan-clear-state="{{ $clear['state'] }}"><dt class="color-on-surface-variant">{{ __('larena-env::admin.cache.names.'.$clear['name']) === 'larena-env::admin.cache.names.'.$clear['name'] ? $clear['name'] : __('larena-env::admin.cache.names.'.$clear['name']) }}</dt><dd class="m-0 min-w-0 text-end">{{ $clear['state'] === 'planned' ? __('larena-env::admin.cache.planned') : ($clear['reason'] === 'none' || $clear['reason'] === null ? __('larena-env::admin.cache.skipped_none') : __('larena-env::admin.cache.skipped')) }}</dd></div>
        @endforeach
    </dl>
@endif
@foreach ($then ?? [] as $after)
    <p data-env-plan-then>{{ $after }}</p>
@endforeach
