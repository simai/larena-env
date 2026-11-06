@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.storages.title'))
@section('heading', __('larena-env::admin.storages.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.storages.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- Storages for the sites' backups away from the server (simai-env 1.44.0), the administrator's: each storage with its
         bucket, how long copies stay and its last test, the copies in the open one with «Забрать на сервер», and a storage
         to add. Every change is planned and confirmed; the keys never come back into the page. --}}
    @if (!$supported)
        <section class="larena-panel larena-panel-padded" data-env-storages="unsupported">
            <p class="m-0">{{ __('larena-env::admin.storages.unsupported') }}</p>
        </section>
    @elseif ($storages === null)
        <section class="larena-panel larena-panel-padded" data-env-storages="unreadable">
            <p class="m-0" role="alert">{{ __('larena-env::admin.storages.unreadable') }}</p>
        </section>
    @else
        <section class="larena-panel larena-panel-padded" data-env-storages="{{ count($storages) }}" aria-labelledby="env-storages">
            <h2 id="env-storages" class="text-2">{{ __('larena-env::admin.storages.list') }}</h2>
            @if ($storages === [])
                <p class="m-0">{{ __('larena-env::admin.storages.none') }}</p>
            @else
                <ul class="m-0 p-0" style="list-style: none;">
                    @foreach ($storages as $storage)
                        <li class="flex flex-wrap items-cross-center g-2 p-y-2 border-top-1 border-top-solid border-outline-variant" data-env-storage="{{ $storage->name }}">
                            <span class="grow min-w-0" style="flex-basis: 16rem;">
                                <strong>{{ $storage->name }}</strong>
                                @if ($storage->default)<sf-badge appearance="tonal" status="success" text="{{ __('larena-env::admin.storages.default') }}"></sf-badge>@endif
                                <span class="color-on-surface-variant" style="display: block; overflow-wrap: anywhere;">
                                    {{ $storage->bucket }}@if ($storage->prefix !== null)/{{ $storage->prefix }}@endif
                                    @if ($storage->endpoint !== null) · {{ preg_replace('#\Ahttps?://#', '', $storage->endpoint) }}@endif
                                    @if ($storage->keep !== null) · {{ __('larena-env::admin.storages.keeps', ['days' => (string) $storage->keep]) }}@endif
                                    @if ($storage->keyHint !== null) · {{ __('larena-env::admin.storages.key', ['hint' => $storage->keyHint]) }}@endif
                                </span>
                                <span style="display: block;" data-env-storage-test="{{ $storage->lastTest === null ? 'never' : ($storage->lastTest['ok'] ? 'ok' : 'failed') }}">
                                    @if ($storage->lastTest === null){{ __('larena-env::admin.storages.test_never') }}
                                    @elseif ($storage->lastTest['ok']){{ __('larena-env::admin.storages.test_ok', ['when' => \Larena\Env\Server\Moment::human($storage->lastTest['at'])]) }}
                                    @else<span style="color: var(--sf-error);">{{ __('larena-env::admin.storages.test_failed', ['when' => \Larena\Env\Server\Moment::human($storage->lastTest['at']), 'error' => $storage->lastTest['error'] ?? '—']) }}</span>@endif
                                </span>
                            </span>
                            <a class="larena-button" style="min-height: 2.75rem;" href="{{ route('larena.env.admin.storages', ['storage' => $storage->name], false) }}#env-storage-copies"
                               @if ($open?->name === $storage->name) aria-current="true" @endif>{{ __('larena-env::admin.storages.copies') }}</a>
                            @foreach (['test', 'remove'] as $op)
                                @if ($allows[$op])
                                    <form method="post" action="{{ route('larena.env.admin.storages.plan', [], false) }}" class="m-0" data-env-storage-op="{{ $op }}">
                                        @csrf
                                        <input type="hidden" name="op" value="{{ $op }}">
                                        <input type="hidden" name="name" value="{{ $storage->name }}">
                                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.storages.'.$op) }}</button>
                                    </form>
                                @endif
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($open !== null)
            <section class="larena-panel larena-panel-padded" data-env-storage-copies="{{ $copies === null ? 'unreadable' : count($copies) }}" aria-labelledby="env-storage-copies">
                <h2 id="env-storage-copies" class="text-2">{{ __('larena-env::admin.storages.copies_in', ['name' => $open->name]) }}</h2>
                <p class="color-on-surface-variant">{{ __('larena-env::admin.storages.copies_note') }}</p>
                @if ($copies === null)
                    <p role="alert">{{ __('larena-env::admin.storages.copies_unreadable') }}</p>
                @elseif ($copies === [])
                    <p class="m-0">{{ __('larena-env::admin.storages.copies_none') }}</p>
                @else
                    <ul class="m-0 p-0" style="list-style: none;">
                        @foreach ($copies as $copy)
                            <li class="flex flex-wrap items-cross-center g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-storage-copy="{{ $copy['domain'] }}/{{ $copy['backup'] }}">
                                <span class="grow min-w-0"><strong>{{ $copy['domain'] }}</strong> <span class="color-on-surface-variant">{{ \Larena\Env\Server\Moment::human($copy['created_at'] ?? null) }} · {{ $copy['backup'] }}</span></span>
                                @if ($allows['fetch'])
                                    <form method="post" action="{{ route('larena.env.admin.storages.plan', [], false) }}" class="m-0" data-env-storage-op="fetch">
                                        @csrf
                                        <input type="hidden" name="op" value="fetch">
                                        <input type="hidden" name="name" value="{{ $open->name }}">
                                        <input type="hidden" name="domain" value="{{ $copy['domain'] }}">
                                        <input type="hidden" name="backup" value="{{ $copy['backup'] }}">
                                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.storages.fetch') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($allows['add'])
            <section class="larena-panel larena-panel-padded" data-env-storage-add aria-labelledby="env-storage-add">
                <h2 id="env-storage-add" class="text-2">{{ __('larena-env::admin.storages.add') }}</h2>
                <p class="color-on-surface-variant">{{ __('larena-env::admin.storages.add_note') }}</p>
                <form method="post" action="{{ route('larena.env.admin.storages.plan', [], false) }}" class="m-0" data-env-storage-op="add" autocomplete="off">
                    @csrf
                    <input type="hidden" name="op" value="add">
                    <div style="display: grid; gap: .75rem 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr));">
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.provider') }}</span>
                            <select name="provider" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;">
                                @foreach ($providers as $provider)<option value="{{ $provider }}">{{ __('larena-env::admin.storages.providers.'.$provider) }}</option>@endforeach
                            </select>
                        </label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.name') }}</span>
                            <input type="text" name="name" required pattern="[a-z0-9][a-z0-9-]{0,31}" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;" placeholder="yandex">
                            <span class="color-on-surface-variant text-0">{{ __('larena-env::admin.storages.fields.name_hint') }}</span></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.bucket') }}</span>
                            <input type="text" name="bucket" required class="larena-input" style="inline-size: 100%; min-height: 2.75rem;"></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.prefix') }}</span>
                            <input type="text" name="prefix" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;" placeholder="backups"></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.endpoint') }}</span>
                            <input type="url" name="endpoint" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;" placeholder="https://…">
                            <span class="color-on-surface-variant text-0">{{ __('larena-env::admin.storages.fields.endpoint_hint') }}</span></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.region') }}</span>
                            <input type="text" name="region" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;"></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.access_key') }}</span>
                            <input type="text" name="access_key" required class="larena-input" style="inline-size: 100%; min-height: 2.75rem;" spellcheck="false"></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.secret_key') }}</span>
                            <input type="password" name="secret_key" required class="larena-input" style="inline-size: 100%; min-height: 2.75rem;" autocomplete="new-password"></label>
                        <label><span style="font-weight: 600; display: block;">{{ __('larena-env::admin.storages.fields.keep') }}</span>
                            <input type="number" name="keep" min="1" max="90" value="30" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;"></label>
                    </div>
                    <label class="flex items-cross-center g-1 m-t-2" style="min-height: 2.75rem;"><input type="checkbox" name="default" value="1" @checked($storages === [])> {{ __('larena-env::admin.storages.fields.default') }}</label>
                    <button type="submit" class="larena-button larena-button-primary m-t-2" style="min-height: 2.75rem;">{{ __('larena-env::admin.storages.add_go') }}</button>
                </form>
            </section>
        @endif
    @endif
@endsection
