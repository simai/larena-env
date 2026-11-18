@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.tab_title', ['title' => __('larena-env::admin.panel_settings.title')]))
@section('heading', __('larena-env::admin.panel_settings.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.panel_settings.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- «Настройки» as a map (study part 8, PX-81): each section with what it holds now and «Изменить» beside it; security
         and the programs live under «Сервер» and are linked from here. PanelSettingsController builds the rows. --}}
    <section class="larena-panel p-2" style="margin: 0;" data-env-settings-map="{{ count($rows) }}" aria-label="{{ __('larena-env::admin.panel_settings.label') }}">
        @include('larena-env::admin.partials.facts', ['items' => $rows, 'label' => __('larena-env::admin.panel_settings.label'), 'mark' => 'settings'])
    </section>
    {{-- One saving rule (PX-90): when a change acts. --}}
    <p class="m-0 m-top-2 text-small color-on-surface-variant" data-env-settings-when>{{ __('larena-env::admin.panel_settings.when') }}</p>
@endsection
