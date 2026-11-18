@extends('larena-admin::layouts.app')

{{-- The browser's tab names the action, not «Подтверждение» (PX-16). --}}
@section('title', __('larena-env::admin.plan.title_of', ['action' => $heading]))
@section('heading', $heading)
@section('eyebrow', $eyebrow)
@section('description', __('larena-env::admin.plan.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The plan as the admin's «Действие с планом» (larena/admin AdminPlannedAction, owner decision dec-63f3e971f41f): one
         question window with the plan in words, the deciding sentence on its plate, the name typed before a site or a database
         goes, the ticks that change the plan, one «Подробнее» for the server's own terms, one filled answer and a quiet
         «Отмена» beside it. The same part opens in the side panel over the site (X-Larena-Fragment: action); this address
         stays for links from the journal and the notifications. --}}
    @if (isset($refused))
        {{-- An action the server will not plan (simai-env 1.60.0 checks, 1.61.0 named refusals): the same window, what stops
             it in words above the button, which stays off, and the way out where there is one. --}}
        <section class="larena-panel larena-panel-padded" data-env-plan-refused="{{ $refusedReason }}" aria-label="{{ $heading }}">
            {{ \Larena\Env\Admin\PlanAction::refused($heading, $refused, $refusedNotes, $runUrl, csrf_token(), $cancelUrl, $refusedKey, $refusedMore) }}
        </section>
    @else
    <section class="larena-panel larena-panel-padded" data-env-plan="{{ $plan->command }}" data-env-confirm="{{ $plan->command }}" aria-label="{{ $heading }}">
        {{ \Larena\Env\Admin\PlanAction::part(get_defined_vars(), csrf_token(), $__env->make('larena-env::admin.partials.plan-more', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render()) }}
    </section>
    @endif
@endsection
