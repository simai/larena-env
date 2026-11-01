@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.new_site.title'))
@section('heading', __('larena-env::admin.new_site.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.new_site.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    <section class="larena-panel larena-panel-padded" aria-labelledby="env-new-site">
        <h2 id="env-new-site">{{ __('larena-env::admin.new_site.heading') }}</h2>
        {{-- First what it runs, each kind with what the server sets up; the form below asks only what that kind takes. --}}
        <nav aria-labelledby="env-new-kind" data-env-new-kinds>
            <h3 id="env-new-kind">{{ __('larena-env::admin.new_site.profile') }}</h3>
            <ul class="larena-list" style="list-style: none; padding-inline-start: 0; display: grid; gap: .5rem;">
                @foreach ($profiles as $profile)
                    <li>
                        <a href="{{ route('larena.env.admin.sites.new', [], false) }}?profile={{ $profile }}" data-env-profile="{{ $profile }}" @if ($profile === ($kind ?? 'static')) aria-current="true" @endif
                            style="display: block; min-height: 2.75rem; padding: .625rem .875rem; border: 1px solid {{ $profile === ($kind ?? 'static') ? 'var(--sf-primary)' : 'var(--larena-line)' }}; border-radius: .625rem; text-decoration: none; color: inherit;{{ $profile === ($kind ?? 'static') ? ' background: var(--sf-primary-container); color: var(--sf-on-primary-container);' : '' }}">
                            @if (($serverProfiles ?? null) !== null)
                                <strong>{{ $serverProfiles[$profile]['label'] }}</strong><br>
                                <span data-env-profile-hint="{{ $profile }}">{{ $serverProfiles[$profile]['hint'] }}</span>
                            @else
                                <strong>{{ __('larena-env::admin.new_site.profiles.'.$profile) }}</strong><br>
                                <span data-env-profile-hint="{{ $profile }}">{{ __('larena-env::admin.new_site.profile_hints.'.$profile) }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
        {{-- The fields the chosen kind asks: the server's own description of it where it lists its kinds (Panel plan 5.5). --}}
        <form method="post" action="{{ route('larena.env.admin.sites.plan', [], false) }}" class="larena-form" data-env-new-site>
            @csrf
            <div class="larena-field">
                <label for="env-new-domain">{{ __('larena-env::admin.new_site.domain') }}</label>
                <input type="text" id="env-new-domain" name="domain" value="{{ $old['domain'] ?? '' }}" required autocomplete="off" placeholder="example.com">
            </div>
            <input type="hidden" name="profile" value="{{ $kind ?? 'static' }}" data-env-new-profile="{{ $kind ?? 'static' }}">
            @if (in_array('php', ($serverProfiles ?? null) !== null ? ($serverProfiles[$kind ?? 'static']['groups'] ?? []) : \Larena\Env\Http\Controllers\NewSiteController::groupsOf($kind ?? 'static', null), true))
                <div class="larena-field" data-env-field="php">
                    <label for="env-new-php">{{ __('larena-env::admin.new_site.php') }}</label>
                    <select id="env-new-php" name="php">
                        <option value="">{{ __('larena-env::admin.fields.default') }}</option>
                        @foreach ($phpVersions as $version)
                            <option value="{{ $version }}" @if (($old['php'] ?? null) === $version) selected @endif>{{ $version }}</option>
                        @endforeach
                    </select>
                    <p>{{ __('larena-env::admin.new_site.php_hint') }}</p>
                </div>
            @endif
            @if (in_array('database', ($serverProfiles ?? null) !== null ? ($serverProfiles[$kind ?? 'static']['groups'] ?? []) : \Larena\Env\Http\Controllers\NewSiteController::groupsOf($kind ?? 'static', null), true))
                <div class="larena-field" data-env-field="database">
                    <label for="env-new-db">{{ __('larena-env::admin.new_site.database') }}</label>
                    <select id="env-new-db" name="create-db">
                        <option value="no">{{ __('larena-env::admin.no') }}</option>
                        <option value="yes" @if (($old['create-db'] ?? (in_array($kind ?? 'static', ['laravel', 'wordpress'], true) ? 'yes' : null)) === 'yes') selected @endif>{{ __('larena-env::admin.yes') }}</option>
                    </select>
                    <p>{{ __('larena-env::admin.new_site.database_hint') }}</p>
                </div>
                <div class="larena-field" data-env-field="engine">
                    <label for="env-new-engine">{{ __('larena-env::admin.new_site.engine') }}</label>
                    <select id="env-new-engine" name="db-engine">
                        <option value="mysql">MySQL</option>
                        <option value="pgsql" @if (($old['db-engine'] ?? null) === 'pgsql') selected @endif>PostgreSQL</option>
                    </select>
                </div>
            @endif
            @if (in_array('redirect', ($serverProfiles ?? null) !== null ? ($serverProfiles[$kind ?? 'static']['groups'] ?? []) : \Larena\Env\Http\Controllers\NewSiteController::groupsOf($kind ?? 'static', null), true))
                <div class="larena-field" data-env-field="redirect">
                    <label for="env-new-redirect">{{ __('larena-env::admin.new_site.redirect_to') }}</label>
                    <input type="url" id="env-new-redirect" name="redirect-to" value="{{ $old['redirect-to'] ?? '' }}" placeholder="https://example.org">
                </div>
            @endif
            @if (in_array('proxy', ($serverProfiles ?? null) !== null ? ($serverProfiles[$kind ?? 'static']['groups'] ?? []) : \Larena\Env\Http\Controllers\NewSiteController::groupsOf($kind ?? 'static', null), true))
                {{-- A reverse proxy forwards to a port of this same server only (127.0.0.1): nothing outside it. --}}
                <div class="larena-field" data-env-field="proxy">
                    <label for="env-new-upstream-port">{{ __('larena-env::admin.new_site.upstream_port') }}</label>
                    <input type="number" id="env-new-upstream-port" name="upstream-port" value="{{ $old['upstream-port'] ?? '' }}" min="1" max="65535" inputmode="numeric" required placeholder="3000">
                    <p>{{ __('larena-env::admin.new_site.upstream_port_hint') }}</p>
                </div>
                <div class="larena-field" data-env-field="proxy-path">
                    <label for="env-new-upstream-path">{{ __('larena-env::admin.new_site.upstream_path') }}</label>
                    <input type="text" id="env-new-upstream-path" name="upstream-path" value="{{ $old['upstream-path'] ?? '' }}" autocomplete="off" placeholder="/">
                </div>
                <div class="larena-field" data-env-field="websockets">
                    <input type="hidden" name="websockets" value="no">
                    <label for="env-new-websockets">
                        <input type="checkbox" id="env-new-websockets" name="websockets" value="yes" @if (($old['websockets'] ?? null) === 'yes') checked @endif>
                        {{ __('larena-env::admin.new_site.websockets') }}
                    </label>
                </div>
            @endif
            @if (($dailyBackup ?? null) !== null && ($kind ?? '') !== 'redirect')
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
