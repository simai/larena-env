@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.software.php.title'))
@section('heading', __('larena-env::admin.software.php.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.software.php.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The server's PHP versions (simai-env 1.37.0), the system level's: one short row each, with its sites, the fixes
         waiting and what may be done to it; a removal only where the server says the version is free. --}}
    <section class="larena-panel" style="margin: 0;" data-env-php="{{ $php === null ? 'unreadable' : 'on' }}" aria-labelledby="env-php">
        {{-- The page's own block: its name for screen readers only, the page title already says it (UX audit LAY-02). --}}
        <h2 id="env-php" class="sr-only">{{ __('larena-env::admin.software.php.versions') }}</h2>
        @if ($php === null)
            <p class="p-x-2 p-bottom-2 m-0" role="alert">{{ $reasonText }}</p>
        @elseif ($php->versions === [])
            <p class="p-x-2 p-bottom-2 m-0">{{ __('larena-env::admin.software.php.none') }}</p>
        @else
            <ul class="m-0 p-0" style="list-style: none;">
                @foreach ($php->versions as $version)
                    <li class="flex flex-wrap items-cross-center g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-php-version="{{ $version->version }}">
                        <div class="grow min-w-0" style="flex-basis: 14rem;">
                            <div class="flex flex-wrap items-cross-center g-1">
                                <span class="mono">PHP {{ $version->version }}</span>
                                <sf-badge appearance="tonal" status="{{ $version->fpmActive ? 'success' : 'error' }}" @if (!$version->fpmActive) role="alert" @endif
                                    text="{{ $version->fpmActive ? __('larena-env::admin.software.php.fpm_running') : __('larena-env::admin.software.php.fpm_stopped') }}"></sf-badge>
                                @if ($version->native)
                                    <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.php.native') }}" data-env-php-native></sf-badge>
                                @endif
                                @if ($version->cliDefault)
                                    <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.php.cli_default') }}" data-env-php-cli></sf-badge>
                                @endif
                                @if ($version->updates !== [])
                                    <sf-badge appearance="tonal" status="warning" data-env-php-updates="{{ count($version->updates) }}"
                                        text="{{ __('larena-env::admin.software.php.updates', ['count' => (string) count($version->updates)]) }}"></sf-badge>
                                @endif
                            </div>
                            <div class="color-on-surface-variant text-small" style="overflow-wrap: anywhere;">
                                <span data-env-php-sites="{{ count($version->sites) }}">{{ $version->sites === [] ? __('larena-env::admin.software.php.no_sites') : __('larena-env::admin.software.php.sites', ['sites' => implode(', ', $version->sites)]) }}</span>
                                @if ($version->packageVersion !== null)
                                    <span class="mono"> · {{ $version->packageVersion }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-wrap items-cross-center g-1">
                            @if ($allows['update'])
                                <form method="post" action="{{ route('larena.env.admin.software.php.plan', [], false) }}" class="m-0" data-env-php-op="update">
                                    @csrf
                                    <input type="hidden" name="op" value="update">
                                    <input type="hidden" name="php" value="{{ $version->version }}">
                                    <button type="submit" class="larena-button{{ $version->updates !== [] ? ' larena-button-primary' : '' }} larena-control-floor">{{ __('larena-env::admin.software.php.update') }}</button>
                                </form>
                            @endif
                            @if ($allows['remove'] && $version->removable)
                                <form method="post" action="{{ route('larena.env.admin.software.php.plan', [], false) }}" class="m-0" data-env-php-op="remove">
                                    @csrf
                                    <input type="hidden" name="op" value="remove">
                                    <input type="hidden" name="php" value="{{ $version->version }}">
                                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.software.php.remove') }}</button>
                                </form>
                            @endif
                            <a class="flex items-cross-center p-x-1 larena-control-floor" href="{{ route('larena.env.admin.software.php', ['extensions' => $version->version], false) }}#env-php-extensions"
                                data-env-php-extensions-link="{{ $version->version }}" @if ($extensionsOf === $version->version) aria-current="true" @endif>{{ __('larena-env::admin.software.php.extensions') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="p-2 m-0 border-top-1 border-top-solid border-outline-variant color-on-surface-variant text-small m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.software.php.how_to_move')])</div>
        @endif
    </section>

    @if ($php !== null && $allows['install'])
        <section class="larena-panel larena-panel-padded" data-env-php-install aria-labelledby="env-php-install">
            <h2 id="env-php-install" class="text-2">{{ __('larena-env::admin.software.php.install_title') }}</h2>
            @if ($php->installable === [])
                <p class="m-0" data-env-php-installable="none">{{ __('larena-env::admin.software.php.nothing_to_install') }}</p>
            @else
                <form method="post" action="{{ route('larena.env.admin.software.php.plan', [], false) }}" class="larena-form" data-env-php-op="install">
                    @csrf
                    <input type="hidden" name="op" value="install">
                    <div class="larena-field">
                        <label for="env-php-install-version">{{ __('larena-env::admin.software.php.version') }}</label>
                        <select class="larena-control-floor" id="env-php-install-version" name="php">
                            @foreach ($php->installable as $installable)
                                <option value="{{ $installable }}">PHP {{ $installable }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-field">
                        <label class="flex items-cross-center g-1"><input type="checkbox" name="include_common" value="1" checked> {{ __('larena-env::admin.software.php.include_common') }}</label>
                    </div>
                    <p class="m-0 color-on-surface-variant text-small">{{ __('larena-env::admin.software.php.install_note') }}</p>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                </form>
            @endif
        </section>
    @endif

    @if ($extensionsOf !== null)
        <section class="larena-panel larena-panel-padded" data-env-php-extensions="{{ $extensionsOf }}" aria-labelledby="env-php-extensions">
            <h2 id="env-php-extensions" class="text-2">{{ __('larena-env::admin.software.php.extensions_of', ['php' => $extensionsOf]) }}</h2>
            @if ($extensions === null)
                <p class="m-0" role="alert">{{ __('larena-env::admin.software.php.extensions_unreadable') }}</p>
            @else
                <ul class="m-0 p-0 grid g-x-3 grid-col-1 md:grid-col-2" style="list-style: none;">
                    @foreach ($extensions as $extension)
                        <li class="flex items-cross-center content-main-between g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-php-extension="{{ $extension->name }}">
                            <span class="mono min-w-0" style="overflow-wrap: anywhere;">{{ $extension->name }}</span>
                            <span class="flex items-cross-center g-1">
                                <sf-badge appearance="tonal" @if ($extension->enabled) status="success" @endif text="{{ $extension->enabled ? __('larena-env::admin.runtime.on') : __('larena-env::admin.runtime.off') }}"></sf-badge>
                                @if ($allows['extension'])
                                    <form method="post" action="{{ route('larena.env.admin.software.php.plan', [], false) }}" class="m-0" data-env-php-op="extension">
                                        @csrf
                                        <input type="hidden" name="op" value="extension">
                                        <input type="hidden" name="php" value="{{ $extensionsOf }}">
                                        <input type="hidden" name="name" value="{{ $extension->name }}">
                                        <input type="hidden" name="enabled" value="{{ $extension->enabled ? 'no' : 'yes' }}">
                                        <button type="submit" class="larena-button larena-control-floor" aria-label="{{ __('larena-env::admin.software.php.operations.extension_'.($extension->enabled ? 'no' : 'yes'), ['php' => $extensionsOf, 'name' => $extension->name]) }}">
                                            {{ $extension->enabled ? __('larena-env::admin.software.php.turn_off') : __('larena-env::admin.software.php.turn_on') }}</button>
                                    </form>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
@endsection
