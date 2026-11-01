@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.new_site.title'))
@section('heading', __('larena-env::admin.new_site.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.new_site.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" aria-labelledby="env-new-site">
        <h2 id="env-new-site">{{ __('larena-env::admin.new_site.heading') }}</h2>
        <form method="post" action="{{ route('larena.env.admin.sites.plan', [], false) }}" class="larena-form" data-env-new-site>
            @csrf
            <div class="larena-field">
                <label for="env-new-domain">{{ __('larena-env::admin.new_site.domain') }}</label>
                <input type="text" id="env-new-domain" name="domain" value="{{ $old['domain'] ?? '' }}" required autocomplete="off" placeholder="example.com">
            </div>
            <div class="larena-field">
                <label for="env-new-profile">{{ __('larena-env::admin.new_site.profile') }}</label>
                <select id="env-new-profile" name="profile" required>
                    @foreach ($profiles as $profile)
                        <option value="{{ $profile }}" data-env-profile="{{ $profile }}" @if (($old['profile'] ?? 'static') === $profile) selected @endif>{{ __('larena-env::admin.new_site.profiles.'.$profile) }}</option>
                    @endforeach
                </select>
            </div>
                <div class="larena-field">
                    <label for="env-new-php">{{ __('larena-env::admin.new_site.php') }}</label>
                    <select id="env-new-php" name="php">
                        <option value="">{{ __('larena-env::admin.fields.default') }}</option>
                        @foreach ($phpVersions as $version)
                            <option value="{{ $version }}" @if (($old['php'] ?? null) === $version) selected @endif>{{ $version }}</option>
                        @endforeach
                    </select>
                    <p>{{ __('larena-env::admin.new_site.php_hint') }}</p>
                </div>
                <div class="larena-field">
                    <label for="env-new-db">{{ __('larena-env::admin.new_site.database') }}</label>
                    <select id="env-new-db" name="create-db">
                        <option value="no">{{ __('larena-env::admin.no') }}</option>
                        <option value="yes" @if (($old['create-db'] ?? null) === 'yes') selected @endif>{{ __('larena-env::admin.yes') }}</option>
                    </select>
                </div>
                <div class="larena-field">
                    <label for="env-new-engine">{{ __('larena-env::admin.new_site.engine') }}</label>
                    <select id="env-new-engine" name="db-engine">
                        <option value="mysql">MySQL</option>
                        <option value="pgsql" @if (($old['db-engine'] ?? null) === 'pgsql') selected @endif>PostgreSQL</option>
                    </select>
                </div>
                <div class="larena-field">
                    <label for="env-new-redirect">{{ __('larena-env::admin.new_site.redirect_to') }}</label>
                    <input type="url" id="env-new-redirect" name="redirect-to" value="{{ $old['redirect-to'] ?? '' }}" placeholder="https://example.org">
                </div>
            @if (($dailyBackup ?? null) !== null)
                <div class="larena-field">
                    <input type="hidden" name="daily-backup" value="no">
                    <label for="env-new-daily-backup">
                        <input type="checkbox" id="env-new-daily-backup" name="daily-backup" value="yes" data-env-new-daily-backup @if ($dailyBackup) checked @endif>
                        {{ __('larena-env::admin.new_site.daily_backup') }}
                    </label>
                    <p>{{ __('larena-env::admin.new_site.daily_backup_hint') }}</p>
                </div>
            @endif
            @if ($owners !== null)
                <div class="larena-field">
                    <label for="env-new-owner">{{ __('larena-env::admin.new_site.owner') }}</label>
                    <select id="env-new-owner" name="owner" data-env-new-owner>
                        <option value="">{{ __('larena-env::admin.new_site.own_account') }}</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner }}" @if (($old['owner'] ?? null) === $owner) selected @endif>{{ $owner }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <p data-env-new-own-space>{{ __('larena-env::admin.new_site.in_your_space') }}</p>
            @endif
            <div class="larena-form-actions">
                <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
            </div>
        </form>
        <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
    </section>
@endsection
