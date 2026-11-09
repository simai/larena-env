{{-- The administrator's password and sign-in code live in the sign-in security screen; it opens in a new tab, the
     wizard stays here. --}}
<p class="m-0">{{ __('larena-env::admin.setup.login.text') }}</p>
@if ($loginUrl !== null)
    <p class="m-0"><a href="{{ $loginUrl }}" target="_blank" rel="noopener" class="flex g-1 items-cross-center larena-control-floor" style="display: inline-flex;" data-env-setup-login>{{ __('larena-env::admin.setup.step.login.open') }} <sf-icon icon="open_in_new" aria-hidden="true"></sf-icon></a></p>
    <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.setup.login.tab') }}</p>
@endif
