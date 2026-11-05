@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.software.overview.title'))
@section('heading', __('larena-env::admin.software.overview.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.software.overview.description'))

@section('content')
    @include('larena-env::admin.partials.notes')


    {{-- Updates in one short line (the panel's UX audit): the panel, simai-env and the system, each with its state; the
         details and the buttons open below it, by themselves only when something waits. --}}
    @if (isset($plans) || $system !== null)
        <section class="larena-panel m-bottom-3" style="margin-top: 0;" id="update" data-env-software-updates-summary aria-labelledby="env-software-updates">
            <details @if (\Larena\Env\Software\SoftwareScreen::updatesWaiting($system, $updateStates ?? [])) open @endif data-env-software-updates-details>
                <summary class="flex flex-wrap items-cross-center g-1 p-2" style="cursor: pointer; min-height: 2.75rem;">
                    <h2 id="env-software-updates" class="m-0 text-2" style="margin-inline-end: .5rem;">{{ __('larena-env::admin.software.overview.'.'updates_title') }}</h2>
                    @if (isset($plans) && $supported)
                        @foreach (array_keys($plans) as $target)
                            <sf-badge appearance="tonal" status="{{ ['unknown' => 'error', 'blocked' => 'warning', 'available' => 'info', 'current' => 'success'][$updateStates[$target] ?? 'unknown'] }}" data-env-software-update-badge="{{ $target }}"
                                text="{{ __('larena-env::admin.update.targets.'.$target) }}: {{ mb_strtolower(__('larena-env::admin.update.states.'.($updateStates[$target] ?? 'unknown'))) }}"></sf-badge>
                        @endforeach
                    @endif
                    @if ($system !== null)
                        <sf-badge appearance="tonal" @if ($system->updates > 0) status="warning" @else status="success" @endif data-env-software-update-badge="system"
                            text="{{ __('larena-env::admin.software.overview.'.'system_short') }}: {{ $system->updates > 0 ? mb_strtolower(__('larena-env::admin.software.overview.'.'updates', ['count' => (string) $system->updates])) : mb_strtolower(__('larena-env::admin.software.overview.'.'no_updates_short')) }}"></sf-badge>
                        @if ($system->security > 0)
                            <sf-badge appearance="tonal" status="error" text="{{ __('larena-env::admin.software.overview.'.'security', ['count' => (string) $system->security]) }}"></sf-badge>
                        @endif
                        @if ($system->rebootRequired)
                            <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.'.'reboot_badge') }}"></sf-badge>
                        @endif
                    @endif
                    <span class="text-small color-on-surface-variant" style="margin-inline-start: auto;">{{ __('larena-env::admin.software.overview.'.'updates_more') }}</span>
                </summary>
                <div class="p-x-2 p-bottom-2">
                    @isset($plans)
                        <div class="m-bottom-2" data-env-software-updates aria-label="{{ __('larena-env::admin.software.overview.'.'panel_title') }}">
                            @include('larena-env::admin.partials.update-targets')
                        </div>
                    @endisset
                    @if ($system !== null)
                        {{-- The system's package updates: what waits, how fresh the list is, and the two ways to install it. --}}
                        <section class="larena-panel larena-panel-padded" style="margin: 0;" data-env-system-updates="{{ $system->updates }}" aria-labelledby="env-system-updates">
                            <div class="flex flex-wrap items-cross-center content-main-between g-1">
                                <h3 id="env-system-updates" class="m-0 text-2">{{ __('larena-env::admin.software.overview.'.'system_title') }}</h3>
                                @if ($allows['refresh'])
                                    <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="refresh">
                                        @csrf
                                        <input type="hidden" name="op" value="refresh">
                                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.'.'refresh') }}</button>
                                    </form>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-cross-center g-1 m-y-1">
                                @if ($system->security > 0)
                                    <span class="sr-only" data-env-system-security-count="{{ $system->security }}">{{ __('larena-env::admin.software.overview.'.'security', ['count' => (string) $system->security]) }}</span>
                                @endif
                                @if ($system->indexAgeMinutes() !== null)
                                    <span class="text-small color-on-surface-variant" data-env-system-age="{{ $system->indexAgeMinutes() }}">{{ __('larena-env::admin.software.overview.'.'checked', ['age' => (string) $system->indexAge()]) }}</span>
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
                                            <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.'.'install_security') }}</button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="update-all">
                                        @csrf
                                        <input type="hidden" name="op" value="update">
                                        <input type="hidden" name="security_only" value="no">
                                        <button type="submit" class="larena-button{{ $system->security > 0 ? '' : ' larena-button-primary' }}" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.'.'install_all') }}</button>
                                    </form>
                                </div>
                                <p class="m-top-1 m-bottom-0 text-small color-on-surface-variant">{{ __('larena-env::admin.software.overview.'.'update_note') }}</p>
                            @endif
                        </section>
                    @endif
                </div>
            </details>
        </section>
    @endif

    @if ($software === null)
        <section class="larena-panel larena-panel-padded" data-env-software="unreadable">
            <p class="m-0" role="alert">{{ $reasonText }}</p>
        </section>
    @else
        {{-- The programs as one table, grouped by what they do (the panel's UX audit, after FastPanel's list): the name and
             what it is for, its state, its version, and a menu of what may be done to it. PHP is a row here too and
             opens its own screen. --}}
        <section class="larena-panel" style="margin: 0;" data-env-software="on" aria-labelledby="env-software-components">
            <h2 id="env-software-components" class="sr-only">{{ __('larena-env::admin.software.overview.'.'components_title') }}</h2>
            <div class="larena-table-scroll">
                <table class="larena-table larena-table-stack" data-env-software-table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('larena-env::admin.software.overview.'.'columns.name') }}</th>
                            <th scope="col" style="white-space: nowrap;">{{ __('larena-env::admin.software.overview.'.'columns.state') }}</th>
                            <th scope="col" style="white-space: nowrap;">{{ __('larena-env::admin.software.overview.'.'columns.version') }}</th>
                            <th scope="col"><span class="larena-visually-hidden">{{ __('larena-env::admin.software.overview.'.'columns.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (\Larena\Env\Software\SoftwareScreen::groups($software, $withPhp ?? false) as $group)
                            <tr data-env-software-group="{{ $group['key'] }}">
                                <th scope="colgroup" colspan="4">{{ __('larena-env::admin.software.overview.groups.'.$group['key']) }}</th>
                            </tr>
                            @if ($group['php'])
                                <tr data-env-software-php="{{ ($phpRow ?? null) === null ? 'unknown' : $phpRow['count'] }}">
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.name') }}" style="min-width: 12rem;">
                                        <a href="{{ route('larena.env.admin.software.php', [], false) }}">PHP</a>
                                        <div class="text-small color-on-surface-variant">{{ __('larena-env::admin.software.overview.purpose.php') }}</div>
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.state') }}">
                                        <div class="flex flex-wrap items-cross-center g-1">
                                            @if (($phpRow ?? null) === null)
                                                <span class="color-on-surface-variant">—</span>
                                            @elseif ($phpRow['count'] === 0)
                                                <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.overview.not_installed') }}"></sf-badge>
                                            @else
                                                <sf-badge appearance="tonal" status="{{ $phpRow['stopped'] === 0 ? 'success' : 'error' }}" @if ($phpRow['stopped'] > 0) role="alert" @endif
                                                    text="{{ $phpRow['stopped'] === 0 ? __('larena-env::admin.software.overview.running') : __('larena-env::admin.software.overview.php_stopped', ['count' => (string) $phpRow['stopped']]) }}"></sf-badge>
                                                @if ($phpRow['fixes'])
                                                    <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.update_waiting') }}"></sf-badge>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.version') }}" class="mono text-small">{{ ($phpRow ?? null) === null || $phpRow['count'] === 0 ? '—' : $phpRow['versions'] }}</td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.actions') }}">
                                        <a class="flex items-cross-center" style="min-height: 2.75rem; white-space: nowrap;" href="{{ route('larena.env.admin.software.php', [], false) }}" data-env-software-php-open>{{ __('larena-env::admin.software.overview.php_open') }}</a>
                                    </td>
                                </tr>
                            @endif
                            @foreach ($group['components'] as $component)
                                <tr data-env-software-component="{{ $component->name }}" data-env-software-state="{{ $component->installed ? 'installed' : 'absent' }}">
                                    <td data-label="{{ __('larena-env::admin.software.overview.'.'columns.name') }}" style="min-width: 12rem;">
                                        <span>{{ __('larena-env::admin.software.overview.components.'.$component->name) }}</span>
                                        <div class="text-small color-on-surface-variant">{{ __('larena-env::admin.software.overview.'.'purpose.'.$component->name) }}</div>
                                        @if ($component->name === 'node' && $component->sites !== [])
                                            <div class="text-small color-on-surface-variant" style="overflow-wrap: anywhere;">{{ __('larena-env::admin.software.php.sites', ['sites' => implode(', ', $component->sites)]) }}</div>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.'.'columns.state') }}">
                                        <div class="flex flex-wrap items-cross-center g-1">
                                            @if (!$component->installed)
                                                <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.overview.'.'not_installed') }}" data-env-software-absent></sf-badge>
                                            @elseif ($component->service !== null)
                                                <sf-badge appearance="tonal" status="{{ $component->active === true ? 'success' : 'error' }}" @if ($component->active !== true) role="alert" @endif
                                                    text="{{ $component->active === true ? __('larena-env::admin.software.overview.'.'running') : __('larena-env::admin.software.overview.'.'stopped') }}"></sf-badge>
                                            @else
                                                <sf-badge appearance="tonal" status="success" text="{{ __('larena-env::admin.software.overview.'.'installed') }}"></sf-badge>
                                            @endif
                                            @if ($component->update)
                                                <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.'.'update_waiting') }}" data-env-software-update></sf-badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.'.'columns.version') }}" class="mono text-small" style="overflow-wrap: anywhere;">
                                        @if ($component->installed)
                                            {{ $component->version ?? '—' }}@if ($component->update && $component->candidate !== null) → {{ $component->candidate }}@endif
                                        @elseif ($component->candidate !== null)
                                            <span class="color-on-surface-variant">{{ __('larena-env::admin.software.overview.'.($component->installable ? 'available' : 'version'), ['version' => $component->candidate]) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.'.'columns.actions') }}">
                                        @if (($allows['install'] && $component->installable) || ($allows['update'] && $component->update))
                                            <details data-env-software-actions="{{ $component->name }}">
                                                <summary class="flex items-cross-center" style="min-height: 2.75rem; cursor: pointer; white-space: nowrap;"
                                                    aria-label="{{ __('larena-env::admin.software.overview.'.'actions_of', ['name' => __('larena-env::admin.software.overview.components.'.$component->name)]) }}">{{ __('larena-env::admin.software.overview.'.'columns.actions') }}</summary>
                                                <div class="flex flex-column g-1 p-y-1" style="min-width: min(18rem, 80vw);">
                                                    @if ($allows['install'] && $component->installable)
                                                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="flex flex-wrap items-cross-end g-1 m-0" data-env-software-op="install-{{ $component->name }}">
                                                            @csrf
                                                            <input type="hidden" name="op" value="install">
                                                            <input type="hidden" name="name" value="{{ $component->name }}">
                                                            @if ($component->name !== 'redis')
                                                                <label class="flex flex-column text-small">{{ __('larena-env::admin.software.overview.'.'major') }}
                                                                    <input type="text" name="version" inputmode="numeric" pattern="[0-9]{2}" maxlength="2" size="3" style="min-height: 2.75rem; width: 4rem;"
                                                                        placeholder="{{ $component->name === 'node' ? '22' : '' }}" aria-describedby="env-software-major-{{ $component->name }}">
                                                                </label>
                                                            @endif
                                                            <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.'.'install') }}</button>
                                                            @if ($component->name !== 'redis')
                                                                <span id="env-software-major-{{ $component->name }}" class="text-small color-on-surface-variant" style="flex-basis: 100%;">{{ __('larena-env::admin.software.overview.'.'major_hint_'.$component->name) }}</span>
                                                            @endif
                                                        </form>
                                                    @endif
                                                    @if ($allows['update'] && $component->update)
                                                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="update-{{ $component->name }}">
                                                            @csrf
                                                            <input type="hidden" name="op" value="update">
                                                            <input type="hidden" name="security_only" value="no">
                                                            <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.'.'update_with_system') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
