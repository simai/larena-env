@extends('larena-admin::layouts.app')

{{-- The open section is named in the tab's title and the heading (critics 2026-11-07): «Журнал — shop.ru». --}}
@section('title', ($tab !== 'overview' && $site !== null ? __('larena-env::admin.site.tabs.'.$tab).' — ' : '').__('larena-env::admin.site.title', ['domain' => $domain]))
@section('heading', $tab !== 'overview' && $site !== null ? $domain.' · '.__('larena-env::admin.site.tabs.'.$tab) : $domain)
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.site.description'))

@if ($site !== null)
    {{-- The site's state at a glance in the page's head: whether it works, what it runs, its certificate. --}}
    @section('heading-meta')
        <span class="flex flex-wrap items-cross-center g-1" data-env-site-state="{{ \Larena\Env\Admin\SiteState::of($site->answersChecked, $site->answers) }}">
            <sf-badge status="{{ \Larena\Env\Admin\SiteState::status(\Larena\Env\Admin\SiteState::of($site->answersChecked, $site->answers)) }}" appearance="tonal" text="{{ __('larena-env::admin.sites.state.'.\Larena\Env\Admin\SiteState::of($site->answersChecked, $site->answers)) }}"></sf-badge>
            <sf-badge appearance="outline" text="{{ in_array($site->profile, ['php', 'generic'], true) && $site->php !== 'none' ? 'PHP '.$site->php : __('larena-env::admin.new_site.profiles.'.$site->profile).($site->php !== 'none' ? ' · PHP '.$site->php : '') }}"></sf-badge>
            @if ($site->certificate !== null)
                <sf-badge status="{{ $site->certificate->daysLeft !== null && $site->certificate->daysLeft < \Larena\Env\Warnings\PanelWarnings::CERTIFICATE_DAYS ? 'warning' : 'success' }}" appearance="tonal"
                    text="{{ $site->certificate->daysLeft === null ? 'HTTPS' : __('larena-env::admin.sites.certificate_left', ['days' => (string) $site->certificate->daysLeft]) }}"></sf-badge>
            @else
                <sf-badge appearance="outline" text="{{ __('larena-env::admin.site.no_https') }}"></sf-badge>
            @endif
        </span>
    @endsection
    @section('actions')
        <a class="larena-button" href="{{ $site->certificate !== null ? 'https' : 'http' }}://{{ $domain }}/" target="_blank" rel="noopener noreferrer" data-env-site-open>{{ __('larena-env::admin.site.open') }}</a>
    @endsection
@endif

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($site === null)
        <section class="larena-panel larena-empty" data-env-state="{{ ($missing ?? false) ? 'missing' : ($refused ? 'refused' : 'failed') }}" role="alert">
            <h2 class="text-2">{{ ($missing ?? false) ? __('larena-env::admin.site.missing_title') : ($refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title')) }}</h2>
            <p>{{ $reasonText }}</p>
            <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
        </section>
    @else
        {{-- The site's sections are its menu in the menu's second column (owner decision 2026-11-05: never more than two
             menus); what needs attention is in the admin's alert bar. The page carries only the open section. --}}
        <div class="min-w-0" data-env-section="{{ $tab }}" data-env-sections="{{ implode(' ', $tabs) }}">
        @if ($tab === 'overview')
            <section class="larena-panel larena-panel-padded" data-env-state="ok" aria-labelledby="env-site-facts">
                <h2 id="env-site-facts" class="text-2">{{ __('larena-env::admin.site.facts') }}</h2>
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-answers="{{ $site->answersChecked ? ($site->answers ?? 'none') : 'unchecked' }}"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.answers') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.sites.state.'.\Larena\Env\Admin\SiteState::of($site->answersChecked, $site->answers)) }}{{ $site->answers !== null ? ' · '.__('larena-env::admin.sites.answers_code', ['code' => (string) $site->answers]) : '' }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.owner') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->owner ?? __('larena-env::admin.access.own_account') }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.profile') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.new_site.profiles.'.$site->profile) }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.php') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->php === 'none' ? __('larena-env::admin.site.php_none') : $site->php }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.ssl') }}</dt><dd class="m-0 min-w-0 text-end" data-env-https="{{ $site->certificate === null ? 'none' : 'on' }}">{{ $site->certificate === null ? __('larena-env::admin.sites.https_none') : ($site->certificate->daysLeft === null ? __('larena-env::admin.sites.https_on') : __('larena-env::admin.sites.https_yes', ['days' => (string) $site->certificate->daysLeft])) }}</dd></div>
                    @if ($site->databaseEngine !== null)
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.database') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.new_site.engines.'.$site->databaseEngine) === 'larena-env::admin.new_site.engines.'.$site->databaseEngine ? $site->databaseEngine : __('larena-env::admin.new_site.engines.'.$site->databaseEngine) }}</dd></div>
                    @endif
                    @if ($site->release !== null)
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.release') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->release ?? '—' }}</dd></div>
                    @endif
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-disk><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.disk') }}</dt><dd class="m-0 min-w-0 text-end">{{ \Larena\Env\Server\Bytes::human($site->diskBytes) }}</dd></div>
                </dl>
            </section>
            @if (($siteRoute ?? null) !== null)
                {{-- Where the site stands on its creation route (simai-env 1.56.0): its steps, the one it is on, and the next
                     one: an address the browser opens, a section of the card, or an action through its plan. --}}
                <section class="larena-panel larena-panel-padded" data-env-site-route="{{ $siteRoute['status']->route }}" data-env-site-step="{{ $siteRoute['status']->step }}" aria-labelledby="env-site-route">
                    <h2 id="env-site-route" class="text-2">{{ __('larena-env::admin.routes.site_title', ['route' => $siteRoute['route']]) }}</h2>
                    @if ($siteRoute['steps'] !== [])
                        <sf-steps class="m-bottom-2" current="{{ $siteRoute['current'] ?? 0 }}" aria-label="{{ __('larena-env::admin.routes.steps_title') }}" data-env-site-route-steps
                            items="{{ json_encode(array_map(static fn (array $step): array => ['label' => $step['label']], $siteRoute['steps']), JSON_UNESCAPED_UNICODE) }}"></sf-steps>
                    @endif
                    @if ($siteRoute['status']->step === 'done')
                        <p data-env-site-route-done>{{ __('larena-env::admin.routes.done') }}</p>
                        {{-- The site is made; without a certificate the next step is HTTPS, in «Домены и HTTPS» (block 4). --}}
                        @if ($site->certificate === null && in_array('domains', $tabs, true))
                            <p data-env-site-route-https>{{ __('larena-env::admin.routes.https_next') }}</p>
                            <p><a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=domains&amp;action={{ \Larena\Env\Operations\SiteOperation::CertificateIssue->value }}" class="larena-button larena-button-primary" data-env-site-route-https-go>{{ __('larena-env::admin.routes.https_go') }}</a></p>
                        @endif
                    @else
                        <p data-env-site-route-at>{{ __('larena-env::admin.routes.now', ['step' => $siteRoute['stepText']]) }}</p>
                        @if ($siteRoute['nextText'] !== null)
                            <p data-env-site-route-next="{{ $siteRoute['status']->next }}">{{ __('larena-env::admin.routes.next', ['step' => $siteRoute['nextText']]) }}</p>
                        @endif
                    @endif
                    @if ($siteRoute['pending'] !== [])
                        <p class="color-on-surface-variant" data-env-site-route-pending>{{ __('larena-env::admin.routes.pending', ['items' => implode(', ', $siteRoute['pending'])]) }}</p>
                    @endif
                    @if ($siteRoute['openUrl'] !== null)
                        <p><a href="{{ $siteRoute['openUrl'] }}" target="_blank" rel="noopener noreferrer" class="larena-button larena-button-primary" data-env-site-route-open>{{ $siteRoute['nextText'] }}</a></p>
                        <p class="text-small color-on-surface-variant">{{ __('larena-env::admin.routes.open_then') }}</p>
                    @elseif ($siteRoute['tab'] !== null)
                        <p><a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab={{ $siteRoute['tab'] }}" class="larena-button" data-env-site-route-tab="{{ $siteRoute['tab'] }}">{{ __('larena-env::admin.routes.go_tab.'.$siteRoute['tab']) }}</a></p>
                    @elseif ($siteRoute['outside'] ?? false)
                        <p class="color-on-surface-variant" data-env-site-route-outside>{{ __('larena-env::admin.routes.outside_note') }}</p>
                    @elseif ($siteRoute['operation'] !== null)
                        <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-site-route-step="{{ $siteRoute['operation']->value }}">
                            @csrf
                            <input type="hidden" name="operation" value="{{ $siteRoute['operation']->value }}">
                            @foreach ($siteRoute['fields'] as $field)
                                <div class="larena-field" data-env-site-route-field="{{ $field }}">
                                    @if ($field === 'migrate')
                                        <input type="hidden" name="migrate" value="no">
                                        <label for="env-route-migrate"><input type="checkbox" id="env-route-migrate" name="migrate" value="yes" checked> {{ __('larena-env::admin.routes.inputs.migrate') }}</label>
                                    @elseif (in_array($field, ['files', 'dump'], true) && ($siteRoute['copies'] ?? null) !== null)
                                        <label for="env-route-{{ $field }}">{{ __('larena-env::admin.routes.inputs.'.$field) }}</label>
                                        <select id="env-route-{{ $field }}" name="{{ $field }}" required>
                                            @foreach ($siteRoute['copies'][$field === 'files' ? 'files' : 'dumps'] as $found)
                                                <option value="{{ $found }}">{{ $found }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <label for="env-route-{{ $field }}">{{ __('larena-env::admin.routes.inputs.'.str_replace('-', '_', $field)) }}</label>
                                        <input type="{{ in_array($field, ['url', 'git'], true) ? 'url' : ($field === 'admin-email' ? 'email' : 'text') }}" id="env-route-{{ $field }}" name="{{ $field }}"
                                            value="{{ $siteRoute['values'][$field] ?? '' }}" autocomplete="off" @if (in_array($field, ['title', 'admin-user', 'admin-email', 'files', 'dump', 'git', 'ref'], true)) required @endif>
                                        @if (in_array($field, ['source', 'url'], true))
                                            <p class="text-small color-on-surface-variant">{{ __('larena-env::admin.routes.input_hints.'.$field) }}</p>
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                            <div class="larena-form-actions">
                                <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.operations.'.$siteRoute['operation']->value) }}</button>
                            </div>
                        </form>
                    @endif
                </section>
            @endif
            <section class="larena-panel larena-panel-padded" data-env-doctor="{{ $doctor?->status ?? 'unknown' }}" aria-labelledby="env-site-doctor">
                <h2 id="env-site-doctor" class="text-2">{{ __('larena-env::admin.doctor.title') }}</h2>
                @if ($doctor === null)
                    <p>{{ __('larena-env::admin.doctor.none') }}</p>
                @elseif ($doctor->problems === [])
                    <p>{{ __('larena-env::admin.doctor.clean', ['count' => (string) $doctor->passed]) }}</p>
                @else
                    <p>{{ __('larena-env::admin.doctor.summary', ['count' => (string) count($doctor->problems), 'passed' => (string) $doctor->passed]) }}</p>
                    <ul class="m-0 p-0" style="list-style: none;">
                        {{-- A rule of the site's profile (simai-env 1.55.1) says what the server found and since when, and where
                             the server offers a fix, «Исправить…» opens its plan; other checks are one line as before. --}}
                        @foreach (\Larena\Env\Admin\SiteChecks::problems($doctor, $checkFixes ?? false) as $problem)
                            <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-problem="{{ $problem['status'] }}"@if ($problem['key'] !== null) data-env-rule="{{ $problem['key'] }}"@endif>
                                <sf-badge appearance="tonal" status="{{ $problem['tone'] }}" text="{{ __($problem['label']) }}"></sf-badge>
                                <span class="grow min-w-0" style="overflow-wrap: anywhere;" data-env-check="{{ $problem['title'] }}">{{ $problem['text'] }}@if ($problem['detail'] !== null || $problem['since'] !== null)<span class="text-small color-on-surface-variant" style="display: block;" data-env-check-detail>{{ $problem['detail'] }}{{ $problem['detail'] !== null && $problem['since'] !== null ? ' · ' : '' }}{{ $problem['since'] !== null ? __('larena-env::admin.doctor.since', ['time' => $problem['since']]) : '' }}</span>@endif</span>
                                <span class="text-small color-on-surface-variant">{{ $problem['area'] }}</span>
                                @if ($problem['fix'] !== null)
                                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="m-0" data-env-check-fix="{{ $problem['fix'] }}">
                                        @csrf
                                        <input type="hidden" name="operation" value="{{ \Larena\Env\Operations\SiteOperation::CheckFix->value }}">
                                        <input type="hidden" name="id" value="{{ $problem['fix'] }}">
                                        <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::admin.doctor.fix') }}</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (\Larena\Env\Admin\SiteChecks::passed($doctor) !== [])
                    <details class="m-t-1" data-env-rules-passed>
                        <summary class="larena-control-floor" style="cursor: pointer; display: flex; align-items: center;">{{ __('larena-env::admin.doctor.passed_rules', ['count' => (string) count(\Larena\Env\Admin\SiteChecks::passed($doctor))]) }}</summary>
                        <ul class="m-0 p-0" style="list-style: none;">
                            @foreach (\Larena\Env\Admin\SiteChecks::passed($doctor) as $rule)
                                <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-rule="{{ $rule['key'] }}" data-env-rule-state="{{ $rule['status'] }}">
                                    <span class="grow min-w-0" style="overflow-wrap: anywhere;">{{ $rule['text'] }}</span>
                                    <span class="text-small color-on-surface-variant" style="overflow-wrap: anywhere;">{{ $rule['detail'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        @elseif ($tab === 'domains')
            @if (($siteNames ?? null) !== null)
                @include('larena-env::admin.partials.site-names')
            @endif
            <section class="larena-panel larena-panel-padded" data-env-certificate="{{ $site->certificate ? 'present' : 'none' }}" aria-labelledby="env-site-certificate">
                <h2 id="env-site-certificate" class="text-2">{{ __('larena-env::admin.certificate.title') }}</h2>
                @if ($site->certificate === null)
                    <p>{{ __('larena-env::admin.certificate.none') }}</p>
                    {{-- Where the domain must lead, and the certificate right here: the same action as among the tab's
                         buttons below, opening its side panel (block 4, texts for a newcomer). --}}
                    <p data-env-domain-point>{{ ($serverIp ?? null) !== null ? __('larena-env::admin.point_domain.ip', ['ip' => $serverIp]) : __('larena-env::admin.point_domain.unknown') }}</p>
                    @if (in_array(\Larena\Env\Operations\SiteOperation::CertificateIssue, $tabOperations, true))
                        <p>{{ __('larena-env::admin.certificate.get_now') }}</p>
                        <p><button type="button" class="larena-button larena-button-primary" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::CertificateIssue->value }}" data-env-certificate-get>{{ __('larena-env::admin.operations.certificate-issue') }}</button></p>
                    @endif
                @else
                    <dl class="m-0" style="display: block;" data-env-facts>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.certificate.names') }}</dt><dd class="m-0 min-w-0 text-end">{{ implode(', ', $site->certificate->names) }}</dd></div>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.certificate.issuer') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->certificate->issuer ?? '—' }}</dd></div>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.certificate.not_after') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->certificate->notAfter ?? '—' }}</dd></div>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.certificate.days_left') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->certificate->daysLeft ?? '—' }}</dd></div>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.certificate.auto_renew') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->certificate->autoRenew ? __('larena-env::admin.yes') : __('larena-env::admin.no') }}</dd></div>
                        @if ($site->certificate->lastRenewOk !== null)
                            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-certificate-renew="{{ $site->certificate->lastRenewOk ? 'ok' : 'failed' }}"><dt class="color-on-surface-variant">{{ __('larena-env::admin.certificate.last_renew') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->certificate->lastRenewAt === null ? '—' : \Larena\Env\Server\Moment::human($site->certificate->lastRenewAt) }} · {{ $site->certificate->lastRenewOk ? __('larena-env::admin.certificate.renew_ok') : __('larena-env::admin.certificate.renew_failed') }}</dd></div>
                        @endif
                    </dl>
                    @if ($site->certificate->endsSoon())
                        <p data-env-certificate-warning role="alert">{{ __('larena-env::admin.certificate.ends_soon') }}</p>
                    @endif
                @endif
            </section>
            @include('larena-env::admin.partials.site-dns', ['siteDns' => $siteDns ?? null])
        @elseif ($tab === 'php')
            <section class="larena-panel larena-panel-padded" data-env-runtime aria-labelledby="env-site-runtime">
                <h2 id="env-site-runtime" class="text-2">{{ __('larena-env::admin.runtime.title') }}</h2>
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.php') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->php === 'none' ? __('larena-env::admin.site.php_none') : $site->php }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.runtime.service') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->service ?? '—' }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.runtime.workers') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->workers === [] ? '—' : implode(', ', $site->workers) }}</dd></div>
                    @if ($site->webServer !== null)
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-web-server="{{ $site->webServer }}"><dt class="color-on-surface-variant">{{ __('larena-env::admin.runtime.web_server') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.runtime.web_servers.'.$site->webServer) }}</dd></div>
                    @endif
                </dl>
            </section>
            @if ($phpSettings !== null)
                <section class="larena-panel larena-panel-padded" data-env-php-settings aria-labelledby="env-site-php-settings">
                    <h2 id="env-site-php-settings" class="text-2">{{ __('larena-env::admin.runtime.settings') }}</h2>
                    @if ($phpSettings === [])
                        <p>{{ __('larena-env::admin.runtime.settings_none') }}</p>
                    @else
                        <dl class="m-0" style="display: block;" data-env-facts>
                            @foreach ($phpSettings as $setting)
                                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-php-setting="{{ $setting->key }}"><dt class="color-on-surface-variant">{{ $setting->key }}</dt><dd class="m-0 min-w-0 text-end">{{ $setting->value }}@if ($setting->source !== null) · {{ $setting->source }}@endif</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endif
            @if ($phpExtensions !== null)
                <section class="larena-panel larena-panel-padded" data-env-php-extensions aria-labelledby="env-site-php-extensions">
                    <h2 id="env-site-php-extensions" class="text-2">{{ __('larena-env::admin.runtime.extensions', ['php' => $site->php]) }}</h2>
                    <ul class="m-0 p-0 grid g-x-3 grid-col-1 md:grid-col-2" style="list-style: none;">
                        @foreach ($phpExtensions as $extension)
                            <li class="flex items-cross-center content-main-between g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-php-extension="{{ $extension->name }}">
                                <span class="mono min-w-0" style="overflow-wrap: anywhere;">{{ $extension->name }}</span>
                                <sf-badge appearance="tonal" status="{{ $extension->enabled ? 'success' : 'neutral' }}" text="{{ $extension->enabled ? __('larena-env::admin.runtime.on') : __('larena-env::admin.runtime.off') }}"></sf-badge>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            {{-- The site's own nginx directives, the system level's, beside PHP and the web server (owner decision 2026-11-05). --}}
            @if ($wholeServer ?? false)
                @include('larena-env::admin.partials.nginx')
            @endif
        @elseif ($tab === 'database')
            <section class="larena-panel larena-panel-padded" data-env-database="{{ $site->databaseEngine ?? 'none' }}" aria-labelledby="env-site-database">
                <h2 id="env-site-database" class="text-2">{{ __('larena-env::admin.database.title') }}</h2>
                {{-- The site's one database (simai-env 1.59.0): its name and size from the server's list, and the block's own
                     actions — «Данные для подключения» (the password shown once, never kept by the panel), «Сменить пароль»;
                     «Создать базу» only for a site without one. A second database is never offered. --}}
                @if ($site->databaseEngine === null)
                    <p>{{ __('larena-env::admin.database.none') }}</p>
                    @if (in_array(\Larena\Env\Operations\SiteOperation::DatabaseCreate, $tabOperations, true))
                        <p class="m-0 m-bottom-1 text-small color-on-surface-variant">{{ __('larena-env::admin.database.create_note') }}</p>
                        <p class="flex flex-wrap g-1"><button type="button" class="larena-button larena-button-primary" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::DatabaseCreate->value }}" data-env-database-create>{{ __('larena-env::admin.database.actions.create') }}</button></p>
                    @endif
                @else
                    <dl class="m-0" style="display: block;" data-env-facts>
                        @if (($databases[0] ?? null) !== null)
                            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.name') }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;" data-env-database-name>{{ $databases[0]->name }}</dd></div>
                        @endif
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.engine') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.new_site.engines.'.$site->databaseEngine) === 'larena-env::admin.new_site.engines.'.$site->databaseEngine ? $site->databaseEngine : __('larena-env::admin.new_site.engines.'.$site->databaseEngine) }}</dd></div>
                        @if (($databases[0] ?? null) !== null)
                            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.size') }}</dt><dd class="m-0 min-w-0 text-end" data-env-database-size>{{ \Larena\Env\Server\Bytes::human($databases[0]->sizeBytes) }}</dd></div>
                            @if ($databases[0]->users !== [])
                                <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.users') }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;">{{ implode(', ', $databases[0]->users) }}</dd></div>
                            @endif
                        @endif
                    </dl>
                    <p class="flex flex-wrap g-1 m-top-1" data-env-database-actions>
                        @if (in_array(\Larena\Env\Operations\SiteOperation::DatabaseCredentials, $tabOperations, true))
                            <button type="button" class="larena-button larena-button-primary" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::DatabaseCredentials->value }}" data-env-database-credentials>{{ __('larena-env::admin.database.actions.credentials') }}</button>
                        @endif
                        @if (in_array(\Larena\Env\Operations\SiteOperation::DatabasePassword, $tabOperations, true))
                            <button type="button" class="larena-button" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::DatabasePassword->value }}" data-env-database-rotate>{{ __('larena-env::admin.database.actions.rotate') }}</button>
                        @endif
                    </p>
                    <p class="m-0 m-top-1 text-small color-on-surface-variant">{{ __('larena-env::admin.database.secrets') }}</p>
                @endif
                {{-- «Открыть базу» (simai-env 1.58.0): Adminer on the site's HTTPS address for 15 minutes, an hour or four, signed in
                     to this database only; its address is shown once, on the run's screen. Open, it says until when and offers
                     «Закрыть»; what stops it (no database, no PHP, an alias, paused, no HTTPS) is said in words, with the
                     certificate's own action where HTTPS is what is missing. --}}
                @if (($dbBrowser ?? null) !== null)
                    <div class="m-top-2 p-top-2 border-top-1 border-top-solid border-outline-variant" data-env-db-browser="{{ $dbBrowser['state']?->open ? 'open' : ($dbBrowser['refusal'] ?? 'closed') }}">
                        <h3 class="m-top-0 text-medium">{{ __('larena-env::admin.db_browser.title') }}</h3>
                        @if ($dbBrowser['state']?->open)
                            <p data-env-db-browser-until>{{ __('larena-env::admin.db_browser.open_until') }}
                                @if (\Larena\Env\Server\Moment::iso($dbBrowser['state']->expiresAt) !== null)<sf-time datetime="{{ \Larena\Env\Server\Moment::iso($dbBrowser['state']->expiresAt) }}" format="time"></sf-time>@else{{ \Larena\Env\Server\Moment::human($dbBrowser['state']->expiresAt) }}@endif
                            </p>
                            <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.db_browser.open_note') }}</p>
                            <p class="flex flex-wrap g-1 m-top-1">
                                @if (in_array(\Larena\Env\Operations\SiteOperation::DatabaseBrowse, $tabOperations, true))
                                    <button type="button" class="larena-button" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::DatabaseBrowse->value }}" data-env-db-browser-reopen>{{ __('larena-env::admin.db_browser.reopen') }}</button>
                                @endif
                                @if (in_array(\Larena\Env\Operations\SiteOperation::DatabaseBrowseClose, $tabOperations, true))
                                    <button type="button" class="larena-button" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::DatabaseBrowseClose->value }}" data-env-db-browser-close>{{ __('larena-env::admin.db_browser.close') }}</button>
                                @endif
                            </p>
                        @elseif ($dbBrowser['refusal'] !== null)
                            <p role="note" data-env-db-browser-refusal="{{ $dbBrowser['refusal'] }}">{{ __('larena-env::admin.db_browser.refusals.'.$dbBrowser['refusal']) }}</p>
                            @if ($dbBrowser['refusal'] === 'no_https' && $dbBrowser['certificate'])
                                <p><a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=domains&amp;action={{ \Larena\Env\Operations\SiteOperation::CertificateIssue->value }}" class="larena-button larena-button-primary" data-env-db-browser-certificate>{{ __('larena-env::admin.db_browser.certificate') }}</a></p>
                            @endif
                        @else
                            <p>{{ __('larena-env::admin.db_browser.explain') }}</p>
                            @if (in_array(\Larena\Env\Operations\SiteOperation::DatabaseBrowse, $tabOperations, true))
                                <p><button type="button" class="larena-button larena-button-primary" data-sf-modal-open="env-op-{{ \Larena\Env\Operations\SiteOperation::DatabaseBrowse->value }}" data-env-db-browser-open>{{ __('larena-env::admin.db_browser.open') }}</button></p>
                            @endif
                        @endif
                    </div>
                @endif
            </section>
        @elseif ($tab === 'backups')
            <section class="larena-panel larena-panel-padded" data-env-backups aria-labelledby="env-site-backups">
                <h2 id="env-site-backups" class="text-2">{{ __('larena-env::admin.backups.title') }}</h2>
                {{-- One line with the main reason, as the sites list and the backups page say it (critics of 0.4.1: three
                     warnings at once, one of them false): a failed attempt, else none yet, else one older than two days. --}}
                @if (($backupState ?? null) !== null)
                    <p data-env-backup-state="{{ $backupState['state'] }}" @if ($backupState['color'] === 'danger') role="alert" @endif>
                        <sf-badge appearance="tonal" status="{{ $backupState['color'] === 'danger' ? 'error' : ($backupState['color'] === 'success' ? 'success' : 'info') }}" text="{{ $backupState['words'] }}"></sf-badge>
                    </p>
                @endif
                @if ($site->backupSchedule === null)
                    <p>{{ __('larena-env::admin.backups.no_schedule') }}</p>
                @else
                    <p>{{ __('larena-env::admin.backups.schedule', ['time' => $site->backupSchedule->time ?? '—', 'keep' => (string) ($site->backupSchedule->keep ?? '—')]) }}
                        @if ($site->backupSchedule->verify !== null)
                            <span data-env-backup-verify="{{ $site->backupSchedule->verify ? 'yes' : 'no' }}">{{ __('larena-env::admin.backups.'.($site->backupSchedule->verify ? 'verify_on' : 'verify_off')) }}</span>
                        @endif
                    </p>
                @endif
                @if ($site->lastVerify !== null)
                    <p data-env-last-verify="{{ $site->lastVerify->ok ? 'ok' : 'failed' }}">
                        {{ __('larena-env::admin.backups.'.($site->lastVerify->ok ? 'last_verify_ok' : 'last_verify_failed'), ['time' => $site->lastVerify->at === null ? '—' : \Larena\Env\Server\Moment::human($site->lastVerify->at)]) }}
                        {{ __('larena-env::admin.backups.'.($site->lastVerify->restoreTest ? 'restore_tested' : 'restore_not_tested')) }}
                    </p>
                @endif
                @if ($site->lastOffsite !== null)
                    {{-- simai-env 1.44.0: the last copy sent away from the server, to the storage it went to. --}}
                    <p data-env-last-offsite="{{ $site->lastOffsite['ok'] ? 'ok' : 'failed' }}" @if (!$site->lastOffsite['ok']) role="alert" @endif>
                        {{ __('larena-env::admin.backups.'.($site->lastOffsite['ok'] ? 'offsite_ok' : 'offsite_failed'), ['time' => \Larena\Env\Server\Moment::human($site->lastOffsite['at']),
                            'storage' => $site->lastOffsite['storage'] ?? '—', 'error' => $site->lastOffsite['error'] ?? '—']) }}
                        <a href="{{ route('larena.env.admin.storages', $site->lastOffsite['storage'] === null ? [] : ['storage' => $site->lastOffsite['storage']], false) }}">{{ __('larena-env::admin.backups.offsite_open') }}</a>
                    </p>
                @endif
                {{-- A copy not sent away from the server is another matter than the backup itself; said here only when the
                     site's description has no last sending above. --}}
                @if ($site->lastOffsite === null)
                    @foreach (array_filter($warnings ?? [], static fn ($warning): bool => $warning->kind === 'backup_offsite_failed' && $warning->domain === $domain) as $backupWarning)
                        <p role="alert" data-env-backup-warning="{{ $backupWarning->kind }}">{{ __('larena-env::admin.warnings.'.$backupWarning->kind, $backupWarning->replace) }}</p>
                    @endforeach
                @endif
                @if ($site->backups !== [])
                    <ul class="m-0 p-0" style="list-style: none;">
                        @foreach ($site->backups as $backup)
                            <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-backup="{{ $backup->name }}">
                                <span class="grow min-w-0">{{ $backup->createdAt !== null ? \Larena\Env\Server\Moment::human($backup->createdAt) : $backup->name }}</span>
                                @if ($backup->encrypted)
                                    <sf-badge appearance="tonal" status="info" text="{{ __('larena-env::admin.backups.encrypted') }}"></sf-badge>
                                @endif
                                <span class="text-small color-on-surface-variant">{{ \Larena\Env\Server\Bytes::human($backup->sizeBytes) }} · {{ implode(', ', array_map(static fn (string $part): string => __('larena-env::admin.backups.parts.'.$part) === 'larena-env::admin.backups.parts.'.$part ? $part : __('larena-env::admin.backups.parts.'.$part), $backup->parts)) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @elseif ($tab === 'cron' && ($cron ?? null) !== null)
            @include('larena-env::admin.partials.cron')
        @elseif ($tab === 'files')
            @include('larena-env::admin.partials.files')
        @elseif ($tab === 'releases')
            <section class="larena-panel larena-panel-padded" data-env-releases aria-labelledby="env-site-releases">
                <h2 id="env-site-releases" class="text-2">{{ __('larena-env::admin.releases.title') }}</h2>
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.release') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->release ?? '—' }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.site.previous_release') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->previousRelease ?? '—' }}</dd></div>
                </dl>
                @if ($site->release === null)
                    <p>{{ __('larena-env::admin.releases.in_place') }}</p>
                @endif
            </section>
        @elseif ($tab === 'log')
            {{-- «Журнал сайта» (PX-48): the site's errors first, the reader's own time, 50 rows and «Ещё», the answer as a
                 badge, the line as the server wrote it on a press, and «Скачать журнал»; the rows are drawn by the
                 admin's one log viewer (larena-admin::history.log). --}}
            <section class="larena-panel larena-panel-padded" data-env-log="{{ $logView }}" aria-labelledby="env-site-log">
                <h2 id="env-site-log" class="text-2">{{ __('larena-env::admin.log.title') }}</h2>
                <form method="get" action="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}" class="larena-form" data-env-log-filter role="search">
                    <input type="hidden" name="tab" value="log">
                    <div class="larena-field">
                        <label for="env-log-kind">{{ __('larena-env::admin.log.kind') }}</label>
                        <select id="env-log-kind" name="log">
                            @foreach ($logViews as $view)
                                <option value="{{ $view }}" @if ($view === $logView) selected @endif>{{ __('larena-env::admin.log.views.'.$view) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-log-q">{{ __('larena-env::admin.log.search') }}</label>
                        <input type="search" id="env-log-q" name="q" value="{{ $logSearch }}" maxlength="128">
                    </div>
                    <div class="larena-field">
                        <label for="env-log-group">{{ __('larena-env::admin.log.'.($logKind === 'error' ? 'level' : 'status')) }}</label>
                        <select id="env-log-group" name="group">
                            <option value="">{{ __('larena-env::admin.log.all') }}</option>
                            @foreach ($logGroups as $group)
                                <option value="{{ $group }}" @if ($group === $logGroup) selected @endif>{{ $logKind === 'error' ? __('larena-env::admin.log.levels.'.$group) : __('larena-env::admin.log.groups.'.$group) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-field">
                        <label for="env-log-lines">{{ __('larena-env::admin.log.lines') }}</label>
                        <select id="env-log-lines" name="lines">
                            @foreach ($logLineChoices as $count)
                                <option value="{{ $count }}" @if ($count === $logLines) selected @endif>{{ $count }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="larena-form-actions">
                        <button type="submit" class="larena-button">{{ __('larena-env::admin.log.show') }}</button>
                        <button type="submit" name="fresh" value="1" class="larena-button" data-env-log-refresh>{{ __('larena-env::admin.log.refresh') }}</button>
                        @if ($log !== null && $log->total > 0)
                            <a href="{{ $logDownload }}" class="larena-button" download data-env-log-download>{{ __('larena-env::admin.log.download') }}</a>
                        @endif
                    </div>
                </form>
                @if ($log === null)
                    <p>{{ $logReasonText ?? __('larena-env::admin.log.none') }}</p>
                @elseif ($log->total === 0)
                    <p>{{ __('larena-env::admin.log.empty') }}</p>
                @elseif ($logRows === [])
                    <p data-env-log-none>{{ __('larena-env::admin.log.'.($logView === 'problems' ? 'no_problems' : 'nothing_found'), ['total' => (string) $log->total]) }}</p>
                @else
                    @include('larena-admin::history.log', ['rows' => $logRows, 'label' => __('larena-env::admin.log.title'), 'zone' => $logZone ?? null])
                    <p class="color-on-surface-variant" data-env-log-count>
                        {{ __('larena-env::admin.log.count', ['shown' => (string) count($logRows), 'found' => (string) count($log->rows), 'total' => (string) $log->total]) }}
                        @if ($logMore !== null) · <a href="{{ $logMore }}" data-env-log-more>{{ __('larena-env::admin.log.more') }}</a>@endif
                    </p>
                @endif
            </section>
        @elseif ($tab === 'access')
            <section class="larena-panel larena-panel-padded" data-env-access aria-labelledby="env-site-access">
                <h2 id="env-site-access" class="text-2">{{ __('larena-env::admin.access.title') }}</h2>
                <dl class="m-0" style="display: block;" data-env-facts>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.owner') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->owner ?? __('larena-env::admin.access.own_account') }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.access.runs_as') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->runsAs ?? '—' }}</dd></div>
                </dl>
                <p>{{ __('larena-env::admin.access.explain') }}</p>
                @if ($sftpAccounts !== null)
                    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.access.sftp') }}</h3>
                    @if ($sftpAccounts === [])
                        <p>{{ __('larena-env::admin.access.sftp_none') }}</p>
                    @else
                        <ul class="m-0 p-0" style="list-style: none;">
                            @foreach ($sftpAccounts as $account)
                                <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-sftp="{{ $account->login }}" data-env-sftp-path="{{ $account->path ?? '' }}">
                                    <span class="mono">{{ $account->login }}</span>
                                    <sf-badge appearance="tonal" status="{{ $account->enabled ? 'success' : 'neutral' }}" text="{{ $account->enabled ? __('larena-env::admin.access.on') : __('larena-env::admin.access.off') }}"></sf-badge>
                                    <span class="grow min-w-0 text-small color-on-surface-variant" style="overflow-wrap: anywhere;">{{ __('larena-env::admin.access.by_key') }}@if ($account->path !== null) · {{ __('larena-env::admin.access.lands', ['path' => $account->path]) }}@endif @if ($account->createdAt !== null) · {{ __('larena-env::admin.access.created', ['when' => \Larena\Env\Server\Moment::human($account->createdAt)]) }}@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <div data-env-sftp-uploads class="m-bottom-1">@include('larena-env::admin.partials.explanation', ['text' => __('larena-env::admin.access.uploads')])</div>
                @endif
            </section>
        @endif

        @if ($tabOperations !== [])
            {{-- The database browser and the database's own actions are offered by the «База данных» block, not the row. --}}
            <section class="larena-panel larena-panel-padded" data-env-operations @if (\Larena\Env\Operations\SiteOperation::inRow($tabOperations, $sftpChoices ?? []) !== []) aria-labelledby="env-site-operations" @else style="display: contents;" @endif>
                @if (\Larena\Env\Operations\SiteOperation::inRow($tabOperations, $sftpChoices ?? []) !== [])
                <h2 id="env-site-operations" class="text-2">{{ __('larena-env::admin.operations.title') }}</h2>
                {{-- The tab's actions as a row of buttons; each opens its fields in a side panel, and the plan comes next
                     (the panel's UX audit, LAY-03): the tab is no longer a column of long forms. --}}
                <div class="flex flex-wrap g-1" data-env-operation-buttons>
                    @foreach (\Larena\Env\Operations\SiteOperation::inRow($tabOperations, $sftpChoices ?? []) as $operation)
                        <button type="button" class="larena-button @if ($operation->destroys()) larena-button-danger @endif" data-sf-modal-open="env-op-{{ $operation->value }}" data-env-operation-open="{{ $operation->value }}">{{ __('larena-env::admin.operations.'.$operation->value) }}</button>
                    @endforeach
                </div>
                @endif
                @foreach ($tabOperations as $operation)
                    @continue(!$operation->namesSite() && ($sftpChoices[$operation->value] ?? []) === [])
                    {{-- «Действие с планом» (larena/admin AdminPlannedAction): the form, its plan, the question and the run stay in this
                         side panel over the site (0 page loads, one question); its holder stays, the panel takes its content once.
                         An action with nothing to fill asks its question at once; ?action=<code> opens it with the page (PX-15). --}}
                    <sf-modal id="env-op-{{ $operation->value }}" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.operations.'.$operation->value) }}" @if (($openAction ?? null) === $operation->value) autoload @endif>
                    <div data-larena-action-host>
                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0; border-radius: 0;" data-env-operation="{{ $operation->value }}" data-larena-action-form @if ($operation->asksNothing(($capabilities ?? null) === null ? null : $capabilities, ($personEmail ?? null) !== null)) data-larena-action-auto @endif>
                        @csrf
                        <input type="hidden" name="operation" value="{{ $operation->value }}">
                        @foreach (($capabilities ?? null) === null ? $operation->fields() : $operation->fieldsOn($capabilities) as $field)
                            @continue(!($dnsCertificates ?? false) && in_array($field, \Larena\Env\Operations\SiteOperation::DNS_FIELDS, true))
                            {{-- A site without a database has none to drop with it. --}}
                            @continue(in_array($field, ['drop-db', 'drop-db-user'], true) && $site->databaseEngine === null)
                            {{-- A certificate's rarely needed choices (HSTS, a test certificate, the way the domain is checked,
                                 every subdomain) stand together under «Подробнее», all off (block 4); they close after the loop. --}}
                            @if ($field === \Larena\Env\Operations\SiteOperation::MORE[0] && $operation === \Larena\Env\Operations\SiteOperation::CertificateIssue)
                                <details class="m-y-1" data-env-certificate-more>
                                <summary class="color-primary" style="cursor: pointer;">{{ __('larena-env::admin.more') }}</summary>
                            @endif
                            @if (in_array($field, \Larena\Env\Operations\SiteOperation::SWITCHES, true))
                                <div class="larena-field" data-env-switch="{{ $field }}">
                                    <input type="hidden" name="{{ $field }}" value="no">
                                    <label class="flex items-cross-center g-1" for="env-{{ $operation->value }}-{{ $field }}">
                                        <input type="checkbox" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" value="yes" @if (\Larena\Env\Operations\SiteOperation::switchedOn($field, $site)) checked @endif @if ($field === 'verify') data-env-verify-field @endif>
                                        <span>{{ __('larena-env::admin.fields.'.$field) }}</span>
                                    </label>
                                    @if (in_array($field, ['hsts', 'staging'], true))
                                        <p class="m-0 text-small color-on-surface-variant" @if ($field === 'staging') role="note" @endif data-env-switch-hint="{{ $field }}">{{ __('larena-env::admin.fields.'.$field.'_hint') }}</p>
                                    @endif
                                </div>
                                @continue
                            @endif
                            <div class="larena-field">
                                <label for="env-{{ $operation->value }}-{{ $field }}">{{ __('larena-env::admin.fields.'.$field) }}</label>
                                @if ($field === 'email')
                                    {{-- Optional: the person's own email is sent when the field is left empty (block 4). --}}
                                    <input type="email" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" value="{{ $personEmail ?? '' }}" @if (($personEmail ?? null) === null) required @endif>
                                    @if (($personEmail ?? null) !== null)
                                        <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.fields.email_hint') }}</p>
                                    @endif
                                @elseif ($field === 'php')
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required>
                                        @foreach ($phpVersions as $version)
                                            <option value="{{ $version }}" @if ($version === $site->php) selected @endif>{{ $version }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field === 'backup')
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required>
                                        @foreach ($site->backups as $backup)
                                            <option value="{{ $backup->name }}">{{ $backup->createdAt !== null ? \Larena\Env\Server\Moment::human($backup->createdAt) : $backup->name }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field === 'login' && $operation !== \Larena\Env\Operations\SiteOperation::SftpCreate)
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required>
                                        @foreach ($sftpChoices[$operation->value] ?? [] as $account)
                                            <option value="{{ $account->login }}">{{ $account->login }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field === 'pubkey')
                                    <textarea id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" rows="3" required></textarea>
                                @elseif ($field === 'time')
                                    <input type="time" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" value="{{ $site->backupSchedule?->time ?? '03:00' }}">
                                @elseif ($field === 'verify')
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" data-env-verify-field>
                                        <option value="yes" @if ($site->backupSchedule?->verify !== false) selected @endif>{{ __('larena-env::admin.yes') }}</option>
                                        <option value="no" @if ($site->backupSchedule?->verify === false) selected @endif>{{ __('larena-env::admin.no') }}</option>
                                    </select>
                                @elseif ($field === 'challenge')
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" aria-describedby="env-{{ $operation->value }}-{{ $field }}-hint" data-env-challenge>
                                        <option value="http">{{ __('larena-env::admin.fields.challenge_http') }}</option>
                                        <option value="dns">{{ __('larena-env::admin.fields.challenge_dns') }}</option>
                                    </select>
                                    <p id="env-{{ $operation->value }}-{{ $field }}-hint" class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.fields.challenge_hint') }}</p>
                                @elseif ($field === 'minutes')
                                    {{-- Three terms (the server takes 5 to 480 minutes): an hour first. --}}
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" data-env-db-browser-minutes>
                                        @foreach (['15', '60', '240'] as $minutes)
                                            <option value="{{ $minutes }}" @if ($minutes === '60') selected @endif>{{ __('larena-env::admin.db_browser.minutes.'.$minutes) }}</option>
                                        @endforeach
                                    </select>
                                    <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::admin.db_browser.minutes_hint') }}</p>
                                @elseif ($field === 'keep')
                                    <input type="number" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" min="1" max="90" value="{{ $site->backupSchedule?->keep ?? '7' }}">
                                @elseif ($field === 'code')
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}">
                                        @foreach (['301', '302', '307', '308'] as $code)
                                            <option value="{{ $code }}">{{ __('larena-env::admin.routes.values.'.$code) }}</option>
                                        @endforeach
                                    </select>
                                @elseif (in_array($field, ['from', 'to'], true))
                                    <input type="text" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required maxlength="500" placeholder="{{ __('larena-env::admin.fields.'.$field.'_placeholder') }}" autocomplete="off">
                                @elseif (in_array($field, ['login', 'name', 'value'], true))
                                    <input type="text" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" @if ($field !== 'value') required @endif maxlength="128">
                                @else
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" @if ($field === 'enabled') required @endif>
                                        @if ($field !== 'enabled')
                                            <option value="">{{ __('larena-env::admin.fields.default') }}</option>
                                        @endif
                                        <option value="yes">{{ __('larena-env::admin.yes') }}</option>
                                        <option value="no">{{ __('larena-env::admin.no') }}</option>
                                    </select>
                                @endif
                            </div>
                        @endforeach
                        @if ($operation === \Larena\Env\Operations\SiteOperation::CertificateIssue && in_array(\Larena\Env\Operations\SiteOperation::MORE[0], ($capabilities ?? null) === null ? $operation->fields() : $operation->fieldsOn($capabilities), true))
                            </details>
                        @endif
                        @if ($operation === \Larena\Env\Operations\SiteOperation::BackupRestoreDatabase)
                            <p data-env-restore-note>{{ __('larena-env::admin.backups.restore_note') }}</p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::BackupExtractFiles)
                            <p data-env-restore-note>{{ __('larena-env::admin.backups.extract_note') }}</p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::ApacheOn)
                            <p class="m-0" data-env-apache-note>{{ __('larena-env::admin.runtime.apache_on_note') }}</p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::ApacheOff)
                            <p class="m-0" data-env-apache-note>{{ __('larena-env::admin.runtime.apache_off_note') }}</p>
                        @elseif (in_array($operation, [\Larena\Env\Operations\SiteOperation::SiteRemove, \Larena\Env\Operations\SiteOperation::DatabaseDrop], true))
                            {{-- «Сначала сделайте копию» leads to where a copy is made: the site's backups tab, where the server
                                 can make one (audit msg-15f90f9f). --}}
                            <p class="m-0" role="note" data-env-remove-note>{{ __('larena-env::admin.operations.'.($operation === \Larena\Env\Operations\SiteOperation::SiteRemove ? 'site_remove_note' : 'database_drop_note')) }}
                                @if (($capabilities ?? null) === null || $capabilities->allows(\Larena\Env\Operations\SiteOperation::Backup->command()))
                                    <a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=backups" data-env-remove-backup>{{ __('larena-env::admin.operations.make_backup') }}</a>
                                @endif
                            </p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::RedirectRemove)
                            <p class="m-0 text-small color-on-surface-variant" data-env-redirect-note>{{ __('larena-env::admin.operations.redirect_remove_note') }}</p>
                        @endif
                        <div class="larena-form-actions">
                            <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
                        </div>
                    </form>
                    </div>
                    </sf-modal>
                @endforeach
            </section>
        @endif
        </div>
    @endif
@endsection
