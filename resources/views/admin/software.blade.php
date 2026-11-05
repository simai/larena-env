@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.software.overview.title'))
@section('heading', __('larena-env::admin.software.overview.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.software.overview.description'))

@section('content')
    @include('larena-env::admin.partials.notes')


    {{-- Updates in one short line (the panel's UX audit): the panel, simai-env and the system, each with its state as a
         badge; the details and the buttons open below it on a click, so the programs come first. --}}
    @if (isset($plans) || $system !== null)
        <section class="larena-panel m-bottom-3" style="margin-top: 0;" id="update" data-env-software-updates-summary aria-labelledby="env-software-updates">
            <details data-env-software-updates-details>
                <summary class="flex flex-wrap items-cross-center g-1 p-2" style="cursor: pointer; min-height: 2.75rem;">
                    <h2 id="env-software-updates" class="m-0 text-2" style="margin-inline-end: .5rem;">{{ __('larena-env::admin.software.overview.updates_title') }}</h2>
                    @if (isset($plans) && $supported)
                        @foreach (array_keys($plans) as $target)
                            <sf-badge appearance="tonal" status="{{ ['unknown' => 'error', 'blocked' => 'warning', 'available' => 'info', 'current' => 'success'][$updateStates[$target] ?? 'unknown'] }}" data-env-software-update-badge="{{ $target }}"
                                text="{{ __('larena-env::admin.update.targets.'.$target) }}: {{ mb_strtolower(__('larena-env::admin.update.states.'.($updateStates[$target] ?? 'unknown'))) }}"></sf-badge>
                        @endforeach
                    @endif
                    @if ($system !== null)
                        <sf-badge appearance="tonal" @if ($system->updates > 0) status="warning" @else status="success" @endif data-env-software-update-badge="system"
                            text="{{ __('larena-env::admin.software.overview.system_short') }}: {{ $system->updates > 0 ? mb_strtolower(__('larena-env::admin.software.overview.updates', ['count' => (string) $system->updates])) : mb_strtolower(__('larena-env::admin.software.overview.no_updates_short')) }}"></sf-badge>
                        @if ($system->security > 0)
                            <sf-badge appearance="tonal" status="error" text="{{ __('larena-env::admin.software.overview.security', ['count' => (string) $system->security]) }}"></sf-badge>
                        @endif
                        @if ($system->rebootRequired)
                            <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.reboot_badge') }}"></sf-badge>
                        @endif
                    @endif
                    <span class="text-small color-on-surface-variant" style="margin-inline-start: auto;">{{ __('larena-env::admin.software.overview.updates_more') }}</span>
                </summary>
                <div class="p-x-2 p-bottom-2">
                    @if (isset($plans) && (!$supported || \Larena\Env\Software\SoftwareScreen::plansToShow($plans) !== []))
                        <div class="m-bottom-2" data-env-software-updates aria-label="{{ __('larena-env::admin.software.overview.panel_title') }}">
                            @include('larena-env::admin.partials.update-targets', ['plans' => $supported ? \Larena\Env\Software\SoftwareScreen::plansToShow($plans) : $plans])
                        </div>
                    @endif
                    @if ($system !== null)
                        {{-- The system's package updates: what waits, how fresh the list is, and the two ways to install it. --}}
                        <section class="larena-panel larena-panel-padded" style="margin: 0;" data-env-system-updates="{{ $system->updates }}" aria-labelledby="env-system-updates">
                            <div class="flex flex-wrap items-cross-center content-main-between g-1">
                                <h3 id="env-system-updates" class="m-0 text-2">{{ __('larena-env::admin.software.overview.system_title') }}</h3>
                                @if ($allows['refresh'])
                                    <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="refresh">
                                        @csrf
                                        <input type="hidden" name="op" value="refresh">
                                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.refresh') }}</button>
                                    </form>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-cross-center g-1 m-y-1">
                                @if ($system->security > 0)
                                    <span class="sr-only" data-env-system-security-count="{{ $system->security }}">{{ __('larena-env::admin.software.overview.security', ['count' => (string) $system->security]) }}</span>
                                @endif
                                @if ($system->indexAgeMinutes() !== null)
                                    <span class="text-small color-on-surface-variant" data-env-system-age="{{ $system->indexAgeMinutes() }}">{{ __('larena-env::admin.software.overview.checked', ['age' => (string) $system->indexAge()]) }}</span>
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
        {{-- The programs as one table, grouped by what they do (the panel's UX audit, after FastPanel's list of
             applications): the name and what it is for, its state, its version, and a menu of what may be done to it
             (simai-env 1.43.0's catalogue: install, update alone, remove, or why not). PHP is a row and opens its own
             screen. Filters and the search are a plain form, so a link keeps them. --}}
        <section class="larena-panel" style="margin: 0; overflow: visible;" data-env-software="on" aria-labelledby="env-software-components">
            <h2 id="env-software-components" class="sr-only">{{ __('larena-env::admin.software.overview.components_title') }}</h2>
            <form method="get" action="{{ route('larena.env.admin.software', [], false) }}" class="flex flex-wrap items-cross-center g-1 p-2 m-0" role="search" data-env-software-filters>
                <nav class="flex flex-wrap items-cross-center g-1" aria-label="{{ __('larena-env::admin.software.overview.filters.label') }}">
                    @foreach (\Larena\Env\Software\SoftwareScreen::counts($software) as $key => $count)
                        <a href="{{ route('larena.env.admin.software', [], false) }}{{ $key === 'all' ? '' : '?show='.$key }}{{ ($query ?? '') !== '' ? ($key === 'all' ? '?' : '&amp;').'q='.rawurlencode($query) : '' }}"
                            class="larena-button{{ ($filter ?? 'all') === $key ? ' larena-button-primary' : '' }}" style="min-height: 2.75rem;" data-env-software-filter="{{ $key }}"
                            @if (($filter ?? 'all') === $key) aria-current="page" @endif>{{ __('larena-env::admin.software.overview.filters.'.$key) }} · {{ $count }}</a>
                    @endforeach
                </nav>
                @if (($filter ?? 'all') !== 'all')
                    <input type="hidden" name="show" value="{{ $filter }}">
                @endif
                <label class="flex items-cross-center g-1 grow" style="margin-inline-start: auto; max-width: 22rem;">
                    <span class="sr-only">{{ __('larena-env::admin.software.overview.search') }}</span>
                    <input type="search" name="q" value="{{ $query ?? '' }}" maxlength="64" placeholder="{{ __('larena-env::admin.software.overview.search') }}" style="min-height: 2.75rem; width: 100%; padding-inline: .75rem; border: 1px solid var(--larena-line); border-radius: .5rem; background: transparent; color: inherit;" data-env-software-search>
                </label>
                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.find') }}</button>
            </form>
            @if (\Larena\Env\Software\SoftwareScreen::groups($software, $withPhp ?? false, $filter ?? 'all', $query ?? '') === [])
                <p class="p-x-2 p-bottom-2 m-0" data-env-software-none>{{ __('larena-env::admin.software.overview.none_found') }}</p>
            @else
                <table class="larena-table larena-table-stack" data-env-software-table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('larena-env::admin.software.overview.columns.name') }}</th>
                            <th scope="col" style="white-space: nowrap;">{{ __('larena-env::admin.software.overview.columns.state') }}</th>
                            <th scope="col" style="white-space: nowrap;">{{ __('larena-env::admin.software.overview.columns.version') }}</th>
                            <th scope="col"><span class="larena-visually-hidden">{{ __('larena-env::admin.software.overview.columns.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (\Larena\Env\Software\SoftwareScreen::groups($software, $withPhp ?? false, $filter ?? 'all', $query ?? '') as $group)
                            <tr data-env-software-group="{{ $group['key'] }}">
                                <th scope="colgroup" colspan="4" style="width: 100%;">{{ __('larena-env::admin.software.overview.groups.'.$group['key']) }}</th>
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
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.version') }}" class="mono text-small" style="min-width: 7rem;">{{ ($phpRow ?? null) === null || $phpRow['count'] === 0 ? '—' : $phpRow['versions'] }}</td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.actions') }}">
                                        <a class="flex items-cross-center" style="min-height: 2.75rem; white-space: nowrap;" href="{{ route('larena.env.admin.software.php', [], false) }}" data-env-software-php-open>{{ __('larena-env::admin.software.overview.php_open') }}</a>
                                    </td>
                                </tr>
                            @endif
                            @foreach ($group['components'] as $component)
                                <tr data-env-software-component="{{ $component->name }}" data-env-software-state="{{ $component->installed ? 'installed' : 'absent' }}">
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.name') }}" style="min-width: 12rem;">
                                        <span>{{ __('larena-env::admin.software.overview.components.'.$component->name) !== 'larena-env::admin.software.overview.components.'.$component->name ? __('larena-env::admin.software.overview.components.'.$component->name) : ($component->title ?? $component->name) }}</span>
                                        <div class="text-small color-on-surface-variant">{{ __('larena-env::admin.software.overview.purpose.'.$component->name) !== 'larena-env::admin.software.overview.purpose.'.$component->name ? __('larena-env::admin.software.overview.purpose.'.$component->name) : $component->purpose }}</div>
                                        @if ($component->sites !== [])
                                            <div class="text-small color-on-surface-variant" style="overflow-wrap: anywhere;" data-env-software-sites>{{ __('larena-env::admin.software.php.sites', ['sites' => implode(', ', $component->sites)]) }}</div>
                                        @endif
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.state') }}">
                                        <div class="flex flex-wrap items-cross-center g-1">
                                            @if (!$component->installed)
                                                <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.overview.not_installed') }}" data-env-software-absent></sf-badge>
                                            @elseif ($component->service !== null)
                                                <sf-badge appearance="tonal" status="{{ $component->active === true ? 'success' : 'error' }}" @if ($component->active !== true) role="alert" @endif
                                                    text="{{ $component->active === true ? __('larena-env::admin.software.overview.running') : __('larena-env::admin.software.overview.stopped') }}"></sf-badge>
                                            @else
                                                <sf-badge appearance="tonal" status="success" text="{{ __('larena-env::admin.software.overview.installed') }}"></sf-badge>
                                            @endif
                                            @if ($component->update)
                                                <sf-badge appearance="tonal" status="warning" text="{{ __('larena-env::admin.software.overview.update_waiting') }}" data-env-software-update></sf-badge>
                                            @endif
                                            @if ($component->requiredByEnv())
                                                <sf-badge appearance="tonal" text="{{ __('larena-env::admin.software.overview.required_by_env') }}" data-env-software-required></sf-badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td data-label="{{ __('larena-env::admin.software.overview.columns.version') }}" class="text-small" style="min-width: 7rem;">
                                        @if ($component->installed)
                                            <span class="mono" style="white-space: nowrap;" title="{{ $component->version }}{{ $component->update && $component->candidate !== null ? ' → '.$component->candidate : '' }}">{{ \Larena\Env\Software\SoftwareScreen::shortVersion($component->version) }}@if ($component->update && $component->candidate !== null) → {{ \Larena\Env\Software\SoftwareScreen::shortVersion($component->candidate) }}@endif</span>
                                        @elseif ($component->candidate !== null)
                                            <span class="color-on-surface-variant" title="{{ $component->candidate }}">{{ __('larena-env::admin.software.overview.'.($component->installable ? 'available' : 'version'), ['version' => \Larena\Env\Software\SoftwareScreen::shortVersion($component->candidate)]) }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td @if (\Larena\Env\Software\SoftwareScreen::hasMenu($component, $allows)) data-label="{{ __('larena-env::admin.software.overview.columns.actions') }}" @endif>
                                        @if (\Larena\Env\Software\SoftwareScreen::hasMenu($component, $allows))
                                            <details data-env-software-actions="{{ $component->name }}" style="position: relative;">
                                                <summary class="flex items-cross-center" style="min-height: 2.75rem; cursor: pointer; white-space: nowrap;"
                                                    aria-label="{{ __('larena-env::admin.software.overview.actions_of', ['name' => $component->title ?? $component->name]) }}">{{ __('larena-env::admin.software.overview.columns.actions') }}</summary>
                                                <div class="flex flex-column g-1 p-2" style="position: absolute; inset-inline-end: 0; top: 100%; z-index: 20; width: min(20rem, 80vw); background: var(--larena-panel); border: 1px solid var(--larena-line); border-radius: .75rem; box-shadow: 0 .5rem 1.5rem rgb(0 0 0 / .25);">
                                                    @if ($allows['install'] && $component->installable)
                                                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="flex flex-wrap items-cross-end g-1 m-0" data-env-software-op="install-{{ $component->name }}">
                                                            @csrf
                                                            <input type="hidden" name="op" value="install">
                                                            <input type="hidden" name="name" value="{{ $component->name }}">
                                                            @if (in_array($component->name, \Larena\Env\Software\SoftwareComponent::VERSIONED, true))
                                                                <label class="flex flex-column text-small">{{ __('larena-env::admin.software.overview.major') }}
                                                                    <input type="text" name="version" inputmode="numeric" pattern="[0-9]{2}" maxlength="2" size="3" style="min-height: 2.75rem; width: 4rem;"
                                                                        placeholder="{{ $component->name === 'node' ? '22' : '' }}" aria-describedby="env-software-major-{{ $component->name }}">
                                                                </label>
                                                            @endif
                                                            <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.install') }}</button>
                                                            @if (in_array($component->name, \Larena\Env\Software\SoftwareComponent::VERSIONED, true))
                                                                <span id="env-software-major-{{ $component->name }}" class="text-small color-on-surface-variant" style="flex-basis: 100%;">{{ __('larena-env::admin.software.overview.major_hint_'.$component->name) }}</span>
                                                            @endif
                                                        </form>
                                                    @endif
                                                    @if (($allows['upgrade'] ?? false) && $component->canUpdate)
                                                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="upgrade-{{ $component->name }}">
                                                            @csrf
                                                            <input type="hidden" name="op" value="upgrade">
                                                            <input type="hidden" name="name" value="{{ $component->name }}">
                                                            <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ $component->candidate !== null ? __('larena-env::admin.software.overview.upgrade_to', ['version' => \Larena\Env\Software\SoftwareScreen::shortVersion($component->candidate)]) : __('larena-env::admin.software.overview.upgrade') }}</button>
                                                        </form>
                                                    @elseif ($allows['update'] && $component->update)
                                                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="update-{{ $component->name }}">
                                                            @csrf
                                                            <input type="hidden" name="op" value="update">
                                                            <input type="hidden" name="security_only" value="no">
                                                            <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.update_with_system') }}</button>
                                                        </form>
                                                    @endif
                                                    @if (($allows['remove'] ?? false) && $component->canRemove)
                                                        <form method="post" action="{{ route('larena.env.admin.software.plan', [], false) }}" class="m-0" data-env-software-op="remove-{{ $component->name }}">
                                                            @csrf
                                                            <input type="hidden" name="op" value="remove">
                                                            <input type="hidden" name="name" value="{{ $component->name }}">
                                                            <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.software.overview.remove') }}</button>
                                                        </form>
                                                    @elseif ($component->installed && in_array($component->removeBlocked['code'] ?? null, ['used_by_sites', 'has_certificates'], true))
                                                        <p class="m-0 text-small color-on-surface-variant" data-env-software-remove-blocked="{{ $component->removeBlocked['code'] }}">{{ __('larena-env::admin.software.overview.remove_blocked.'.$component->removeBlocked['code'], ['sites' => implode(', ', $component->removeBlocked['sites']), 'count' => (string) ($component->removeBlocked['count'] ?? 0)]) }}</p>
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
            @endif
        </section>
    @endif
@endsection
