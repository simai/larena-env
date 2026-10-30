@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.plan.title'))
@section('heading', __('larena-env::admin.operations.'.$operation->value))
@section('eyebrow', $domain)
@section('description', __('larena-env::admin.plan.description'))

@section('content')
    @if ($source === 'recorded')
        <section class="larena-panel larena-panel-padded" data-env-source="recorded" role="note">
            <p>{{ __('larena-env::admin.recorded_notice') }}</p>
        </section>
    @endif

    <section class="larena-panel larena-panel-padded" data-env-plan="{{ $plan->command }}" aria-labelledby="env-plan">
        <h2 id="env-plan">{{ __('larena-env::admin.plan.heading') }}</h2>
        <p>{{ $plan->description }}</p>
        <dl class="larena-recipe-facts">
            <div><dt>{{ __('larena-env::admin.plan.command') }}</dt><dd>{{ $plan->command }}</dd></div>
            <div><dt>{{ __('larena-env::admin.plan.target') }}</dt><dd>{{ $plan->target ?? $domain }}</dd></div>
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
        <form method="post" action="{{ route('larena.env.admin.site.run', ['domain' => $domain], false) }}" data-env-confirm="{{ $operation->value }}">
            @csrf
            <input type="hidden" name="operation" value="{{ $operation->value }}">
            @foreach ($input as $field => $value)
                <input type="hidden" name="{{ $field }}" value="{{ $value }}">
            @endforeach
            <button type="submit">{{ __('larena-env::admin.plan.confirm') }}</button>
        </form>
        <p><a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}">{{ __('larena-env::admin.plan.cancel') }}</a></p>
    </section>
@endsection
