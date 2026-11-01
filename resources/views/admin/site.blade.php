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
                    <div data-env-answers="{{ $site->answersChecked ? ($site->answers ?? 'none') : 'unchecked' }}"><dt>{{ __('larena-env::admin.sites.answers') }}</dt><dd>{{ !$site->answersChecked ? '—' : ($site->answers === null ? __('larena-env::admin.sites.answers_none') : __('larena-env::admin.sites.answers_code', ['code' => (string) $site->answers])) }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.owner') }}</dt><dd>{{ $site->owner ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.profile') }}</dt><dd>{{ $site->profile }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.php') }}</dt><dd>{{ $site->php }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.ssl') }}</dt><dd>{{ $site->ssl }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.database') }}</dt><dd>{{ $site->databaseEngine ?? '—' }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.sites.release') }}</dt><dd>{{ $site->release ?? '—' }}</dd></div>
                    <div data-env-disk><dt>{{ __('larena-env::admin.sites.disk') }}</dt><dd>{{ \Larena\Env\Server\Bytes::human($site->diskBytes) }}</dd></div>
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
                @if ($site->backups === [])
                    <p data-env-no-backup role="alert">{{ __('larena-env::admin.backups.none') }} <a href="{{ route('larena.env.admin.site', ['domain' => $domain], false) }}?tab=backups">{{ __('larena-env::admin.backups.go') }}</a></p>
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
            @if ($phpSettings !== null)
                <section class="larena-panel larena-panel-padded" data-env-php-settings aria-labelledby="env-site-php-settings">
                    <h2 id="env-site-php-settings">{{ __('larena-env::admin.runtime.settings') }}</h2>
                    @if ($phpSettings === [])
                        <p>{{ __('larena-env::admin.runtime.settings_none') }}</p>
                    @else
                        <dl class="larena-recipe-facts">
                            @foreach ($phpSettings as $setting)
                                <div data-env-php-setting="{{ $setting->key }}"><dt>{{ $setting->key }}</dt><dd>{{ $setting->value }}@if ($setting->source !== null) · {{ $setting->source }}@endif</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            @endif
            @if ($phpExtensions !== null)
                <section class="larena-panel larena-panel-padded" data-env-php-extensions aria-labelledby="env-site-php-extensions">
                    <h2 id="env-site-php-extensions">{{ __('larena-env::admin.runtime.extensions', ['php' => $site->php]) }}</h2>
                    <ul class="larena-list">
                        @foreach ($phpExtensions as $extension)
                            <li data-env-php-extension="{{ $extension->name }}">{{ $extension->name }} · {{ $extension->enabled ? __('larena-env::admin.runtime.on') : __('larena-env::admin.runtime.off') }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @elseif ($tab === 'database')
            <section class="larena-panel larena-panel-padded" data-env-database="{{ $site->databaseEngine ?? 'none' }}" aria-labelledby="env-site-database">
                <h2 id="env-site-database">{{ __('larena-env::admin.database.title') }}</h2>
                @if ($site->databaseEngine === null)
                    <p>{{ __('larena-env::admin.database.none') }}</p>
                @else
                    <dl class="larena-recipe-facts">
                        <div><dt>{{ __('larena-env::admin.database.engine') }}</dt><dd>{{ $site->databaseEngine }}</dd></div>
                    </dl>
                    @if ($databases !== null)
                        <ul class="larena-list">
                            @foreach ($databases as $database)
                                <li data-env-database-item="{{ $database->name }}">{{ $database->name }} · {{ \Larena\Env\Server\Bytes::human($database->sizeBytes) }}@if ($database->users !== []) · {{ implode(', ', $database->users) }}@endif</li>
                            @endforeach
                        </ul>
                    @endif
                    <p>{{ __('larena-env::admin.database.secrets') }}</p>
                @endif
            </section>
        @elseif ($tab === 'backups')
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
                                {{ $backup->createdAt ?? $backup->name }} · {{ \Larena\Env\Server\Bytes::human($backup->sizeBytes) }} · {{ implode(', ', $backup->parts) }}
                                @if ($backup->encrypted) · {{ __('larena-env::admin.backups.encrypted') }} @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
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
                            <table class="larena-table" data-env-log-rows>
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
                                            <td>{{ $row['time'] ?? '—' }}</td>
                                            @if ($logKind === 'error')
                                                <td>{{ $row['level'] ?? '—' }}</td>
                                            @else
                                                <td>{{ $row['status'] ?? '—' }}</td>
                                                <td>{{ $row['method'] === null ? '—' : $row['method'].' '.$row['path'] }}</td>
                                                <td>{{ $row['ip'] ?? '—' }}</td>
                                            @endif
                                            {{-- A request read into its parts needs no copy of its line; a line in another form, or an error's message, is the line. --}}
                                            <td>@if ($logKind === 'error' || $row['time'] === null)<code>{{ $row['text'] }}</code>@endif</td>
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
                <h2 id="env-site-access">{{ __('larena-env::admin.access.title') }}</h2>
                <dl class="larena-recipe-facts">
                    <div><dt>{{ __('larena-env::admin.sites.owner') }}</dt><dd>{{ $site->owner ?? __('larena-env::admin.access.own_account') }}</dd></div>
                    <div><dt>{{ __('larena-env::admin.access.runs_as') }}</dt><dd>{{ $site->runsAs ?? '—' }}</dd></div>
                </dl>
                <p>{{ __('larena-env::admin.access.explain') }}</p>
                @if ($sftpAccounts !== null)
                    <h3>{{ __('larena-env::admin.access.sftp') }}</h3>
                    @if ($sftpAccounts === [])
                        <p>{{ __('larena-env::admin.access.sftp_none') }}</p>
                    @else
                        <ul class="larena-list">
                            @foreach ($sftpAccounts as $account)
                                <li data-env-sftp="{{ $account->login }}">{{ $account->login }} · {{ __('larena-env::admin.access.keys', ['count' => (string) $account->keys]) }} · {{ $account->enabled ? __('larena-env::admin.access.on') : __('larena-env::admin.access.off') }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
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
                                @elseif ($field === 'backup')
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required>
                                        @foreach ($site->backups as $backup)
                                            <option value="{{ $backup->name }}">{{ $backup->createdAt ?? $backup->name }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field === 'login' && $operation !== \Larena\Env\Operations\SiteOperation::SftpCreate)
                                    <select id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" required>
                                        @foreach ($sftpAccounts ?? [] as $account)
                                            <option value="{{ $account->login }}">{{ $account->login }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field === 'pubkey')
                                    <textarea id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" rows="3" required></textarea>
                                @elseif ($field === 'time')
                                    <input type="time" id="env-{{ $operation->value }}-{{ $field }}" name="{{ $field }}" value="{{ $site->backupSchedule?->time ?? '03:00' }}">
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
                        @endif
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
