@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.people.title'))
@section('heading', __('larena-env::admin.people.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.people.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" aria-labelledby="env-people" data-env-people>
        <h2 id="env-people">{{ __('larena-env::admin.people.list') }}</h2>
        <p>{{ __('larena-env::admin.people.explain') }}</p>
        @if ($reasonText !== null)
            <p role="alert">{{ $reasonText }}</p>
        @endif
        @if ($people === [])
            <p data-env-people-empty>{{ __('larena-env::admin.people.empty') }}</p>
        @else
            <table class="larena-table">
                <thead><tr><th>{{ __('larena-env::admin.people.person') }}</th><th>{{ __('larena-env::admin.people.space') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($people as $person)
                    <tr data-env-person="{{ $person['subject'] }}">
                        <td>{{ $person['label'] }}</td>
                        <td>
                            <form method="post" action="{{ route('larena.env.admin.people.assign', [], false) }}">
                                @csrf
                                <input type="hidden" name="subject" value="{{ $person['subject'] }}">
                                <label class="larena-visually-hidden" for="env-space-{{ $loop->index }}">{{ __('larena-env::admin.people.space') }}</label>
                                <select id="env-space-{{ $loop->index }}" name="owner">
                                    <option value="">{{ __('larena-env::admin.people.no_space') }}</option>
                                    @foreach ($owners as $owner)
                                        <option value="{{ $owner }}" @if ($person['owner'] === $owner) selected @endif>{{ $owner }}</option>
                                    @endforeach
                                    @if ($person['owner'] !== null && !in_array($person['owner'], $owners, true))
                                        <option value="{{ $person['owner'] }}" selected>{{ $person['owner'] }}</option>
                                    @endif
                                </select>
                                <button type="submit">{{ __('larena-env::admin.people.save') }}</button>
                            </form>
                        </td>
                        <td>
                            @if ($person['owner'] !== null)
                                <form method="post" action="{{ route('larena.env.admin.people.act', [], false) }}">
                                    @csrf
                                    <input type="hidden" name="subject" value="{{ $person['subject'] }}">
                                    <button type="submit">{{ __('larena-env::admin.people.act_as') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <p>{{ __('larena-env::admin.people.levels') }} <a href="/admin/users">{{ __('larena-env::admin.people.users_link') }}</a></p>
    </section>

    <section class="larena-panel larena-panel-padded" aria-labelledby="env-new-owner" data-env-new-owner-form>
        <h2 id="env-new-owner">{{ __('larena-env::admin.people.new_owner') }}</h2>
        <p>{{ __('larena-env::admin.people.new_owner_explain') }}</p>
        <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}">
            @csrf
            <label for="env-owner-name">{{ __('larena-env::admin.people.owner_name') }}</label>
            <input type="text" id="env-owner-name" name="name" required pattern="[a-z][a-z0-9_-]{0,31}" autocomplete="off">
            <label for="env-owner-key">{{ __('larena-env::admin.people.owner_key') }}</label>
            <textarea id="env-owner-key" name="pubkey" rows="3"></textarea>
            <button type="submit">{{ __('larena-env::admin.operations.plan') }}</button>
        </form>
    </section>
@endsection
