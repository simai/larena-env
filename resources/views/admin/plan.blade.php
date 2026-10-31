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
        <dl class="larena-recipe-facts">
            <div><dt>{{ __('larena-env::admin.plan.command') }}</dt><dd>{{ $plan->command }}</dd></div>
            <div><dt>{{ __('larena-env::admin.plan.target') }}</dt><dd>{{ $plan->target ?? $target }}</dd></div>
            <div><dt>{{ __('larena-env::admin.plan.effects') }}</dt><dd>{{ $plan->effects }}</dd></div>
            <div><dt>{{ __('larena-env::admin.plan.lock') }}</dt><dd>{{ $plan->lock ?? '—' }}</dd></div>
        </dl>
        @if ($plan->args !== [])
            <h3>{{ __('larena-env::admin.plan.arguments') }}</h3>
            <dl class="larena-recipe-facts">
                @foreach ($plan->args as $name => $value)
                    <div data-env-plan-argument="{{ $name }}"><dt>{{ $name }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        @endif
        <form method="post" action="{{ $runUrl }}" data-env-confirm="{{ $plan->command }}">
            @csrf
            @foreach ($hidden as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach
            <button type="submit">{{ __('larena-env::admin.plan.confirm') }}</button>
        </form>
        <p><a href="{{ $cancelUrl }}">{{ __('larena-env::admin.plan.cancel') }}</a></p>
    </section>
@endsection
