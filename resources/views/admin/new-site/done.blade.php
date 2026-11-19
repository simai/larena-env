{{-- The result: the password once with «Скопировать» (read here from the server's first answer after the install, kept
     nowhere), then where to go: the site, WordPress's sign-in, the site's card. --}}
<p class="m-0" data-env-new-done="{{ $domain }}">{{ __('larena-env::admin.new_site.wizard.done_text', ['domain' => $domain]) }}</p>
@if ($secret !== null)
    <div class="larena-panel larena-panel-padded m-0" role="alert" data-env-job-secret>
        <p class="m-0 text-medium">{{ __('larena-env::admin.routes.password.title') }}</p>
        <p class="m-0">{{ __('larena-env::admin.routes.password.save_now') }}</p>
        <dl class="m-0 m-y-1" style="display: block;" data-env-facts data-larena-action-details>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.password.user') }}</dt><dd class="m-0 min-w-0 text-end mono">{{ $secret->user }}</dd></div>
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant"><dt class="color-on-surface-variant">{{ __('larena-env::admin.routes.password.password') }}</dt><dd class="m-0 min-w-0 text-end mono" style="overflow-wrap: anywhere;" data-env-job-password>{{ $secret->password }}</dd></div>
        </dl>
        <sf-button-copy value="{{ $secret->password }}" text="{{ __('larena-env::admin.job.copy_password') }}" label="{{ __('larena-env::admin.job.copy_password') }}" appearance="default" scheme="primary" data-env-job-copy></sf-button-copy>
    </div>
@elseif ($secretShown)
    <p class="m-0" role="note" data-env-job-secret-shown>{{ __('larena-env::admin.routes.password.shown') }}</p>
@endif
<div class="flex flex-wrap g-1" data-env-new-next>
    <sf-button href="{{ $siteUrl }}" target="_blank" text="{{ __('larena-env::admin.new_site.wizard.open_site') }}" appearance="outline" scheme="on-surface" icon-end="open_in_new" data-env-new-open-site></sf-button>
    @if ($wpLogin !== null)
        <sf-button href="{{ $wpLogin }}" target="_blank" text="{{ __('larena-env::admin.new_site.wizard.wp_login') }}" appearance="outline" scheme="on-surface" icon-end="open_in_new" data-env-new-wp-login></sf-button>
    @endif
    <sf-button href="{{ $cardUrl }}" text="{{ __('larena-env::admin.new_site.wizard.card') }}" appearance="outline" scheme="on-surface" data-env-new-card></sf-button>
</div>
@if ($then !== [])
    <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.new_site.wizard.next_here', ['steps' => implode(' → ', $then)]) }}</p>
@endif
