@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.plan.title_of', ['action' => $heading]))
@section('heading', $heading)
@section('eyebrow', $eyebrow)

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- «Вернуть сайт» of a removed site, before its first step: the server makes the site again only with its kind
         (simai-env 1.65.0's gateway takes no site:add without one) and the backup may not name it, so the person chooses
         it here — the backup's own kind chosen where the server names it — and whether a database is made (ticked where
         the backup holds one). Then the plan, as every step. --}}
    <section class="larena-panel larena-panel-padded" data-env-held-return="{{ $domain }}" data-env-held-return-known="{{ $known ?? 'none' }}" aria-labelledby="env-held-return">
        <h2 id="env-held-return" class="text-2">{{ __('larena-env::admin.storages.held.choose.title', ['domain' => $domain]) }}</h2>
        <p class="color-on-surface-variant">{{ __('larena-env::admin.storages.held.choose.'.($chosen !== null ? 'note_known' : 'note_unknown')) }}</p>
        @if ($kinds === [])
            <p role="alert" data-env-held-return-none>{{ __('larena-env::admin.storages.held.choose.none') }}</p>
            <p class="m-0"><a class="larena-button" href="{{ $cancelUrl }}">{{ __('larena-env::admin.storages.held.choose.back') }}</a></p>
        @else
            <form method="post" action="{{ $planUrl }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0; border-radius: 0;" data-env-held-return-form>
                @csrf
                <input type="hidden" name="op" value="return-site">
                <input type="hidden" name="domain" value="{{ $domain }}">
                <input type="hidden" name="backup" value="{{ $backup }}">
                <fieldset class="larena-field" style="border: 0; padding: 0; margin: 0;">
                    <legend>{{ __('larena-env::admin.storages.held.choose.kind') }}</legend>
                    <div class="flex flex-col gap-1" role="radiogroup" data-env-held-return-kinds>
                        @foreach ($kinds as $name => $db)
                            <label class="flex items-cross-center gap-1"><input type="radio" name="profile" value="{{ $name }}" required @checked($chosen === $name) data-env-held-return-kind="{{ $name }}" data-env-held-return-db="{{ $db }}"> <span>{{ $labels[$name] }}</span>@if ($known === $name) <span class="text-small color-on-surface-variant">{{ __('larena-env::admin.storages.held.choose.was') }}</span>@endif</label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="larena-field">
                    <input type="hidden" name="create-db" value="no">
                    <label class="flex items-cross-center gap-1 larena-control-floor"><input type="checkbox" name="create-db" value="yes" @checked($database) data-env-held-return-create-db> {{ __('larena-env::admin.storages.held.choose.database') }}</label>
                    <p class="m-0 text-small color-on-surface-variant" data-env-held-return-db-note>{{ __('larena-env::admin.storages.held.choose.'.($database ? 'database_held' : 'database_none')) }} {{ __('larena-env::admin.storages.held.choose.database_kind') }}</p>
                </div>
                <div class="larena-form-actions flex flex-wrap gap-1">
                    <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.storages.held.choose.go') }}</button>
                    <a class="larena-button" href="{{ $cancelUrl }}">{{ __('larena-env::admin.storages.held.choose.back') }}</a>
                </div>
            </form>
        @endif
    </section>
@endsection
