{{-- The step checks what it asks (PX-22): it reads whether this person's sign-in code is on, says so, and «Далее» checks it
     again; the sign-in settings open in this tab and lead back here. --}}
@if (($mfa ?? null) === true)
    <p class="m-0" data-env-setup-login-state="on"><sf-badge appearance="tonal" status="success" text="{{ __('larena-env::admin.setup.state.done') }}"></sf-badge> {{ __('larena-env::admin.setup.login.on') }}</p>
@else
    <p class="m-0" data-env-setup-login-state="{{ ($mfa ?? null) === false ? 'off' : 'unknown' }}">{{ ($mfa ?? null) === false ? __('larena-env::admin.setup.login.text') : __('larena-env::admin.setup.login.unknown') }}</p>
    @if ($loginUrl !== null)
        <div><sf-button appearance="outline" text="{{ __('larena-env::admin.setup.step.login.open') }}" href="{{ $loginUrl }}" data-env-setup-login></sf-button></div>
        <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.setup.login.back') }}</p>
    @endif
@endif
