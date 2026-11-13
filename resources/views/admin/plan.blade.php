@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.plan.title'))
@section('heading', $heading)
@section('eyebrow', $eyebrow)
@section('description', __('larena-env::admin.plan.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" data-env-plan="{{ $plan->command }}" aria-labelledby="env-plan">
        <h2 id="env-plan" class="text-2">{{ __('larena-env::admin.plan.heading') }}</h2>
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
            <dl class="m-0" style="display: block;" data-env-facts>
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
        @foreach ($then ?? [] as $after)
            <p data-env-plan-then>{{ $after }}</p>
        @endforeach
        {{-- What and with which values, in the panel's words; the server's own terms under «Подробнее» (the panel's UX
             audit, COPY-02). --}}
        <dl class="m-0" style="display: block;" data-env-facts>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.target') }}</dt><dd class="m-0 min-w-0 text-end">{{ $plan->target ?? $target }}</dd></div>
            @foreach (\Larena\Env\Admin\PlanValues::of($plan->args, $plan->target ?? $target, ($check ?? null) !== null ? ['id'] : []) as $row)
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-argument="{{ $row['name'] }}"><dt class="color-on-surface-variant">{{ $row['label'] }}</dt><dd class="m-0 min-w-0 text-end" style="overflow-wrap: anywhere;">{{ $row['value'] }}</dd></div>
            @endforeach
        </dl>
        <details class="m-y-2" data-env-plan-server>
            <summary>{{ __('larena-env::admin.more') }}</summary>
            <p>{{ $plan->description }}</p>
            <dl class="m-0" style="display: block;" data-env-facts>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.command') }}</dt><dd class="m-0 min-w-0 text-end mono">{{ $plan->command }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.effects') }}</dt><dd class="m-0 min-w-0 text-end">{{ \Larena\Env\Admin\PlanValues::effects($plan->effects) }}</dd></div>
                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.lock') }}</dt><dd class="m-0 min-w-0 text-end">{{ \Larena\Env\Admin\PlanValues::lock($plan->lock) }}</dd></div>
            </dl>
        </details>
        <form method="post" action="{{ $runUrl }}" data-env-confirm="{{ $plan->command }}">
            @csrf
            @foreach ($hidden as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach
            <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.plan.confirm') }}</button>
        </form>
        <p><a href="{{ $cancelUrl }}">{{ __('larena-env::admin.plan.cancel') }}</a></p>
    </section>
@endsection
