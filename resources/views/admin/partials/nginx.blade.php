{{-- A site's own nginx directives (Panel plan 6.3), the system level's only: applied, then kept only once confirmed in time. --}}
<section class="larena-panel larena-panel-padded" data-env-nginx="{{ $overlaySupported ? 'on' : 'unsupported' }}" aria-labelledby="env-site-nginx">
    <h2 id="env-site-nginx">{{ __('larena-env::admin.nginx.title') }}</h2>
    @if (!$overlaySupported)
        <p data-env-nginx-unsupported>{{ __('larena-env::admin.nginx.unsupported') }}</p>
    @elseif ($overlay === null)
        <p role="alert">{{ $overlayProblem ?? __('larena-env::admin.nginx.unreadable') }}</p>
    @else
        <p>{{ __('larena-env::admin.nginx.explain') }}</p>
        <p data-env-nginx-allowlist>{{ __('larena-env::admin.nginx.allowlist') }}</p>
        @if ($overlay['pending'] !== null)
            <div role="alert" data-env-nginx-pending="{{ $overlay['pending'] }}">
                <p>{{ __('larena-env::admin.nginx.pending') }}</p>
                @if ($overlayAllows['confirm'])
                    <form method="post" action="{{ route('larena.env.admin.site.nginx.plan', ['domain' => $domain], false) }}" data-env-nginx-op="overlay-confirm">
                        @csrf
                        <input type="hidden" name="op" value="overlay-confirm">
                        <input type="hidden" name="change" value="{{ $overlay['pending'] }}">
                        <button type="submit" class="larena-button">{{ __('larena-env::admin.nginx.keep') }}</button>
                    </form>
                @endif
            </div>
        @endif
        <form method="post" action="{{ route('larena.env.admin.site.nginx.plan', ['domain' => $domain], false) }}" class="larena-form" data-env-nginx-op="overlay-set">
            @csrf
            <input type="hidden" name="op" value="overlay-set">
            <div class="larena-field">
                <label for="env-nginx-content">{{ __('larena-env::admin.nginx.content') }}</label>
                <textarea id="env-nginx-content" name="content" rows="14" spellcheck="false" style="font-family: var(--sf-font-mono, monospace); width: 100%; white-space: pre; overflow-x: auto;" @if (!$overlayAllows['set']) readonly @endif>{{ $overlay['content'] }}</textarea>
            </div>
            @if ($overlayAllows['set'])
                <p>{{ __('larena-env::admin.nginx.apply_note') }}</p>
                <div class="larena-form-actions"><button type="submit" class="larena-button">{{ __('larena-env::admin.operations.plan') }}</button></div>
            @endif
        </form>
        @if ($overlayAllows['clear'] && trim($overlay['content']) !== '')
            <form method="post" action="{{ route('larena.env.admin.site.nginx.plan', ['domain' => $domain], false) }}" data-env-nginx-op="overlay-clear">
                @csrf
                <input type="hidden" name="op" value="overlay-clear">
                <button type="submit" class="larena-button">{{ __('larena-env::admin.files.operations.overlay-clear') }}</button>
            </form>
        @endif
    @endif
</section>
