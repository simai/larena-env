@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.security.title'))
@section('heading', __('larena-env::admin.security.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.security.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The server's security (simai-env 1.45.0–1.47.0), the system level's: the score first, then what to fix with
         «Исправить» where the server can do it itself, the level in one choice, the sites' packages and files. --}}
    @if (!$supported)
        <section class="larena-panel larena-panel-padded" data-env-security="unsupported">
            <p class="m-0">{{ __('larena-env::admin.security.unsupported') }}</p>
        </section>
    @elseif ($status === null)
        <section class="larena-panel larena-panel-padded" data-env-security="unreadable">
            <p class="m-0" role="alert">{{ __('larena-env::admin.security.unreadable') }}</p>
        </section>
    @else
        <section class="larena-panel larena-panel-padded flex flex-wrap items-cross-center g-4" data-env-security="{{ $status->grade }}" aria-labelledby="env-security-score">
            <div role="img" aria-label="{{ __('larena-env::admin.security.score') }}: {{ __('larena-env::admin.security.score_of', ['score' => (string) ($status->score ?? 0)]) }}" data-env-security-score="{{ $status->score ?? '' }}"
                 style="flex: none; inline-size: 7rem; block-size: 7rem; border-radius: 50%; display: grid; place-items: center;
                 background: conic-gradient(var(--sf-{{ ['good' => 'success', 'attention' => 'warning', 'risk' => 'error'][$status->grade] }}) {{ ($status->score ?? 0) * 3.6 }}deg, var(--sf-outline-variant) 0);">
                <span style="inline-size: 5.5rem; block-size: 5.5rem; border-radius: 50%; display: grid; place-items: center; background: var(--larena-panel);">
                    <span class="text-4" style="font-weight: 700; line-height: 1;">{{ $status->score ?? '—' }}</span>
                </span>
            </div>
            <div class="grow min-w-0" style="flex-basis: 14rem;">
                <h2 id="env-security-score" class="text-2 m-0">{{ __('larena-env::admin.security.grades.'.$status->grade) }}</h2>
                <p class="m-0 color-on-surface-variant" data-env-security-summary>{{ __('larena-env::admin.security.summary', ['risk' => (string) $status->summary['risk'], 'warn' => (string) $status->summary['warn'], 'ok' => (string) $status->summary['ok']]) }}</p>
                @if ($status->checkedAt !== null)
                    <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.security.checked', ['when' => \Larena\Env\Server\Moment::human($status->checkedAt)]) }}</p>
                @endif
                @if (!$status->onlineEnabled)
                    <p class="m-0 color-on-surface-variant" data-env-security-offline>{{ __('larena-env::admin.security.online_off') }}</p>
                @elseif ($status->onlineError !== null)
                    <p class="m-0 color-on-surface-variant" data-env-security-online-error>{{ __('larena-env::admin.security.online_error') }}</p>
                @endif
            </div>
            @if ($allows['scan'])
                <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="scan">
                    @csrf
                    <input type="hidden" name="op" value="scan">
                    <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.scan') }}</button>
                </form>
            @endif
        </section>

        <section class="larena-panel larena-panel-padded" data-env-security-problems="{{ count($status->problems()) }}" aria-labelledby="env-security-problems">
            <h2 id="env-security-problems" class="text-2">{{ __('larena-env::admin.security.problems') }}</h2>
            @if ($status->problems() === [])
                <p class="m-0">{{ __('larena-env::admin.security.no_problems') }}</p>
            @else
                <ul class="m-0 p-0" style="list-style: none;">
                    @foreach ($status->problems() as $check)
                        <li id="env-security-check-{{ $check->id }}" class="flex flex-wrap items-cross-center g-2 p-y-2 border-top-1 border-top-solid border-outline-variant" data-env-security-check="{{ $check->id }}" data-env-security-status="{{ $check->status }}">
                            <sf-badge appearance="tonal" status="{{ $check->status === 'risk' ? 'error' : 'warning' }}" text="{{ __('larena-env::admin.security.statuses.'.$check->status) }}"></sf-badge>
                            <div class="grow min-w-0" style="flex-basis: 16rem;">
                                <div style="font-weight: 600; overflow-wrap: anywhere;">
                                    {{ $words[$check->id]['title'] }}@if ($check->domain !== null) · {{ $check->domain }}@endif
                                </div>
                                <div class="color-on-surface-variant" style="overflow-wrap: anywhere;">
                                    {{ $words[$check->id]['line'] }}
                                </div>
                                @if (!($fixes[$check->id] ?? false))
                                    <details data-env-security-how>
                                        <summary style="cursor: pointer;">{{ __('larena-env::admin.security.how') }}</summary>
                                        <p class="m-0 p-y-1">{{ $words[$check->id]['how'] }}</p>
                                    </details>
                                @endif
                            </div>
                            @if ($fixes[$check->id] ?? false)
                                <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-fix="{{ $check->id }}">
                                    @csrf
                                    <input type="hidden" name="op" value="fix">
                                    <input type="hidden" name="check" value="{{ $check->id }}">
                                    <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.fix') }}</button>
                                </form>
                            @elseif ($check->domain !== null)
                                <a class="larena-button" style="min-height: 2.75rem;" href="{{ route('larena.env.admin.site', ['domain' => $check->domain], false) }}{{ $check->check === 'https' ? '?tab=domains' : ($check->check === 'backup_verify' ? '?tab=backups' : '') }}" data-env-security-open="{{ $check->id }}">{{ __('larena-env::admin.security.open_site') }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($status->rest() !== [])
                <details class="m-t-2" data-env-security-fine>
                    <summary style="cursor: pointer;">{{ __('larena-env::admin.security.fine', ['count' => (string) count($status->rest())]) }}</summary>
                    <ul class="m-0 p-0" style="list-style: none;">
                        @foreach ($status->rest() as $check)
                            <li class="flex flex-wrap items-cross-center g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-security-check="{{ $check->id }}" data-env-security-status="{{ $check->status }}">
                                <sf-badge appearance="tonal" status="{{ $check->status === 'ok' ? 'success' : 'neutral' }}" text="{{ __('larena-env::admin.security.statuses.'.$check->status) }}"></sf-badge>
                                <span class="grow min-w-0" style="overflow-wrap: anywhere;">
                                    {{ $words[$check->id]['title'] }}@if ($check->domain !== null) · {{ $check->domain }}@endif
                                    <span class="color-on-surface-variant">— {{ $words[$check->id]['line'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        <section class="larena-panel larena-panel-padded" data-env-security-level="{{ $status->level ?? 'none' }}" aria-labelledby="env-security-level">
            <h2 id="env-security-level" class="text-2">{{ __('larena-env::admin.security.level') }}</h2>
            <p class="color-on-surface-variant">{{ __('larena-env::admin.security.level_note') }}</p>
            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));">
                @foreach ($levels as $level)
                    <div class="p-3" style="border: 1px solid {{ $status->level === $level ? 'var(--sf-primary)' : 'var(--larena-line)' }}; border-radius: .75rem; display: flex; flex-direction: column; gap: .5rem;" data-env-security-level-card="{{ $level }}" @if ($status->level === $level) aria-current="true" @endif>
                        <div class="flex flex-wrap items-cross-center g-1">
                            <strong>{{ __('larena-env::admin.security.levels.'.$level.'.name') }}</strong>
                            @if ($status->level === $level)
                                <sf-badge appearance="tonal" status="success" text="{{ __('larena-env::admin.security.level_current') }}"></sf-badge>
                            @elseif ($level === 'enhanced')
                                <sf-badge appearance="tonal" status="info" text="{{ __('larena-env::admin.security.level_recommended') }}"></sf-badge>
                            @endif
                        </div>
                        <p class="m-0 color-on-surface-variant grow">{{ __('larena-env::admin.security.levels.'.$level.'.text') }}</p>
                        @if ($allows['level'] && $status->level !== $level)
                            <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="level-{{ $level }}">
                                @csrf
                                <input type="hidden" name="op" value="level">
                                <input type="hidden" name="set" value="{{ $level }}">
                                <button type="submit" class="larena-button @if ($level === 'enhanced') larena-button-primary @endif" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.level_apply') }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        @if ($geoSupported)
            {{-- Country blocking (simai-env 1.48.0): the mode, the countries, the ports and the addresses never dropped in one
                 form, planned and confirmed; allowing only a list or all ports is undone by the server unless kept in time. --}}
            <section class="larena-panel larena-panel-padded" data-env-security-geo="{{ $geo?->mode ?? 'unreadable' }}" aria-labelledby="env-security-geo">
                <h2 id="env-security-geo" class="text-2">{{ __('larena-env::admin.security.geo.title') }}</h2>
                <p class="color-on-surface-variant">{{ __('larena-env::admin.security.geo.note') }}</p>
                @if ($geo === null)
                    <p role="alert">{{ __('larena-env::admin.security.geo.unreadable') }}</p>
                @else
                    <p class="m-0" data-env-security-geo-now>
                        <strong>{{ __('larena-env::admin.security.geo.now') }}:</strong>
                        {{ __('larena-env::admin.security.geo.modes.'.$geo->mode) }}@if ($geo->mode !== 'off') · {{ __('larena-env::admin.security.geo.port_choices.'.$geo->ports) }}@endif
                    </p>
                    @if ($geo->mode !== 'off')
                        <p class="m-0" data-env-security-geo-countries>
                            @foreach ($geo->countries as $code)<span style="white-space: nowrap;">{{ \Larena\Env\Security\GeoStatus::flag($code) }} {{ $countries[$code] ?? $code }}</span>@if (!$loop->last), @endif @endforeach
                        </p>
                        <p class="m-0 color-on-surface-variant">
                            @if (!$geo->active){{ __('larena-env::admin.security.geo.inactive') }}@elseif ($geo->dropped !== null){{ __('larena-env::admin.security.geo.dropped', ['count' => number_format($geo->dropped, 0, ',', ' ')]) }}@endif
                        </p>
                    @endif
                    @if ($allows['geo'])
                        <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0 m-t-3" data-env-security-op="geo">
                            @csrf
                            <input type="hidden" name="op" value="geo">
                            <fieldset class="m-0 p-0" style="border: 0;">
                                <legend style="font-weight: 600;">{{ __('larena-env::admin.security.geo.mode') }}</legend>
                                @foreach (\Larena\Env\Security\GeoStatus::MODES as $mode)
                                    <label class="flex items-cross-center g-1" style="min-height: 2.75rem;"><input type="radio" name="mode" value="{{ $mode }}" @checked($geo->mode === $mode)> {{ __('larena-env::admin.security.geo.modes.'.$mode) }}</label>
                                @endforeach
                            </fieldset>
                            <details class="m-t-2" data-env-security-geo-pick @if ($geo->countries === []) open @endif>
                                <summary style="cursor: pointer; min-height: 2.75rem; display: flex; align-items: center;">
                                    {{ __('larena-env::admin.security.geo.countries_pick') }} · {{ __('larena-env::admin.security.geo.countries_chosen', ['count' => (string) count($geo->countries)]) }}
                                </summary>
                                <div style="max-block-size: 18rem; overflow: auto; display: grid; gap: .125rem .75rem; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); padding: .5rem 0;">
                                    @foreach ($countries as $code => $name)
                                        <label class="flex items-cross-center g-1" style="min-height: 2.25rem;" data-env-security-geo-country="{{ $code }}">
                                            <input type="checkbox" name="countries[]" value="{{ $code }}" @checked(in_array($code, $geo->countries, true))>
                                            <span>{{ \Larena\Env\Security\GeoStatus::flag($code) }} {{ $name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                            <fieldset class="m-0 m-t-2 p-0" style="border: 0;">
                                <legend style="font-weight: 600;">{{ __('larena-env::admin.security.geo.ports') }}</legend>
                                @foreach (\Larena\Env\Security\GeoStatus::PORTS as $ports)
                                    <label class="flex items-cross-center g-1" style="min-height: 2.75rem;"><input type="radio" name="ports" value="{{ $ports }}" @checked($geo->ports === $ports)> {{ __('larena-env::admin.security.geo.port_choices.'.$ports) }}</label>
                                @endforeach
                            </fieldset>
                            <label class="m-t-2" style="display: block;">
                                <span style="font-weight: 600; display: block;">{{ __('larena-env::admin.security.geo.keep') }}</span>
                                <input type="text" name="keep" class="larena-input" style="inline-size: 100%; max-inline-size: 32rem; min-height: 2.75rem;" value="{{ $myAddress }}" inputmode="text" autocomplete="off" data-env-security-geo-keep>
                                <span class="color-on-surface-variant" style="display: block;">{{ __('larena-env::admin.security.geo.keep_hint') }}</span>
                            </label>
                            <p class="color-on-surface-variant m-t-2">{{ __('larena-env::admin.security.geo.timed') }}</p>
                            <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.geo.apply') }}</button>
                        </form>
                    @endif
                    <div class="flex flex-wrap items-cross-center g-2 m-t-3">
                        <span class="grow color-on-surface-variant" data-env-security-geo-database>
                            {{ $geo->databasePresent ? __('larena-env::admin.security.geo.database', ['date' => $geo->databaseDate ?? '—']) : __('larena-env::admin.security.geo.database_none') }}
                        </span>
                        @if ($allows['geo-update'] && $geo->databasePresent)
                            <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="geo-update">
                                @csrf
                                <input type="hidden" name="op" value="geo-update">
                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.geo.update') }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($lookupSupported)
                        <form method="get" action="{{ route('larena.env.admin.security', [], false) }}#env-security-geo" class="flex flex-wrap items-cross-end g-2 m-t-3" data-env-security-geo-lookup>
                            <label class="grow" style="flex-basis: 12rem;">
                                <span style="font-weight: 600; display: block;">{{ __('larena-env::admin.security.geo.lookup') }}</span>
                                <input type="text" name="ip" class="larena-input" style="inline-size: 100%; min-height: 2.75rem;" value="{{ $lookup['ip'] ?? '' }}" placeholder="{{ __('larena-env::admin.security.geo.lookup_field') }}" autocomplete="off">
                            </label>
                            <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.geo.lookup_go') }}</button>
                        </form>
                        @if ($lookup !== null)
                            <p class="m-t-1" role="status" data-env-security-geo-answer="{{ $lookup['country'] ?? '' }}">
                                @if (!$lookup['valid']){{ __('larena-env::admin.security.geo.lookup_invalid', ['ip' => $lookup['ip']]) }}
                                @elseif ($lookup['country'] === null){{ __('larena-env::admin.security.geo.lookup_unknown', ['ip' => $lookup['ip']]) }}
                                @else{{ __('larena-env::admin.security.geo.lookup_found', ['ip' => $lookup['ip'], 'country' => \Larena\Env\Security\GeoStatus::flag($lookup['country']).' '.($countries[$lookup['country']] ?? $lookup['country'])]) }}@endif
                            </p>
                        @endif
                    @endif
                    {{-- The country database's licence (CC BY 4.0) asks for this line wherever its data is shown. --}}
                    <p class="m-0 m-t-2 color-on-surface-variant text-0" data-env-security-geo-attribution>{{ $geo->attribution }}</p>
                @endif
            </section>
        @endif

        @if ($mcpSupported)
            {{-- Access for AI assistants over MCP (simai-env 1.49.0): on or off, the keys with whom each works for, a key to
                 add for the whole server or one owner's sites, and the lines to connect Claude Code, Codex or any client.
                 Every change is the server's own command, planned and confirmed. --}}
            <section class="larena-panel larena-panel-padded" data-env-security-mcp="{{ $mcp === null ? 'unreadable' : ($mcp->enabled ? 'on' : 'off') }}" aria-labelledby="env-security-mcp">
                <h2 id="env-security-mcp" class="text-2">{{ __('larena-env::admin.security.mcp.title') }}</h2>
                <p class="color-on-surface-variant">{{ __('larena-env::admin.security.mcp.note') }}</p>
                @if ($mcp === null)
                    <p role="alert">{{ __('larena-env::admin.security.mcp.unreadable') }}</p>
                @else
                    <div class="flex flex-wrap items-cross-center g-2">
                        <span class="grow"><strong>{{ __('larena-env::admin.security.mcp.now') }}:</strong> {{ __('larena-env::admin.security.mcp.'.($mcp->enabled ? 'on' : 'off')) }}</span>
                        @if ($allows[$mcp->enabled ? 'mcp-disable' : 'mcp-enable'])
                            <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="{{ $mcp->enabled ? 'mcp-disable' : 'mcp-enable' }}">
                                @csrf
                                <input type="hidden" name="op" value="{{ $mcp->enabled ? 'mcp-disable' : 'mcp-enable' }}">
                                <button type="submit" class="larena-button @if (!$mcp->enabled) larena-button-primary @endif" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.operations.'.($mcp->enabled ? 'mcp-disable' : 'mcp-enable')) }}</button>
                            </form>
                        @endif
                    </div>
                    @if ($mcp->enabled)
                        <h3 class="text-1 m-t-3">{{ __('larena-env::admin.security.mcp.keys') }}</h3>
                        @if ($mcp->keys === [])
                            <p class="m-0" data-env-security-mcp-keys="0">{{ __('larena-env::admin.security.mcp.keys_none') }}</p>
                        @else
                            <ul class="m-0 p-0" style="list-style: none;" data-env-security-mcp-keys="{{ count($mcp->keys) }}">
                                @foreach ($mcp->keys as $key)
                                    <li class="flex flex-wrap items-cross-center g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-security-mcp-key="{{ $key['fingerprint'] }}">
                                        <span class="grow min-w-0">
                                            <strong>{{ $key['role'] === 'owner' ? __('larena-env::admin.security.mcp.for_owner', ['owner' => $key['owner']]) : __('larena-env::admin.security.mcp.for_admin') }}</strong>
                                            <span class="color-on-surface-variant text-0" style="display: block; overflow-wrap: anywhere; font-family: var(--sf-mono, monospace);">{{ $key['fingerprint'] }}</span>
                                        </span>
                                        @if ($allows['mcp-key-remove'])
                                            <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="mcp-key-remove">
                                                @csrf
                                                <input type="hidden" name="op" value="mcp-key-remove">
                                                <input type="hidden" name="fingerprint" value="{{ $key['fingerprint'] }}">
                                                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.mcp.remove') }}</button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($allows['mcp-key-add'])
                            <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0 m-t-3" data-env-security-op="mcp-key-add">
                                @csrf
                                <input type="hidden" name="op" value="mcp-key-add">
                                <label style="display: block;">
                                    <span style="font-weight: 600; display: block;">{{ __('larena-env::admin.security.mcp.pubkey') }}</span>
                                    <textarea name="pubkey" class="larena-input" rows="3" required style="inline-size: 100%; font-family: var(--sf-mono, monospace);" placeholder="ssh-ed25519 AAAA… name@computer" autocomplete="off" spellcheck="false"></textarea>
                                    <span class="color-on-surface-variant" style="display: block;">{{ __('larena-env::admin.security.mcp.pubkey_hint') }}</span>
                                </label>
                                <fieldset class="m-0 m-t-2 p-0" style="border: 0;">
                                    <legend style="font-weight: 600;">{{ __('larena-env::admin.security.mcp.role') }}</legend>
                                    <label class="flex items-cross-center g-1" style="min-height: 2.75rem;"><input type="radio" name="role" value="owner" @checked($owners !== [])> {{ __('larena-env::admin.security.mcp.role_owner') }}</label>
                                    @if ($owners !== [])
                                        <select name="owner" class="larena-input" style="min-height: 2.75rem; margin-inline-start: 1.5rem;" aria-label="{{ __('larena-env::admin.security.mcp.owner') }}">
                                            @foreach ($owners as $owner)<option value="{{ $owner }}">{{ $owner }}</option>@endforeach
                                        </select>
                                    @endif
                                    <label class="flex items-cross-center g-1" style="min-height: 2.75rem;"><input type="radio" name="role" value="administrator" @checked($owners === [])> {{ __('larena-env::admin.security.mcp.role_admin') }}</label>
                                </fieldset>
                                <button type="submit" class="larena-button larena-button-primary m-t-2" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.mcp.add') }}</button>
                            </form>
                        @endif
                        @if ($mcp->clients !== [])
                            <h3 class="text-1 m-t-3">{{ __('larena-env::admin.security.mcp.connect') }}</h3>
                            <p class="color-on-surface-variant m-0">{{ __('larena-env::admin.security.mcp.connect_note') }}</p>
                            @foreach (['claude_code', 'codex', 'json'] as $client)
                                @if (isset($mcp->clients[$client]))
                                    <label class="m-t-2" style="display: block;" data-env-security-mcp-client="{{ $client }}">
                                        <span style="font-weight: 600; display: block;">{{ __('larena-env::admin.security.mcp.clients.'.$client) }}</span>
                                        <input type="text" readonly class="larena-input" style="inline-size: 100%; min-height: 2.75rem; font-family: var(--sf-mono, monospace);" value="{{ $mcp->clients[$client] }}">
                                    </label>
                                @endif
                            @endforeach
                        @endif
                    @endif
                @endif
            </section>
        @endif

        <section class="larena-panel larena-panel-padded" data-env-security-vulnerabilities="{{ count($status->vulnerabilities) }}" aria-labelledby="env-security-vulnerabilities">
            <h2 id="env-security-vulnerabilities" class="text-2">{{ __('larena-env::admin.security.vulnerabilities') }}</h2>
            @if ($status->vulnerabilities === [])
                <p class="m-0">{{ __('larena-env::admin.security.vulnerabilities_none') }}</p>
            @else
                @foreach ($status->vulnerabilities as $domain => $items)
                    <h3 class="text-1">{{ __('larena-env::admin.security.site', ['site' => $domain]) }}</h3>
                    <ul class="m-0 p-0" style="list-style: none;" data-env-security-vulnerable-site="{{ $domain }}">
                        @foreach ($items as $item)
                            <li class="flex flex-wrap g-2 p-y-1 border-top-1 border-top-solid border-outline-variant">
                                <span class="grow min-w-0" style="overflow-wrap: anywhere;"><strong>{{ $item['package'] }}</strong> {{ $item['version'] ?? '' }} · {{ $item['id'] }}@if ($item['summary'] !== null) — {{ $item['summary'] }}@endif</span>
                                @if ($item['severity'] !== null)<sf-badge appearance="tonal" status="warning" text="{{ $item['severity'] }}"></sf-badge>@endif
                                @if ($item['fixed'] !== null)<span class="color-on-surface-variant">{{ __('larena-env::admin.security.vulnerability_columns.fixed') }} {{ $item['fixed'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </section>

        @if ($filesSupported)
            <section class="larena-panel larena-panel-padded" data-env-security-files="{{ $files?->foundCount() ?? '' }}" aria-labelledby="env-security-files">
                <h2 id="env-security-files" class="text-2">{{ __('larena-env::admin.security.files') }}</h2>
                <p class="color-on-surface-variant">{{ __('larena-env::admin.security.files_note') }}</p>
                @if ($files === null || $files->checkedAt === null)
                    <p>{{ __('larena-env::admin.security.files_never') }}</p>
                @else
                    <p>{{ __('larena-env::admin.security.files_checked', ['when' => \Larena\Env\Server\Moment::human($files->checkedAt),
                        'engines' => implode(', ', $engines)]) }}</p>
                    @if ($files->foundCount() === 0)
                        <p data-env-security-files-clean>{{ __('larena-env::admin.security.files_clean') }}</p>
                    @else
                        <p><strong>{{ __('larena-env::admin.security.files_found', ['count' => (string) $files->foundCount()]) }}</strong></p>
                    @endif
                    @foreach ($files->sites as $domain => $site)
                        @if ($site['error'] !== null)
                            <p role="alert">{{ __('larena-env::admin.security.site', ['site' => $domain]) }}: {{ __('larena-env::admin.security.files_error', ['error' => $site['error']]) }}</p>
                        @endif
                        @if ($site['found'] !== [])
                            <h3 class="text-1">{{ __('larena-env::admin.security.site', ['site' => $domain]) }}</h3>
                            <ul class="m-0 p-0" style="list-style: none;">
                                @foreach ($site['found'] as $file)
                                    <li class="flex flex-wrap items-cross-center g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-security-found="{{ $domain }}/{{ $file['path'] }}">
                                        <span class="grow min-w-0" style="flex-basis: 14rem; overflow-wrap: anywhere;"><code>{{ $file['path'] }}</code> · {{ $file['signature'] }}</span>
                                        @if ($allows['quarantine'])
                                            <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="quarantine">
                                                @csrf
                                                <input type="hidden" name="op" value="quarantine">
                                                <input type="hidden" name="domain" value="{{ $domain }}">
                                                <input type="hidden" name="path" value="{{ $file['path'] }}">
                                                <button type="submit" class="larena-button larena-button-primary" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.to_quarantine') }}</button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                @endif
                @if ($allows['files-scan'])
                    <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0 m-t-2" data-env-security-op="files-scan">
                        @csrf
                        <input type="hidden" name="op" value="files-scan">
                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.files_scan') }}</button>
                    </form>
                @endif

                <h3 class="text-1 m-t-3">{{ __('larena-env::admin.security.quarantine') }}</h3>
                <p class="color-on-surface-variant">{{ __('larena-env::admin.security.quarantine_note') }}</p>
                @if ($quarantine === null || $quarantine->files === [])
                    <p class="m-0" data-env-security-quarantine="0">{{ __('larena-env::admin.security.quarantine_none') }}</p>
                @else
                    <ul class="m-0 p-0" style="list-style: none;" data-env-security-quarantine="{{ count($quarantine->kept()) }}">
                        @foreach ($quarantine->files as $file)
                            <li class="flex flex-wrap items-cross-center g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-security-quarantined="{{ $file['id'] }}">
                                <span class="grow min-w-0" style="flex-basis: 14rem; overflow-wrap: anywhere;">{{ $file['domain'] }} · <code>{{ $file['path'] }}</code>@if ($file['at'] !== null) · {{ \Larena\Env\Server\Moment::human($file['at']) }}@endif</span>
                                @if ($file['state'] === 'restored')
                                    <span class="color-on-surface-variant">{{ __('larena-env::admin.security.restored', ['when' => \Larena\Env\Server\Moment::human($file['restoredAt'])]) }}</span>
                                @elseif ($allows['restore'])
                                    <form method="post" action="{{ route('larena.env.admin.security.plan', [], false) }}" class="m-0" data-env-security-op="restore">
                                        @csrf
                                        <input type="hidden" name="op" value="restore">
                                        <input type="hidden" name="id" value="{{ $file['id'] }}">
                                        <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.security.restore') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @endif
@endsection
