@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.people.title'))
@section('heading', __('larena-env::admin.people.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.people.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- People and their spaces as rows (the approved panel layout): who, which space, act as them; then the spaces with
         their disk and whether they are blocked. --}}
    <section class="larena-panel" style="margin: 0 0 1rem;" aria-labelledby="env-people" data-env-people>
        <div class="p-2 p-bottom-1">
            <h2 id="env-people" class="m-0 text-2">{{ __('larena-env::admin.people.list') }}</h2>
            <div class="m-0 m-top-1 color-on-surface-variant m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.people.explain')])</div>
            {{-- A second person in three steps, in order (block 4): the role on «Пользователи», a workspace here, then the tie. --}}
            <h3 class="m-0 m-top-1 text-medium" id="env-people-steps">{{ __('larena-env::admin.people.steps_title') }}</h3>
            <ol class="m-0 m-top-1 m-bottom-1" aria-labelledby="env-people-steps" data-env-people-steps>
                @foreach ([0, 1, 2] as $number)
                    <li>{{ __('larena-env::admin.people.steps.'.$number) }}</li>
                @endforeach
            </ol>
            @if ($reasonText !== null)
                <p class="m-0 m-top-1 color-error" role="alert">{{ $reasonText }}</p>
            @endif
        </div>
        @if ($people === [])
            <p class="p-2 m-0 border-top-1 border-top-solid border-outline-variant color-on-surface-variant" data-env-people-empty>{{ __('larena-env::admin.people.empty') }}</p>
        @else
            <ul class="m-0 p-0" style="list-style: none;">
                @foreach ($people as $person)
                    <li class="flex flex-wrap items-cross-center g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-person="{{ $person['subject'] }}">
                        <div class="grow min-w-0" style="flex-basis: 12rem;">
                            <div style="overflow-wrap: anywhere;">{{ $person['label'] }}</div>
                            <div class="text-small color-on-surface-variant">{{ $person['owner'] === null ? __('larena-env::admin.people.no_space') : __('larena-env::admin.people.space').': '.$person['owner'] }}</div>
                        </div>
                        <form method="post" action="{{ route('larena.env.admin.people.assign', [], false) }}" class="flex flex-wrap items-cross-center g-1 m-0">
                            @csrf
                            <input type="hidden" name="subject" value="{{ $person['subject'] }}">
                            <label class="larena-visually-hidden" for="env-space-{{ $loop->index }}">{{ __('larena-env::admin.people.space') }}</label>
                            <select class="larena-control-floor" id="env-space-{{ $loop->index }}" name="owner" style="max-width: 14rem;">
                                <option value="">{{ __('larena-env::admin.people.no_space') }}</option>
                                @foreach ($owners as $owner)
                                    <option value="{{ $owner }}" @if ($person['owner'] === $owner) selected @endif>{{ $owner }}</option>
                                @endforeach
                                @if ($person['owner'] !== null && !in_array($person['owner'], $owners, true))
                                    <option value="{{ $person['owner'] }}" selected>{{ $person['owner'] }}</option>
                                @endif
                            </select>
                            <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.people.save') }}</button>
                        </form>
                        <div data-env-person-actions>
                            @if ($person['owner'] !== null)
                                <form method="post" action="{{ route('larena.env.admin.people.act', [], false) }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="subject" value="{{ $person['subject'] }}">
                                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.people.act_as') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="p-2 m-0 border-top-1 border-top-solid border-outline-variant color-on-surface-variant text-small">{{ __('larena-env::admin.people.levels') }} <a href="/admin/users">{{ __('larena-env::admin.people.users_link') }}</a></p>
    </section>

    @if ($owners !== [])
        <section class="larena-panel" style="margin: 0 0 1rem;" aria-labelledby="env-owners" data-env-owners>
            <div class="p-2 p-bottom-1">
                <h2 id="env-owners" class="m-0 text-2">{{ __('larena-env::admin.people.spaces') }}</h2>
                @if ($canBlock ?? false)
                    <div class="m-0 m-top-1 color-on-surface-variant m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.people.block_explain')])</div>
                @endif
            </div>
            <ul class="m-0 p-0" style="list-style: none;">
                @foreach ($owners as $owner)
                    <li class="flex flex-wrap items-cross-center g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-owner="{{ $owner }}" data-env-owner-blocked="{{ in_array($owner, $blockedOwners ?? [], true) ? 'yes' : 'no' }}">
                        <div class="grow min-w-0 flex flex-wrap items-cross-center g-1" style="flex-basis: 12rem;">
                            <span class="mono" style="overflow-wrap: anywhere;">{{ $owner }}</span>
                            @if (in_array($owner, $blockedOwners ?? [], true))
                                <sf-badge status="error" appearance="tonal" text="{{ __('larena-env::admin.people.blocked') }}"></sf-badge>
                            @endif
                            @if (isset($ownerDisk[$owner]))
                                <span class="text-small color-on-surface-variant">{{ __('larena-env::admin.people.disk', ['size' => \Larena\Env\Server\Bytes::human($ownerDisk[$owner])]) }}</span>
                            @endif
                        </div>
                        @if ($canBlock ?? false)
                            <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}" class="m-0">
                                @csrf
                                <input type="hidden" name="action" value="{{ in_array($owner, $blockedOwners ?? [], true) ? 'unblock' : 'block' }}">
                                <input type="hidden" name="name" value="{{ $owner }}">
                                <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.people.'.(in_array($owner, $blockedOwners ?? [], true) ? 'unblock' : 'block')) }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="larena-panel larena-panel-padded" aria-labelledby="env-new-owner" data-env-new-owner-form>
        <h2 id="env-new-owner" class="text-2">{{ __('larena-env::admin.people.new_owner') }}</h2>
        <p>{{ __('larena-env::admin.people.new_owner_explain') }}</p>
        <form method="post" action="{{ route('larena.env.admin.people.owners.plan', [], false) }}" class="larena-form">
            @csrf
            <div class="larena-field">
                <label for="env-owner-name">{{ __('larena-env::admin.people.owner_name') }}</label>
                <input type="text" id="env-owner-name" name="name" required pattern="[a-z][a-z0-9_-]{0,31}" autocomplete="off">
            </div>
            <div class="larena-field">
                <label for="env-owner-key">{{ __('larena-env::admin.people.owner_key') }}</label>
                <textarea id="env-owner-key" name="pubkey" rows="3"></textarea>
            </div>
            <div class="larena-form-actions">
                <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
            </div>
        </form>
    </section>
@endsection
