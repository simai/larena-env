@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.new_site.title'))
@section('heading', __('larena-env::admin.new_site.heading'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.new_site.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The way through: the kind and its fields here, then the plan in words, then the server makes the site. --}}
    <sf-steps class="m-bottom-2" current="0" aria-label="{{ __('larena-env::admin.new_site.steps_label') }}" data-env-new-steps
        items="{{ json_encode([['label' => __('larena-env::admin.new_site.steps.fill')], ['label' => __('larena-env::admin.new_site.steps.plan'), 'disabled' => true],
            ['label' => __('larena-env::admin.new_site.steps.make'), 'disabled' => true]], JSON_UNESCAPED_UNICODE) }}"></sf-steps>
    <section class="larena-panel larena-panel-padded" aria-labelledby="env-new-site">
        <h2 id="env-new-site" class="m-top-0 text-2">{{ __('larena-env::admin.new_site.profile') }}</h2>
        {{-- First what it runs, as tiles: each kind with what the server sets up; the fields below ask only what that kind
             takes. Utilities until the Framework's choice cards ship (msg-993e1e39151942c19b23). --}}
        <nav aria-labelledby="env-new-site" data-env-new-kinds>
            <ul class="grid g-1 grid-col-1 md:grid-col-2 xl:grid-col-4 m-0 p-0" style="list-style: none;">
                @foreach ($profiles as $profile)
                    @if (isset(($unavailable ?? [])[$profile]))
                        {{-- A kind none of whose ways the panel can lead on this server: shown with the reason, never a link. --}}
                        <li class="flex">
                            <div class="grow flex flex-col g-1/2 p-2 radius-2 border-1 border-dashed border-outline-variant color-on-surface-variant larena-control-floor" aria-disabled="true" data-env-profile-unavailable="{{ $profile }}">
                                <strong>{{ ($serverProfiles ?? null) !== null ? $serverProfiles[$profile]['label'] : __('larena-env::admin.new_site.profiles.'.$profile) }}</strong>
                                <span>{{ $unavailable[$profile] }}</span>
                            </div>
                        </li>
                        @continue
                    @endif
                    <li class="flex">
                        <a href="{{ route('larena.env.admin.sites.new', [], false) }}?profile={{ $profile }}" data-env-profile="{{ $profile }}" @if ($profile === ($kind ?? 'static')) aria-current="true" @endif
                            class="grow flex flex-col g-1/2 p-2 radius-2 border-1 border-solid {{ $profile === ($kind ?? 'static') ? 'border-primary bg-primary-container color-on-primary-container' : 'border-outline-variant color-on-surface' }} larena-control-floor"
                            style="text-decoration: none;">
                            <strong>{{ ($serverProfiles ?? null) !== null ? $serverProfiles[$profile]['label'] : __('larena-env::admin.new_site.profiles.'.$profile) }}</strong>
                            <span class="{{ $profile === ($kind ?? 'static') ? '' : 'color-on-surface-variant' }}" data-env-profile-hint="{{ $profile }}"
                                style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">{{ ($serverProfiles ?? null) !== null ? $serverProfiles[$profile]['hint'] : __('larena-env::admin.new_site.profile_hints.'.$profile) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </section>
    @if (($routes ?? null) !== null)
        {{-- How a site of this kind is made (simai-env 1.56.0): new, restored from a copy or from a repository; a way the
             server plans only, or the panel cannot lead yet, is shown with the reason. Then its steps, in order. --}}
        <section class="larena-panel larena-panel-padded m-top-2" aria-labelledby="env-new-site-route" data-env-new-routes>
            <h2 id="env-new-site-route" class="m-top-0 text-2">{{ __('larena-env::admin.routes.choose') }}</h2>
            <ul class="grid g-1 grid-col-1 md:grid-col-2 xl:grid-col-3 m-0 p-0" style="list-style: none;">
                @foreach ($routes as $one)
                    <li class="flex">
                        @if ($one['available'])
                            <a href="{{ route('larena.env.admin.sites.new', [], false) }}?profile={{ $kind }}&amp;route={{ $one['id'] }}" data-env-route="{{ $one['id'] }}" @if ($one['id'] === $route) aria-current="true" @endif
                                class="grow flex flex-col g-1/2 p-2 radius-2 border-1 border-solid {{ $one['id'] === $route ? 'border-primary bg-primary-container color-on-primary-container' : 'border-outline-variant color-on-surface' }} larena-control-floor"
                                style="text-decoration: none;">
                                <strong>{{ $one['label'] }}</strong>
                                <span class="{{ $one['id'] === $route ? '' : 'color-on-surface-variant' }}">{{ $one['hint'] }}</span>
                            </a>
                        @else
                            <div class="grow flex flex-col g-1/2 p-2 radius-2 border-1 border-dashed border-outline-variant color-on-surface-variant larena-control-floor" aria-disabled="true" data-env-route-unavailable="{{ $one['id'] }}">
                                <strong>{{ $one['label'] }}</strong>
                                <span>{{ $one['reason'] }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @foreach ($routes as $one)
                @if ($one['id'] === $route)
                    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.routes.steps_title') }}</h3>
                    <ol class="m-0" data-env-route-steps="{{ $one['id'] }}">
                        @foreach ($one['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                @endif
            @endforeach
        </section>
    @endif
    <section class="larena-panel larena-panel-padded m-top-2" aria-labelledby="env-new-site-fields">
        <h2 id="env-new-site-fields" class="m-top-0 text-2">{{ __('larena-env::admin.new_site.fields_title', ['kind' => ($serverProfiles ?? null) !== null ? $serverProfiles[$kind ?? 'static']['label'] : __('larena-env::admin.new_site.profiles.'.($kind ?? 'static'))]) }}</h2>
        {{-- Before the site is made: where its domain must lead, with the server's real address (block 4). --}}
        <p data-env-domain-point>{{ ($serverIp ?? null) !== null ? __('larena-env::admin.point_domain.ip', ['ip' => $serverIp]) : __('larena-env::admin.point_domain.unknown') }}</p>
        @if (($owners ?? null) !== null && $owners !== [])
            <p class="color-on-surface-variant" data-env-new-owner-explain>{{ __('larena-env::admin.new_site.owner_explain') }}</p>
        @endif
        {{-- The fields the chosen kind asks, in the Framework property view over the new site's port: record.create plans
             the site and leads to that plan. The plain form is the view's fallback, hidden once the view has read its fields. --}}
        <div id="admin-record-view" data-larena-close-url="{{ route('larena.env.admin.index', [], false) }}">
        @if (($propertyView ?? null) !== null)
            <sf-view-properties collection="{{ $propertyView['collection'] ?? 'env.new_site.'.($kind ?? 'static') }}" mode="create" data-env-new-site-view
                aria-label="{{ __('larena-env::admin.new_site.heading') }}" empty-text="—"
                data-larena-property-port="{{ $propertyView['port'] }}" data-larena-property-scope="{{ $propertyView['scope'] }}"></sf-view-properties>
        @endif
        {{-- The fields the chosen kind asks: the server's own description of it where it lists its kinds (Panel plan 5.5). --}}
        <form @if (($propertyView ?? null) !== null) data-larena-record-fallback @endif method="post" action="{{ route('larena.env.admin.sites.plan', [], false) }}" class="larena-form" data-env-new-site>
            @csrf
            <div class="larena-field">
                <label for="env-new-domain">{{ __('larena-env::admin.new_site.domain') }}</label>
                <input type="text" id="env-new-domain" name="domain" value="{{ $old['domain'] ?? '' }}" required autocomplete="off" placeholder="example.com">
            </div>
            <input type="hidden" name="profile" value="{{ $kind ?? 'static' }}" data-env-new-profile="{{ $kind ?? 'static' }}">
            @if (($route ?? null) !== null)
                {{-- A route's own fields (simai-env 1.56.0), in the order the server lists them: site:add takes some, a later
                     step the rest (WordPress's title and administrator, a repository); the site's card offers those again at
                     their step. A file the route puts on the server is asked there too, after the site is made. --}}
                <input type="hidden" name="route" value="{{ $route }}" data-env-new-route="{{ $route }}">
                @foreach ($routeFields as $field)
                    <div class="larena-field" data-env-route-field="{{ $field['name'] }}"@if ($field['later']) data-env-route-later @endif>
                        <label for="env-new-route-{{ $field['name'] }}">{{ $field['label'] }}</label>
                        @if ($field['kind'] === 'choice')
                            <select id="env-new-route-{{ $field['name'] }}" name="{{ $field['name'] }}" @if ($field['required']) required @endif>
                                @foreach ($field['values'] as $choice)
                                    <option value="{{ $choice['value'] }}" @if (($routeOld[$field['name']] ?? $field['default']) === $choice['value']) selected @endif>{{ $choice['label'] }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="{{ $field['kind'] === 'url' ? 'url' : 'text' }}" id="env-new-route-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                value="{{ $routeOld[$field['name']] ?? $field['default'] ?? '' }}" autocomplete="off" @if ($field['required']) required @endif>
                        @endif
                    </div>
                @endforeach
                @foreach ($routeFiles as $file)
                    <p class="color-on-surface-variant" data-env-route-file>{{ __('larena-env::admin.routes.file_later', ['file' => $file]) }}</p>
                @endforeach
            @else
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
            @if (in_array('bitrix', ($serverProfiles ?? null) !== null ? ($serverProfiles[$kind ?? 'static']['groups'] ?? []) : [], true))
                {{-- A 1C-Bitrix site's installer in its root (simai-env 1.33.0): none until needed, a new install or a restore. --}}
                <div class="larena-field" data-env-field="bitrix-files">
                    <label for="env-new-bitrix-files">{{ __('larena-env::admin.new_site.bitrix_files') }}</label>
                    <select id="env-new-bitrix-files" name="bitrix-files">
                        @foreach (\Larena\Env\Http\Controllers\NewSiteController::BITRIX_FILES as $choice)
                            <option value="{{ $choice }}" @if (($old['bitrix-files'] ?? 'none') === $choice) selected @endif>{{ __('larena-env::admin.new_site.bitrix_files_choices.'.$choice) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @endif
            @if (($dailyBackup ?? null) !== null && (($serverProfiles ?? null) !== null ? ($serverProfiles[$kind ?? 'static']['backup'] ?? false) : \Larena\Env\Http\Controllers\NewSiteController::backsUp($kind ?? 'static', null)))
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
        </div>
    </section>
@endsection
