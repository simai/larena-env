@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.plan.title'))
@section('heading', $heading)
@section('eyebrow', $eyebrow)
@section('description', __('larena-env::admin.plan.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" data-env-plan="{{ $plan->command }}" aria-labelledby="env-plan">
        <h2 id="env-plan">{{ __('larena-env::admin.plan.heading') }}</h2>
        <p>{{ $plan->description }}</p>
        @if (($replaces ?? null) !== null)
            <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.plan.replaces') }}</h3>
            <p data-env-plan-replaces role="note">{{ $replaces }}</p>
        @endif
        @if (($update ?? null) !== null)
            @include('larena-env::admin.partials.update-plan', ['update' => $update])
        @endif
        @foreach ($then ?? [] as $after)
            <p data-env-plan-then>{{ $after }}</p>
        @endforeach
        <dl class="m-0" data-env-facts>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.command') }}</dt><dd class="m-0 min-w-0 text-end">{{ $plan->command }}</dd></div>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.target') }}</dt><dd class="m-0 min-w-0 text-end">{{ $plan->target ?? $target }}</dd></div>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.effects') }}</dt><dd class="m-0 min-w-0 text-end">{{ $plan->effects }}</dd></div>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.plan.lock') }}</dt><dd class="m-0 min-w-0 text-end">{{ $plan->lock ?? '—' }}</dd></div>
        </dl>
        @if ($plan->args !== [])
            <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.plan.arguments') }}</h3>
            <dl class="m-0" data-env-facts>
                @foreach ($plan->args as $name => $value)
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-plan-argument="{{ $name }}"><dt class="color-on-surface-variant">{{ $name }}</dt><dd class="m-0 min-w-0 text-end">{{ $value }}</dd></div>
                @endforeach
            </dl>
        @endif
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
