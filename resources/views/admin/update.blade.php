@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.update.page_title'))
@section('heading', __('larena-env::admin.update.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.update.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    @include('larena-env::admin.partials.update-targets')
@endsection
