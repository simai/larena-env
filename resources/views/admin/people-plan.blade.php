@extends('larena-admin::layouts.app')

{{-- An action on a person (roles study part 7): «Пригласить человека» and blocking, planned in words as the admin's
     «Действие с планом» (larena/admin AdminPlannedAction): what will be done, then one filled answer and «Отмена». --}}
@section('title', __('larena-env::admin.plan.title_of', ['action' => $heading]))
@section('heading', $heading)
@section('eyebrow', __('larena-env::admin.people.heading'))

@section('content')
    @include('larena-env::admin.partials.notes')
    <section class="larena-panel larena-panel-padded" data-env-person-plan aria-label="{{ $heading }}">
        {{ $action->html() }}
    </section>
@endsection
