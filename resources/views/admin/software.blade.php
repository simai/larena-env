@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.software.overview.title'))
@section('heading', __('larena-env::admin.software.overview.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.software.overview.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($software === null)
        <section class="larena-panel larena-panel-padded" data-env-software="unreadable">
            <p class="m-0" role="alert">{{ $reasonText }}</p>
        </section>
    @else
        {{-- The system's package updates first: what waits, how fresh the list is, and the two ways to install it. --}}
        <section class="larena-panel larena-panel-padded" data-env-system-updates="{{ $system->updates }}" aria-labelledby="env-system-updates">
            <div class="flex flex-wrap items-cross-center content-main-between g-1">
                <h2 id="env-system-updates" class="m-0">{{ __('larena-env::admin.software.overview.system_title') }}</h2>
                @if ($allows['refresh'])
                    <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="refresh">
                        @csrf
                        <input type="hidden" name="op" value="refresh">
                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.refresh') }}</button>
                    </form>
                @endif
            </div>
            <div class="flex flex-wrap items-cross-center g-1 m-y-1">
                <sf-badge appearance="tonal" @if ($system->updates > 0) status="warning" @else status="success" @endif
                    text="{{ __('larena-env::admin.software.overview.updates', ['count' => (string) $system->updates]) }}"></sf-badge>
                @if ($system->security > 0)
                    <sf-badge appearance="tonal" status="error" data-env-system-security-count="{{ $system->security }}"
                        text="{{ __('larena-env::admin.software.overview.security', ['count' => (string) $system->security]) }}"></sf-badge>
                @endif
                @if ($system->rebootRequired)
                    <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.reboot_badge') }}"></sf-badge>
                @endif
                @if ($system->indexAgeMinutes() !== null)
                    <span class="text-small color-on-surface-variant" data-env-system-age="{{ $system->indexAgeMinutes() }}">{{ __('larena-env::admin.software.overview.checked', ['minutes' => (string) $system->indexAgeMinutes()]) }}</span>
                @endif
            </div>
            @include('larena-env::admin.partials.system-updates', ['system' => $system])
            @if ($allows['update'] && $system->updates > 0)
                <div class="flex flex-wrap items-cross-center g-1 m-top-2">
                    @if ($system->security > 0)
                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="update-security">
                            @csrf
                            <input type="hidden" name="op" value="update">
                            <input type="hidden" name="security_only" value="yes">
                            <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.install_security') }}</button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="update-all">
                        @csrf
                        <input type="hidden" name="op" value="update">
                        <input type="hidden" name="security_only" value="no">
                        <button type="submit" class="larena-button{{ $system->security > 0 ? '' : ' larena-button-primary' }}" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.install_all') }}</button>
                    </form>
                </div>
                <p class="m-top-1 m-bottom-0 text-small color-on-surface-variant">{{ __('larena-env::admin.software.overview.update_note') }}</p>
            @endif
        </section>

        {{-- The components, one short row each: the version, an update waiting, the service, and «Установить» where the
             server can add it. --}}
        <section class="larena-panel" style="margin: 0;" data-env-software="on" aria-labelledby="env-software-components">
            <div class="p-2 p-bottom-1">
                <h2 id="env-software-components" class="m-0">{{ __('larena-env::admin.software.overview.components_title') }}</h2>
            </div>
            <ul class="m-0 p-0" style="list-style: none;">
                @foreach ($software->components as $component)
                    <li class="flex flex-wrap items-cross-center g-2 p-x-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-software-component="{{ $component->name }}">
                        <div class="grow min-w-0" style="flex-basis: 14rem;">
                            <div class="flex flex-wrap items-cross-center g-1">
                                <span>{{ __('larena-env::admin.software.overview.components.'.$component->name) }}</span>
                                @if (!$component->installed)
                                    <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.overview.not_installed') }}" data-env-software-absent></sf-badge>
                                @elseif ($component->service !== null)
                                    <sf-badge appearance="tonal" status="{{ $component->active === true ? 'success' : 'error' }}" @if ($component->active !== true) role="alert" @endif
                                        text="{{ $component->active === true ? __('larena-env::admin.software.overview.running') : __('larena-env::admin.software.overview.stopped') }}"></sf-badge>
                                @endif
                                @if ($component->update)
                                    <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.update_waiting') }}" data-env-software-update></sf-badge>
                                @endif
                            </div>
                            <div class="color-on-surface-variant text-small mono" style="overflow-wrap: anywhere;">
                                @if ($component->installed)
                                    {{ $component->version ?? '—' }}@if ($component->update && $component->candidate !== null) → {{ $component->candidate }}@endif
                                @elseif ($component->candidate !== null)
                                    {{ __('larena-env::admin.software.overview.available', ['version' => $component->candidate]) }}
                                @endif
                            </div>
                            @if ($component->name === 'node' && $component->sites !== [])
                                <div class="color-on-surface-variant text-small" style="overflow-wrap: anywhere;">{{ __('larena-env::admin.software.php.sites', ['sites' => implode(', ', $component->sites)]) }}</div>
                            @endif
                        </div>
                        @if ($allows['install'] && $component->installable)
                            <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="flex flex-wrap items-cross-center g-1 m-0" data-env-software-op="install-{{ $component->name }}">
                                @csrf
                                <input type="hidden" name="op" value="install">
                                <input type="hidden" name="name" value="{{ $component->name }}">
                                @if ($component->name !== 'redis')
                                    <label class="flex items-cross-center g-1 text-small">{{ __('larena-env::admin.software.overview.major') }}
                                        <input type="text" name="version" inputmode="numeric" pattern="[0-9]{2}" maxlength="2" size="3" style="min-height: 2.75rem; width: 4rem;"
                                            placeholder="{{ $component->name === 'node' ? '22' : '' }}" aria-describedby="env-software-major-{{ $component->name }}">
                                    </label>
                                    <span id="env-software-major-{{ $component->name }}" class="text-small color-on-surface-variant">{{ __('larena-env::admin.software.overview.major_hint_'.$component->name) }}</span>
                                @endif
                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.install') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="p-2 m-0 border-top-1 border-top-solid border-outline-variant color-on-surface-variant text-small">
                {{ __('larena-env::admin.software.overview.php_elsewhere') }} <a href="{{ route('larena.env.admin.software.php', [], false) }}">PHP</a>
            </p>
        </section>
    @endif
@endsection
