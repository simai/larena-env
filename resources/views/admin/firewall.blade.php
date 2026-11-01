@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.firewall.title'))
@section('heading', __('larena-env::admin.firewall.title'))
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.firewall.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- The server's firewall (Panel plan 5.2), the system level's: every change is undone by the server unless kept in time. --}}
    <section class="larena-panel larena-panel-padded" data-env-firewall="{{ !$supported ? 'unsupported' : ($status === null ? 'unreadable' : ($status->active ? 'on' : 'off')) }}" aria-labelledby="env-firewall">
        <h2 id="env-firewall">{{ __('larena-env::admin.firewall.now') }}</h2>
        @if (!$supported)
            <p data-env-firewall-unsupported>{{ __('larena-env::admin.firewall.unsupported') }}</p>
        @elseif ($status === null)
            <p role="alert">{{ __('larena-env::admin.firewall.unreadable') }}</p>
        @else
            @include('larena-env::admin.partials.timed-change', ['change' => $pending, 'canKeep' => $allows['keep']])
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-env::admin.firewall.state') }}</dt><dd>{{ $status->active ? __('larena-env::admin.firewall.on') : __('larena-env::admin.firewall.off') }}</dd></div>
                <div><dt>{{ __('larena-env::admin.firewall.default_incoming') }}</dt><dd>{{ $status->defaultIncoming === null ? '—' : __('larena-env::admin.firewall.actions.'.($status->defaultIncoming === 'allow' ? 'allow' : 'deny')) }}</dd></div>
            </dl>
            <p>{{ __('larena-env::admin.firewall.protected_note') }}</p>
        @endif
    </section>

    @if ($supported && $status !== null)
        <section class="larena-panel larena-panel-padded" data-env-firewall-rules aria-labelledby="env-firewall-rules">
            <h2 id="env-firewall-rules">{{ __('larena-env::admin.firewall.rules') }}</h2>
            @if ($status->rules === [])
                <p>{{ __('larena-env::admin.firewall.no_rules') }}</p>
            @else
                <ul class="larena-list" style="list-style: none; padding-inline-start: 0; display: grid; gap: .5rem;">
                    @foreach ($status->rules as $rule)
                        <li data-env-firewall-rule="{{ $rule->id }}" @if ($rule->protected) data-env-firewall-protected @endif
                            style="display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .625rem .75rem; border: 1px solid var(--larena-line); border-radius: .625rem;">
                            <span style="flex: 1 1 12rem; min-width: 0; overflow-wrap: anywhere;">
                                {{ __('larena-env::admin.firewall.rule', ['action' => __('larena-env::admin.firewall.actions.'.$rule->action), 'port' => $rule->port,
                                    'proto' => $rule->proto === 'any' ? __('larena-env::admin.firewall.any_proto') : $rule->proto,
                                    'from' => $rule->from === 'any' ? __('larena-env::admin.firewall.anywhere') : $rule->from]) }}
                            </span>
                            @if ($rule->protected)
                                <span data-env-firewall-locked>{{ __('larena-env::admin.firewall.locked') }}</span>
                            @elseif ($allows['rule-remove'])
                                <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" data-env-firewall-op="rule-remove">
                                    @csrf
                                    <input type="hidden" name="op" value="rule-remove">
                                    <input type="hidden" name="id" value="{{ $rule->id }}">
                                    <button type="submit" style="min-height: 2.75rem;">{{ __('larena-env::admin.firewall.remove') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($allows['rule-add'])
                <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" class="larena-form" data-env-firewall-op="rule-add">
                    @csrf
                    <input type="hidden" name="op" value="rule-add">
                    <h3>{{ __('larena-env::admin.firewall.add') }}</h3>
                    <div class="larena-field">
                        <label for="env-fw-action">{{ __('larena-env::admin.firewall.action') }}</label>
                        <select id="env-fw-action" name="action" style="min-height: 2.75rem;">
                            <option value="allow">{{ __('larena-env::admin.firewall.actions.allow') }}</option>
                            <option value="deny">{{ __('larena-env::admin.firewall.actions.deny') }}</option>
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-fw-port">{{ __('larena-env::admin.firewall.port') }}</label>
                        <input type="text" id="env-fw-port" name="port" required inputmode="numeric" autocomplete="off" placeholder="8080">
                        <p>{{ __('larena-env::admin.firewall.port_hint') }}</p>
                    </div>
                    <div class="larena-field">
                        <label for="env-fw-proto">{{ __('larena-env::admin.firewall.proto') }}</label>
                        <select id="env-fw-proto" name="proto" style="min-height: 2.75rem;">
                            <option value="any">{{ __('larena-env::admin.firewall.any_proto') }}</option>
                            <option value="tcp">TCP</option>
                            <option value="udp">UDP</option>
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-fw-from">{{ __('larena-env::admin.firewall.from') }}</label>
                        <input type="text" id="env-fw-from" name="from" autocomplete="off" placeholder="{{ __('larena-env::admin.firewall.from_placeholder') }}">
                    </div>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
                </form>
            @endif
        </section>

        <section class="larena-panel larena-panel-padded" data-env-firewall-blocks aria-labelledby="env-firewall-blocks">
            <h2 id="env-firewall-blocks">{{ __('larena-env::admin.firewall.blocks') }}</h2>
            @if ($status->blocks === [])
                <p>{{ __('larena-env::admin.firewall.no_blocks') }}</p>
            @else
                <ul class="larena-list" style="list-style: none; padding-inline-start: 0; display: grid; gap: .5rem;">
                    @foreach ($status->blocks as $block)
                        <li data-env-firewall-block="{{ $block['address'] }}" style="display: flex; flex-wrap: wrap; align-items: center; gap: .5rem;">
                            <span style="flex: 1 1 12rem; overflow-wrap: anywhere;">{{ $block['address'] }} · {{ $block['expires_at'] === null ? __('larena-env::admin.firewall.until_unblocked') : __('larena-env::admin.firewall.until', ['time' => \Larena\Env\Server\Moment::human($block['expires_at'])]) }}</span>
                            @if ($allows['unblock'])
                                <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" data-env-firewall-op="unblock">
                                    @csrf
                                    <input type="hidden" name="op" value="unblock">
                                    <input type="hidden" name="address" value="{{ $block['address'] }}">
                                    <button type="submit" style="min-height: 2.75rem;">{{ __('larena-env::admin.firewall.unblock') }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($allows['block'])
                <form method="post" action="{{ route('larena.env.admin.firewall.plan', [], false) }}" class="larena-form" data-env-firewall-op="block">
                    @csrf
                    <input type="hidden" name="op" value="block">
                    <div class="larena-field">
                        <label for="env-fw-block">{{ __('larena-env::admin.firewall.block_address') }}</label>
                        <input type="text" id="env-fw-block" name="address" required autocomplete="off" placeholder="203.0.113.7">
                    </div>
                    <div class="larena-field">
                        <label for="env-fw-ttl">{{ __('larena-env::admin.firewall.block_for') }}</label>
                        <select id="env-fw-ttl" name="ttl" style="min-height: 2.75rem;">
                            @foreach ($ttls as $ttl)
                                <option value="{{ $ttl }}">{{ __('larena-env::admin.firewall.ttls.'.($ttl === '' ? 'forever' : $ttl)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.firewall.block') }}</button></div>
                </form>
            @endif
        </section>
    @endif
@endsection
