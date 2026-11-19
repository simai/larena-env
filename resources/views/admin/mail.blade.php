@extends('larena-admin::layouts.app')

@section('title', __('larena-env::admin.tab_title', ['title' => __('larena-env::mail.title')]))
@section('heading', __('larena-env::mail.title'))
@section('eyebrow', __('larena-env::admin.panel_settings.title'))
@section('description', __('larena-env::mail.description'))

@section('content')
    @include('larena-env::admin.partials.notes')

    {{-- «Настройки» → «Почта» (owner decision C335): how the panel sends its own letters, as one card «значение · Изменить»
         (study part 8, PX-82), the change open in place under it (?edit=mail), planned and confirmed. The password is never
         shown, only «задан». Mail is on only after a test letter: the test sits under the card. PanelMailController
         builds the card's rows. --}}
    <section class="larena-panel p-2" style="margin: 0;" id="env-mail-now" data-env-mail="{{ $state }}" aria-label="{{ __('larena-env::mail.card.title') }}">
        @include('larena-env::admin.partials.facts', ['items' => $items, 'label' => __('larena-env::mail.card.title'), 'mark' => 'mail'])
        @if (($checked ?? null) !== null)
            {{-- In the reader's own time (C183): the Framework's sf-time says it in the browser's zone, not the server's UTC. --}}
            <p class="m-0 p-x-1 p-top-1 color-on-surface-variant" data-env-mail-checked>{{ __('larena-env::mail.card.checked') }}: <sf-time datetime="{{ $checked }}" format="datetime"></sf-time></p>
        @endif

        @if ($mismatch && !$edit)
            {{-- Said before the test letter (auditor's addition 2): a sender off the mailbox's domain lands in spam. --}}
            <p class="m-0 p-x-1 p-top-1 color-warning" role="note" data-env-mail-spam>{{ __('larena-env::mail.spam_warning') }}</p>
        @endif

        @if ($edit)
            <form method="post" action="{{ route('larena.env.admin.settings.mail.plan', [], false) }}" class="larena-form p-x-1 p-top-1" data-env-mail-form>
                @csrf
                <div class="larena-field">
                    <label for="env-mail-service">{{ __('larena-env::mail.fields.service') }}</label>
                    <select id="env-mail-service" name="service" style="min-height: 44px;" data-env-mail-service>
                        @foreach ($services as $service)
                            <option value="{{ $service }}" @selected(($settings?->service ?? 'smtp') === $service)>{{ __('larena-env::mail.services.'.$service) }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::mail.form.service_help') }}</p>
                <fieldset class="m-0 p-0" style="border: 0;" data-env-mail-server>
                    <legend class="text-small color-on-surface-variant p-0 m-top-1">{{ __('larena-env::mail.form.server_legend') }}</legend>
                    <div class="larena-field">
                        <label for="env-mail-host">{{ __('larena-env::mail.fields.host') }}</label>
                        <input type="text" id="env-mail-host" name="host" maxlength="253" autocomplete="off" inputmode="url" value="{{ $settings !== null && !$settings->isService() ? $settings->host : '' }}">
                    </div>
                    <div class="larena-field">
                        <label for="env-mail-port">{{ __('larena-env::mail.fields.port') }}</label>
                        <input type="text" id="env-mail-port" name="port" maxlength="5" inputmode="numeric" value="{{ $settings !== null && !$settings->isService() ? $settings->port : '465' }}" aria-describedby="env-mail-port-help">
                        <p id="env-mail-port-help" class="m-0 text-small color-on-surface-variant">{{ __('larena-env::mail.form.port_help') }}</p>
                    </div>
                    <div class="larena-field">
                        <label for="env-mail-encryption">{{ __('larena-env::mail.fields.encryption') }}</label>
                        <select id="env-mail-encryption" name="encryption" style="min-height: 44px;">
                            @foreach (\Larena\Env\Mail\MailSettings::ENCRYPTIONS as $encryption)
                                <option value="{{ $encryption }}" @selected(($settings?->encryption ?? 'ssl') === $encryption)>{{ __('larena-env::mail.encryptions.'.$encryption) }}</option>
                            @endforeach
                        </select>
                    </div>
                </fieldset>
                <div class="larena-field">
                    <label for="env-mail-login">{{ __('larena-env::mail.fields.login') }}</label>
                    <input type="text" id="env-mail-login" name="login" maxlength="254" autocomplete="off" value="{{ $settings?->login ?? '' }}" aria-describedby="env-mail-login-help">
                    <p id="env-mail-login-help" class="m-0 text-small color-on-surface-variant">{{ __('larena-env::mail.form.login_help') }}</p>
                </div>
                <div class="larena-field" data-env-mail-password>
                    <label for="env-mail-password">{{ __('larena-env::mail.fields.password') }}</label>
                    <input type="password" id="env-mail-password" name="password" maxlength="500" autocomplete="new-password" @if (!$hasPassword) required @endif aria-describedby="env-mail-password-help">
                    <p id="env-mail-password-help" class="m-0 text-small color-on-surface-variant">{{ $hasPassword ? __('larena-env::mail.form.password_keep') : __('larena-env::mail.form.password_help') }}</p>
                </div>
                <div class="larena-field">
                    <label for="env-mail-from">{{ __('larena-env::mail.fields.from_address') }}</label>
                    <input type="email" id="env-mail-from" name="from_address" maxlength="254" autocomplete="off" required value="{{ $settings?->fromAddress ?? '' }}" aria-describedby="env-mail-from-help">
                    <p id="env-mail-from-help" class="m-0 text-small color-on-surface-variant">{{ __('larena-env::mail.form.from_help') }}</p>
                </div>
                <div class="larena-field">
                    <label for="env-mail-from-name">{{ __('larena-env::mail.fields.from_name') }}</label>
                    <input type="text" id="env-mail-from-name" name="from_name" maxlength="120" autocomplete="off" value="{{ $settings?->fromName ?? \Larena\Env\Mail\MailSettings::DEFAULT_FROM_NAME }}">
                </div>
                {{-- «Что слать на почту» (as with the server's alerts, only urgent by default); recovery always goes. --}}
                <fieldset class="m-0 p-0 m-top-1" style="border: 0;" data-env-mail-levels>
                    <legend class="p-0">{{ __('larena-env::mail.fields.levels') }}</legend>
                    @foreach (\Larena\Env\Mail\MailSettings::LEVELS as $level)
                        <div class="larena-field" data-env-mail-level="{{ $level }}">
                            <label class="flex items-cross-center gap-1" for="env-mail-level-{{ $level }}">
                                <input type="checkbox" id="env-mail-level-{{ $level }}" name="levels[]" value="{{ $level }}" @checked(in_array($level, $settings?->levels ?? ['urgent'], true))>
                                <span>{{ __('larena-env::mail.levels.'.$level) }}</span>
                            </label>
                        </div>
                    @endforeach
                    <p class="m-0 text-small color-on-surface-variant">{{ __('larena-env::mail.card.levels_hint') }}</p>
                </fieldset>
                <p class="m-0 m-top-1 text-small color-on-surface-variant" data-env-settings-when="mail">{{ __('larena-env::mail.form.when') }}</p>
                <div class="flex flex-wrap items-cross-center gap-1 m-t-2">
                    <button type="submit" class="larena-button larena-button-primary larena-control-floor">{{ __('larena-env::mail.form.plan') }}</button>
                    @if ($settings !== null && $state !== \Larena\Env\Mail\PanelMail::PASSWORD_LOST)
                        <a href="{{ route('larena.env.admin.settings.mail', [], false) }}" class="larena-button larena-control-floor" data-env-edit-cancel>{{ __('larena-env::mail.form.cancel') }}</a>
                    @endif
                </div>
            </form>
        @endif

        @if ($canTest && !$edit)
            <div class="flex flex-wrap items-cross-center gap-2 p-x-1 p-top-2 m-top-1 border-top-1 border-top-solid border-outline-variant" data-env-mail-test>
                <p class="grow m-0 color-on-surface-variant" style="flex-basis: 14rem; overflow-wrap: anywhere;"><strong class="color-on-surface">{{ __('larena-env::mail.test.title') }}.</strong> {{ __($state === \Larena\Env\Mail\PanelMail::ON ? 'larena-env::mail.test.explain_on' : 'larena-env::mail.test.explain', ['to' => $testTo]) }}</p>
                <form method="post" action="{{ route('larena.env.admin.settings.mail.test', [], false) }}" class="m-0">
                    @csrf
                    <button type="submit" class="larena-button {{ $state === \Larena\Env\Mail\PanelMail::ON ? '' : 'larena-button-primary' }} larena-control-floor">{{ __('larena-env::mail.test.title') }}</button>
                </form>
            </div>
        @endif

        @if ($canOperate && !$edit && $state === \Larena\Env\Mail\PanelMail::ON)
            <div class="flex flex-wrap items-cross-center gap-2 p-x-1 p-top-2 m-top-1 border-top-1 border-top-solid border-outline-variant" data-env-mail-off>
                <p class="grow m-0 color-on-surface-variant" style="flex-basis: 14rem;">{{ __('larena-env::mail.off.explain') }}</p>
                <form method="post" action="{{ route('larena.env.admin.settings.mail.plan', [], false) }}" class="m-0">
                    @csrf
                    <input type="hidden" name="action" value="off">
                    <button type="submit" class="larena-button larena-control-floor">{{ __('larena-env::mail.off.title') }}</button>
                </form>
            </div>
        @endif
    </section>
@endsection
