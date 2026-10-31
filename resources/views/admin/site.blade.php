@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.site.title', ['domain' => $domain]))
@section('heading', $domain)
@section('eyebrow', __('larena-env::admin.eyebrow'))
@section('description', __('larena-env::admin.site.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    @if ($site === null)
        <section class="larena-panel larena-empty" data-env-state="{{ $refused ? 'refused' : 'failed' }}" role="alert">
            <h2>{{ $refused ? __('larena-env::admin.refused_title') : __('larena-env::admin.failed_title') }}</h2>
            <p>{{ $reasonText }}</p>
            <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
        </section>
    @else
        <nav class="larena-panel larena-panel-padded" aria-label="{{ __('larena-env::admin.site.tabs_label') }}" data-env-tabs>
            @foreach ($tabs as $name)
                <a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab={{ $name }}" data-env-tab="{{ $name }}" @if ($name === $tab) aria-current="page" @endif>{{ __('larena-env::admin.site.tabs.'.$name) }}</a>
            @endforeach
        </nav>

        @if ($tab === 'overview')
            <section class="larena-panel larena-panel-padded" data-env-state="ok" aria-labelledby="env-site-facts">
                <h2 id="env-site-facts">{{ __('larena-env::admin.site.facts') }}</h2>
                <dl class="larena-recipe-facts">
                    <div><dt>{{ __('larena-env::admin.sites.owner') }}</dt><dd>{{ $site->owner ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.profile') }}</dt><dd>{{ $site->profile }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.php') }}</dt><dd>{{ $site->php }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.ssl') }}</dt><dd>{{ $site->ssl }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.database') }}</dt><dd>{{ $site->databaseEngine ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.release') }}</dt><dd>{{ $site->release ?? '—' }}</dd></div>
                </dl>
            </section>
            <section class="larena-panel larena-panel-padded" data-env-doctor="{{ $doctor?->status ?? 'unknown' }}" aria-labelledby="env-site-doctor">
                <h2 id="env-site-doctor">{{ __('larena-env::admin.doctor.title') }}</h2>
                @if ($doctor === null)
                    <p>{{ __('larena-env::admin.doctor.none') }}</p>
                @elseif ($doctor->problems === [])
                    <p>{{ __('larena-env::admin.doctor.clean', ['count' => (string) $doctor->passed]) }}</p>
                @else
                    <p>{{ __('larena-env::admin.doctor.summary', ['count' => (string) count($doctor->problems), 'passed' => (string) $doctor->passed]) }}</p>
                    <ul class="larena-list">
                        @foreach ($doctor->problems as $problem)
                            <li data-env-problem="{{ $problem['status'] }}">{{ __('larena-env::admin.doctor.'.strtolower($problem['status'])) }} · {{ $problem['area'] }} · {{ $problem['title'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section class="larena-panel larena-panel-padded" data-env-backups aria-labelledby="env-site-backups">
                <h2 id="env-site-backups">{{ __('larena-env::admin.backups.title') }}</h2>
                @if ($site->backupSchedule === null)
                    <p>{{ __('larena-env::admin.backups.no_schedule') }}</p>
                @else
                    <p>{{ __('larena-env::admin.backups.schedule', ['time' => $site->backupSchedule->time ?? '—', 'keep' => $site->backupSchedule->keep ?? '—']) }}</p>
                @endif
                @if ($site->backups === [])
                    <p>{{ __('larena-env::admin.backups.none') }}</p>
                @else
                    <ul class="larena-list">
                        @foreach ($site->backups as $backup)
                            <li data-env-backup="{{ $backup->name }}">
                                {{ $backup->createdAt ?? $backup->name }} · {{ implode(', ', $backup->parts) }}
                                @if ($backup->encrypted) · {{ __('larena-env::admin.backups.encrypted') }} @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @elseif ($tab === 'domains')
            <section class="larena-panel larena-panel-padded" data-env-certificate="{{ $site->certificate ? 'present' : 'none' }}" aria-labelledby="env-site-certificate">
                <h2 id="env-site-certificate">{{ __('larena-env::admin.certificate.title') }}</h2>
                @if ($site->certificate === null)
                    <p>{{ __('larena-env::admin.certificate.none') }}</p>
                @else
                    <dl class="larena-recipe-facts">
                        <div><dt>{{ __('larena-env::admin.certificate.names') }}</dt><dd>{{ implode(', ', $site->certificate->names) }}</dd></div>
                        <div><dt>{{ __('larena-env::admin.certificate.issuer') }}</dt><dd>{{ $site->certificate->issuer ?? '—' }}</dd></div>
                        <div><dt>{{ __('larena-env::admin.certificate.not_after') }}</dt><dd>{{ $site->certificate->notAfter ?? '—' }}</dd></div>
                        <div><dt>{{ __('larena-env::admin.certificate.days_left') }}</dt><dd>{{ $site->certificate->daysLeft ?? '—' }}</dd></div>
                        <div><dt>{{ __('larena-env::admin.certificate.auto_renew') }}</dt><dd>{{ $site->certificate->autoRenew ? __('larena-env::admin.yes') : __('larena-env::admin.no') }}</dd></div>
                    </dl>
                    @if ($site->certificate->endsSoon())
                        <p data-env-certificate-warning role="alert">{{ __('larena-env::admin.certificate.ends_soon') }}</p>
                    @endif
                @endif
            </section>
        @elseif ($tab === 'php')
            <section class="larena-panel larena-panel-padded" data-env-runtime aria-labelledby="env-site-runtime">
                <h2 id="env-site-runtime">{{ __('larena-env::admin.runtime.title') }}</h2>
                <dl class="larena-recipe-facts">
                    <div><dt>{{ __('larena-env::admin.sites.php') }}</dt><dd>{{ $site->php }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.runtime.service') }}</dt><dd>{{ $site->service ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.runtime.workers') }}</dt><dd>{{ $site->workers === [] ? '—' : implode(', ', $site->workers) }}</dd></div>
                </dl>
            </section>
        @elseif ($tab === 'releases')
            <section class="larena-panel larena-panel-padded" data-env-releases aria-labelledby="env-site-releases">
                <h2 id="env-site-releases">{{ __('larena-env::admin.releases.title') }}</h2>
                <dl class="larena-recipe-facts">
                    <div><dt>{{ __('larena-env::admin.sites.release') }}</dt><dd>{{ $site->release ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.site.previous_release') }}</dt><dd>{{ $site->previousRelease ?? '—' }}</dd></div>
                </dl>
                @if ($site->release === null)
                    <p>{{ __('larena-env::admin.releases.in_place') }}</p>
                @endif
            </section>
        @elseif ($tab === 'log')
            <section class="larena-panel larena-panel-padded" data-env-log="{{ $logKind }}" aria-labelledby="env-site-log">
                <h2 id="env-site-log">{{ __('larena-env::admin.log.title') }}</h2>
                <p>
                    @foreach ($logKinds as $kind)
                        <a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=log&amp;log={{ $kind }}" @if ($kind === $logKind) aria-current="page" @endif>{{ __('larena-env::admin.log.'.$kind) }}</a>
                    @endforeach
                </p>
                @if ($log === null)
                    <p>{{ $logReasonText ?? __('larena-env::admin.log.none') }}</p>
                @elseif ($log->lines === [])
                    <p>{{ __('larena-env::admin.log.empty') }}</p>
                @else
                    <pre data-env-log-lines>{{ implode("\n", $log->lines) }}</pre>
                @endif
            </section>
        @elseif ($tab === 'access')
            <section class="larena-panel larena-panel-padded" data-env-access aria-labelledby="env-site-access">
                <h2 id="env-site-access">{{ __('larena-env::admin.access.title') }}</h2>
                <dl class="larena-recipe-facts">
                    <div><dt>{{ __('larena-env::admin.sites.owner') }}</dt><dd>{{ $site->owner ?? __('larena-env::admin.access.own_account') }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.access.runs_as') }}</dt><dd>{{ $site->runsAs ?? '—' }}</dd></div>
                </dl>
                <p>{{ __('larena-env::admin.access.explain') }}</p>
            </section>
        @endif

        @if ($tabOperations !== [])
            <section class="larena-panel larena-panel-padded" data-env-operations aria-labelledby="env-site-operations">
                <h2 id="env-site-operations">{{ __('larena-env::admin.operations.title') }}</h2>
                <p>{{ __('larena-env::admin.operations.explain') }}</p>
                @foreach ($tabOperations as $operation)
                    <form method="post" action="{{ route('larena.env.admin.site.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-operation="{{ $operation->value }}">
                        @csrf
                        <input type="hidden" name="operation" value="{{ $operation->value }}">
                        <h3>{{ __('larena-env::admin.operations.'.$operation->value) }}</h3>
                        @foreach ($operation->fields() as $field)
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
                                @else
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}">
                                        <option value="">{{ __('larena-env::admin.fields.default') }}</option>
                                        <option value="yes">{{ __('larena-env::admin.yes') }}</option>
                                        <option value="no">{{ __('larena-env::admin.no') }}</option>
                                    </select>
                                @endif
                            </div>
                        @endforeach
                        <div class="larena-form-actions">
                            <button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button>
                        </div>
                    </form>
                @endforeach
            </section>
        @endif
        <p><a href="{{ route('larena.env.admin.index', [], false) }}">{{ __('larena-env::admin.site.back') }}</a></p>
    @endif
@endsection
