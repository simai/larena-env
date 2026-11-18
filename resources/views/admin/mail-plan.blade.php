@extends('larena-admin::layouts.app')

{{-- A change of the panel's mail (owner decision C335), planned in words as the admin's «Действие с планом» (larena/admin
     AdminPlannedAction): what will be kept — the password only as «новый» or «прежний» —, what happens to mail after it,
     then one filled answer and «Отмена». --}}
@section('title', __('larena-env::admin.plan.title_of', ['action' => $heading]))
@section('heading', $heading)
@section('eyebrow', __('larena-env::mail.title'))

@section('content')
    @include('larena-env::admin.partials.notes')
    <section class="larena-panel larena-panel-padded" data-env-mail-plan aria-label="{{ $heading }}">
        {{ $action->html() }}
    </section>
@endsection
