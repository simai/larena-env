@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.databases.page_title'))
@section('heading', __('larena-env::admin.databases.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.databases.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- «Базы данных» (simai-env 1.59.0 db:list): one database per site at most; a row opens the site's «База данных». --}}
    @if ($view === null)
        <section class="larena-panel larena-empty" data-env-databases="unreadable" role="alert">
            <p>{{ __('larena-env::admin.databases.unreadable') }}</p>
        </section>
    @else
        @include('larena-admin::dataview.page', ['dataview' => $view, 'sectionId' => 'env-server-databases', 'owner' => 'larena/env',
            'ariaLabel' => __('larena-env::admin.databases.title'), 'queryUrl' => route('larena.env.admin.databases', [], false),
            'matchedCount' => $matched, 'source' => 'env_server_databases'])
    @endif
@endsection
