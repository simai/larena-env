@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.panel_address.moving_title'))
@section('heading', __('larena-env::admin.panel_address.moving_title'))
@section('eyebrow', __('larena-env::admin.panel_address.title'))

@section('content')
    {{-- The port or the address by IP is changing: this address may stop answering, so the person goes on at the new one
         and keeps the change there within 5 minutes, or the server puts the old one back. --}}
    <section class="larena-panel larena-panel-padded" data-env-panel-address-moving="{{ $op }}" aria-labelledby="env-panel-address-moving">
        <h2 id="env-panel-address-moving" class="text-2">{{ __('larena-env::admin.panel_address.moving_'.$op, ['port' => (string) ($port ?? '')]) }}</h2>
        <p>{{ __('larena-env::admin.panel_address.moving_open') }}</p>
        <p><a class="larena-button larena-button-primary" style="min-height: 2.75rem;" href="{{ $next }}" data-env-panel-address-next>{{ $nextBase }}</a></p>
        <p class="color-on-surface-variant">{{ __('larena-env::admin.panel_address.moving_back') }}</p>
    </section>
@endsection
