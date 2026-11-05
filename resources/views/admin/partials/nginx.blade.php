{{-- A site's own nginx directives (simai-env 1.41.0), the system level's only: applied once nginx takes them and the site
     still answers, then undone after two minutes unless kept. --}}
<section class="larena-panel larena-panel-padded" data-env-nginx="{{ $nginxSupported ? 'on' : 'unsupported' }}" aria-labelledby="env-site-nginx">
    <h2 id="env-site-nginx">{{ __('larena-env::admin.nginx.title') }}</h2>
    @if (!$nginxSupported)
        <p data-env-nginx-unsupported>{{ __('larena-env::admin.nginx.unsupported') }}</p>
    @elseif ($nginx === null)
        <p role="alert">{{ $nginxProblem ?? __('larena-env::admin.nginx.unreadable') }}</p>
    @else
        <p>{{ __('larena-env::admin.nginx.explain') }}</p>
        <p data-env-nginx-allowlist>{{ __('larena-env::admin.nginx.allowlist') }}</p>
        @if (!$nginx['included'])
            <p role="alert" data-env-nginx-not-included>{{ __('larena-env::admin.nginx.not_included') }}</p>
        @endif
        @include('larena-env::admin.partials.timed-change', ['change' => $nginx['pending'], 'canKeep' => $nginxAllows['keep'], 'changeDomain' => $domain])
        <form method="post" action="{{ route('larena.env.admin.site.nginx.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-nginx-op="nginx-save">
            @csrf
            <input type="hidden" name="op" value="nginx-save">
            <div class="larena-field">
                <label for="env-nginx-content">{{ __('larena-env::admin.nginx.content') }}</label>
                <textarea id="env-nginx-content" name="content" rows="14" spellcheck="false" style="font-family: var(--sf-font-mono, monospace); width: 100%; white-space: pre; overflow-x: auto;" @if (!$nginxAllows['save'] || $nginx['pending'] !== null) readonly @endif>{{ $nginx['content'] }}</textarea>
            </div>
            @if ($nginxAllows['save'] && $nginx['pending'] === null)
                <p>{{ __('larena-env::admin.nginx.apply_note') }}</p>
                <div class="larena-form-actions"><button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.nginx.save') }}</button></div>
            @elseif ($nginx['pending'] !== null)
                <p data-env-nginx-wait>{{ __('larena-env::admin.nginx.wait') }}</p>
            @endif
        </form>
        @if ($nginxAllows['save'] && $nginx['pending'] === null && $nginx['present'])
            <form method="post" action="{{ route('larena.env.admin.site.nginx.plan', ['domain' => $domain], false) }}" data-env-nginx-op="nginx-clear">
                @csrf
                <input type="hidden" name="op" value="nginx-clear">
                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.files.operations.nginx-clear') }}</button>
            </form>
        @endif
    @endif
</section>
