@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.update.page_title'))
@section('heading', __('larena-env::admin.update.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.update.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- «Обновления» among the panel's settings (owner 2026-11-18): the order of the two updates, «Проверить» (the server is
         asked again what each would bring), then simai-env and the panel. --}}
    <div class="flex flex-wrap items-cross-center content-main-between g-1 m-bottom-2" data-env-update-head>
        <p class="m-0 color-on-surface-variant" data-env-update-order>{{ __('larena-env::admin.update.order') }}</p>
        @if (($supported ?? false) && ($canUpdate ?? false) && ($checkUrl ?? null) !== null)
            <form method="post" action="{{ $checkUrl }}" class="m-0" data-env-update-check>
                @csrf
                <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.update.check') }}</button>
            </form>
        @endif
    </div>

    @include('larena-env::admin.partials.update-targets')
@endsection
