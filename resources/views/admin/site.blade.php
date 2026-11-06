@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.site.title', ['domain' => $domain]))
@section('heading', $domain)
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
        <section class="larena-panel larena-empty" data-env-state="{{ $refused ? 'refused' : 'failed' }}" role="alert">
            <h2 class="text-2">{{ $refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title') }}</h2>
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
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.owner') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->owner ?? '—' }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.profile') }}</dt><dd class="m-0 min-w-0 text-end">{{ __('larena-env::admin.new_site.profiles.'.$site->profile) }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.php') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->php }}</dd></div>
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.ssl') }}</dt><dd class="m-0 min-w-0 text-end" data-env-https="{{ $site->certificate === null ? 'none' : 'on' }}">{{ $site->certificate === null ? __('larena-env::admin.sites.https_none') : ($site->certificate->daysLeft === null ? __('larena-env::admin.sites.https_on') : __('larena-env::admin.sites.https_yes', ['days' => (string) $site->certificate->daysLeft])) }}</dd></div>
                    @if ($site->databaseEngine !== null)
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.database') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->databaseEngine ?? '—' }}</dd></div>
                    @endif
                    @if ($site->release !== null)
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.release') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->release ?? '—' }}</dd></div>
                    @endif
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-disk><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.disk') }}</dt><dd class="m-0 min-w-0 text-end">{{ \Larena\Env\Server\Bytes::human($site->diskBytes) }}</dd></div>
                </dl>
            </section>
            <section class="larena-panel larena-panel-padded" data-env-doctor="{{ $doctor?->status ?? 'unknown' }}" aria-labelledby="env-site-doctor">
                <h2 id="env-site-doctor" class="text-2">{{ __('larena-env::admin.doctor.title') }}</h2>
                @if ($doctor === null)
                    <p>{{ __('larena-env::admin.doctor.none') }}</p>
                @elseif ($doctor->problems === [])
                    <p>{{ __('larena-env::admin.doctor.clean', ['count' => (string) $doctor->passed]) }}</p>
                @else
                    <p>{{ __('larena-env::admin.doctor.summary', ['count' => (string) count($doctor->problems), 'passed' => (string) $doctor->passed]) }}</p>
                    <ul class="m-0 p-0" style="list-style: none;">
                        @foreach ($doctor->problems as $problem)
                            <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-problem="{{ $problem['status'] }}">
                                <sf-badge appearance="tonal" status="{{ str_contains(strtolower($problem['status']), 'fail') || str_contains(strtolower($problem['status']), 'err') ? 'error' : 'warning' }}" text="{{ __('larena-env::admin.doctor.'.strtolower($problem['status'])) }}"></sf-badge>
                                <span class="grow min-w-0" style="overflow-wrap: anywhere;" data-env-check="{{ $problem['title'] }}">{{ \Larena\Env\Server\SiteDoctor::checkText($problem['title']) }}</span>
                                <span class="text-small color-on-surface-variant">{{ \Larena\Env\Server\SiteDoctor::areaText($problem['area']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @elseif ($tab === 'domains')
            <section class="larena-panel larena-panel-padded" data-env-certificate="{{ $site->certificate ? 'present' : 'none' }}" aria-labelledby="env-site-certificate">
                <h2 id="env-site-certificate" class="text-2">{{ __('larena-env::admin.certificate.title') }}</h2>
                @if ($site->certificate === null)
                    <p>{{ __('larena-env::admin.certificate.none') }}</p>
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
                    <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.sites.php') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->php }}</dd></div>
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
                @if ($site->databaseEngine === null)
                    <p>{{ __('larena-env::admin.database.none') }}</p>
                @else
                    <dl class="m-0" style="display: block;" data-env-facts>
                        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.database.engine') }}</dt><dd class="m-0 min-w-0 text-end">{{ $site->databaseEngine }}</dd></div>
                    </dl>
                    @if ($databases !== null)
                        <ul class="m-0 m-top-1 p-0" style="list-style: none;">
                            @foreach ($databases as $database)
                                <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-database-item="{{ $database->name }}">
                                    <span class="mono grow min-w-0" style="overflow-wrap: anywhere;">{{ $database->name }}</span>
                                    <span class="text-small color-on-surface-variant">{{ \Larena\Env\Server\Bytes::human($database->sizeBytes) }}@if ($database->users !== []) · {{ implode(', ', $database->users) }}@endif</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p>{{ __('larena-env::admin.database.secrets') }}</p>
                @endif
            </section>
        @elseif ($tab === 'backups')
            <section class="larena-panel larena-panel-padded" data-env-backups aria-labelledby="env-site-backups">
                <h2 id="env-site-backups" class="text-2">{{ __('larena-env::admin.backups.title') }}</h2>
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
                @foreach (array_filter($warnings ?? [], static fn ($warning): bool => in_array($warning->kind, ['backup_failed', 'backup_missing'], true) && $warning->domain === $domain) as $backupWarning)
                    <p role="alert" data-env-backup-warning="{{ $backupWarning->kind }}">{{ __('larena-env::admin.warnings.'.$backupWarning->kind, $backupWarning->replace) }}</p>
                @endforeach
                @if ($site->backups === [])
                    <p>{{ __('larena-env::admin.backups.none') }}</p>
                @else
                    <ul class="m-0 p-0" style="list-style: none;">
                        @foreach ($site->backups as $backup)
                            <li class="flex flex-wrap items-cross-center g-1 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-backup="{{ $backup->name }}">
                                <span class="grow min-w-0">{{ $backup->createdAt !== null ? \Larena\Env\Server\Moment::human($backup->createdAt) : $backup->name }}</span>
                                @if ($backup->encrypted)
                                    <sf-badge appearance="tonal" status="info" text="{{ __('larena-env::admin.backups.encrypted') }}"></sf-badge>
                                @endif
                                <span class="text-small color-on-surface-variant">{{ \Larena\Env\Server\Bytes::human($backup->sizeBytes) }} · {{ implode(', ', $backup->parts) }}</span>
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
            <section class="larena-panel larena-panel-padded" data-env-log="{{ $logKind }}" aria-labelledby="env-site-log">
                <h2 id="env-site-log" class="text-2">{{ __('larena-env::admin.log.title') }}</h2>
                <form method="get" action="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}" class="larena-form" data-env-log-filter role="search">
                    <input type="hidden" name="tab" value="log">
                    <div class="larena-field">
                        <label for="env-log-kind">{{ __('larena-env::admin.log.kind') }}</label>
                        <select id="env-log-kind" name="log">
                            @foreach ($logKinds as $kind)
                                <option value="{{ $kind }}" @if ($kind === $logKind) selected @endif>{{ __('larena-env::admin.log.'.$kind) }}</option>
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
                                <option value="{{ $group }}" @if ($group === $logGroup) selected @endif>{{ $group }}</option>
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
                    </div>
                </form>
                @if ($log === null)
                    <p>{{ $logReasonText ?? __('larena-env::admin.log.none') }}</p>
                @elseif ($log->total === 0)
                    <p>{{ __('larena-env::admin.log.empty') }}</p>
                @else
                    <p data-env-log-count>{{ __('larena-env::admin.log.count', ['shown' => (string) count($log->rows), 'total' => (string) $log->total]) }}</p>
                    @if ($log->rows !== [])
                        <div class="larena-table-scroll">
                            <table class="larena-table larena-table-stack" data-env-log-rows>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('larena-env::admin.log.time') }}</th>
                                        @if ($logKind === 'error')
                                            <th scope="col">{{ __('larena-env::admin.log.level') }}</th>
                                        @else
                                            <th scope="col">{{ __('larena-env::admin.log.status') }}</th>
                                            <th scope="col">{{ __('larena-env::admin.log.request') }}</th>
                                            <th scope="col">{{ __('larena-env::admin.log.address') }}</th>
                                        @endif
                                        <th scope="col">{{ __('larena-env::admin.log.'.($logKind === 'error' ? 'message' : 'line')) }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($log->rows as $row)
                                        <tr data-env-log-row="{{ $row['status'] ?? $row['level'] ?? 'text' }}">
                                            <td data-label="{{ __('larena-env::admin.log.time') }}">{{ $row['time'] ?? '—' }}</td>
                                            @if ($logKind === 'error')
                                                <td data-label="{{ __('larena-env::admin.log.level') }}">{{ $row['level'] ?? '—' }}</td>
                                            @else
                                                <td data-label="{{ __('larena-env::admin.log.status') }}">{{ $row['status'] ?? '—' }}</td>
                                                <td data-label="{{ __('larena-env::admin.log.request') }}">{{ $row['method'] === null ? '—' : $row['method'].' '.$row['path'] }}</td>
                                                <td data-label="{{ __('larena-env::admin.log.address') }}">{{ $row['ip'] ?? '—' }}</td>
                                            @endif
                                            {{-- A request read into its parts needs no copy of its line; a line in another form, or an error's message, is the line. --}}
                                            <td data-label="{{ __('larena-env::admin.log.'.($logKind === 'error' ? 'message' : 'line')) }}">@if ($logKind === 'error' || $row['time'] === null)<code>{{ $row['text'] }}</code>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
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
            <section class="larena-panel larena-panel-padded" data-env-operations aria-labelledby="env-site-operations">
                <h2 id="env-site-operations" class="text-2">{{ __('larena-env::admin.operations.title') }}</h2>
                {{-- The tab's actions as a row of buttons; each opens its fields in a side panel, and the plan comes next
                     (the panel's UX audit, LAY-03): the tab is no longer a column of long forms. --}}
                <div class="flex flex-wrap g-1" data-env-operation-buttons>
                    @foreach ($tabOperations as $operation)
                        @continue(!$operation->namesSite() && ($sftpChoices[$operation->value] ?? []) === [])
                        <button type="button" class="larena-button" data-sf-modal-open="env-op-{{ $operation->value }}" data-env-operation-open="{{ $operation->value }}">{{ __('larena-env::admin.operations.'.$operation->value) }}</button>
                    @endforeach
                </div>
                @foreach ($tabOperations as $operation)
                    @continue(!$operation->namesSite() && ($sftpChoices[$operation->value] ?? []) === [])
                    <sf-modal id="env-op-{{ $operation->value }}" position="inline-end" width="28rem" heading="{{ __('larena-env::admin.operations.'.$operation->value) }}">
                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="larena-form m-0" style="padding: 0; box-shadow: none; background: none; border: 0; border-radius: 0;" data-env-operation="{{ $operation->value }}">
                        @csrf
                        <input type="hidden" name="operation" value="{{ $operation->value }}">
                        @foreach (($capabilities ?? null) === null ? $operation->fields() : $operation->fieldsOn($capabilities) as $field)
                            @continue(!($dnsCertificates ?? false) && in_array($field, \Larena\Env\Operations\SiteOperation::DNS_FIELDS, true))
                            @if (in_array($field, \Larena\Env\Operations\SiteOperation::SWITCHES, true))
                                <div class="larena-field" data-env-switch="{{ $field }}">
                                    <input type="hidden" name="{{ $field }}" value="no">
                                    <label class="flex items-cross-center g-1" for="env-{{ $operation->value }}-{{ $field }}">
                                        <input type="checkbox" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" value="yes" @if (\Larena\Env\Operations\SiteOperation::switchedOn($field, $site)) checked @endif @if ($field === 'verify') data-env-verify-field @endif>
                                        <span>{{ __('larena-env::admin.fields.'.$field) }}</span>
                                    </label>
                                </div>
                                @continue
                            @endif
                            <div class="larena-field">
                                <label for="env-{{ $operation->value }}-{{ $field }}">{{ __('larena-env::admin.fields.'.$field) }}</label>
                                @if ($field === 'email')
                                    <input type="email" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required>
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
                                @elseif ($field === 'keep')
                                    <input type="number" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" min="1" max="90" value="{{ $site->backupSchedule?->keep ?? '7' }}">
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
                        @if ($operation === \Larena\Env\Operations\SiteOperation::BackupRestoreDatabase)
                            <p data-env-restore-note>{{ __('larena-env::admin.backups.restore_note') }}</p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::BackupExtractFiles)
                            <p data-env-restore-note>{{ __('larena-env::admin.backups.extract_note') }}</p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::ApacheOn)
                            <p class="m-0" data-env-apache-note>{{ __('larena-env::admin.runtime.apache_on_note') }}</p>
                        @elseif ($operation === \Larena\Env\Operations\SiteOperation::ApacheOff)
                            <p class="m-0" data-env-apache-note>{{ __('larena-env::admin.runtime.apache_off_note') }}</p>
                        @endif
                        <div class="larena-form-actions">
                            <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
                        </div>
                    </form>
                    </sf-modal>
                @endforeach
            </section>
        @endif
        </div>
    @endif
@endsection
