{{-- The kind as tiles (PX-17, PX-19): WordPress first, «чаще всего»; a kind the panel cannot lead here says why. --}}
<sf-input-choice name="profile" label="{{ __('larena-env::admin.new_site.wizard.kinds') }}" layout="tile" required value="{{ $kind }}"
    items="{{ json_encode($kinds, JSON_UNESCAPED_UNICODE) }}" @if (($errors['profile'] ?? null) !== null) invalid error-message="{{ $errors['profile'] }}" @endif data-env-new-kinds></sf-input-choice>
@if ($disabled !== [] && $canEnable)
    {{-- The kinds the server can make but are switched off (simai-env 1.57.5): «Включить», only for the server's administrator. --}}
    <div class="flex flex-column g-1" data-env-new-disabled>
        <p class="m-0 text-medium">{{ __('larena-env::admin.new_site.wizard.disabled_title') }}</p>
        <p class="m-0 color-on-surface-variant">{{ __('larena-env::admin.new_site.wizard.disabled_text') }}</p>
        <ul class="m-0 p-0" style="list-style: none;">
            @foreach ($disabled as $id => $name)
                <li class="flex flex-wrap items-cross-center content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-new-disabled-kind="{{ $id }}">
                    <span>{{ $name }}</span>
                    <button type="button" class="larena-button larena-control-floor" data-sf-modal-open="env-enable-{{ $id }}" data-env-new-enable="{{ $id }}">{{ __('larena-env::admin.new_site.wizard.enable') }}</button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
