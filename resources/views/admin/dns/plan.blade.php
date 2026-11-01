@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.plan.title'))
@section('heading', $undo ? __('larena-env::admin.dns.undo') : __('larena-env::admin.dns.plan_heading'))
@section('eyebrow', $target)
@section('description', __('larena-env::admin.plan.description'))

@section('content')
    <section class="larena-panel larena-panel-padded" data-env-dns-plan="{{ count($changes) }}" aria-labelledby="env-dns-plan">
        <h2 id="env-dns-plan">{{ __('larena-env::admin.plan.heading') }}</h2>
        <p>{{ __('larena-env::admin.dns.plan_explain') }}</p>
        <ul class="larena-list">
            @foreach ($changes as $change)
                <li data-env-dns-change="{{ $change->action }}" style="overflow-wrap: anywhere;">
                    @if ($change->action === 'create')
                        {{ __('larena-env::admin.dns.plan_create', ['record' => $change->after->line()]) }}
                    @elseif ($change->action === 'update')
                        {{ __('larena-env::admin.dns.plan_update', ['before' => $change->before->line(), 'after' => $change->after->line()]) }}
                    @else
                        {{ __('larena-env::admin.dns.plan_delete', ['record' => $change->before->line()]) }}
                    @endif
                </li>
            @endforeach
        </ul>
        <p>{{ __('larena-env::admin.dns.plan_undo_note') }}</p>
        <form method="post" action="{{ $runUrl }}" data-env-dns-confirm>
            @csrf
            <input type="hidden" name="plan" value="{{ $planId }}">
            <button type="submit" class="larena-button">{{ __('larena-env::admin.plan.confirm') }}</button>
        </form>
        <p><a href="{{ $cancelUrl }}">{{ __('larena-env::admin.plan.cancel') }}</a></p>
    </section>
@endsection
