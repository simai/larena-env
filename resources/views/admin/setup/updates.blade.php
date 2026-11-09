{{-- What simai-env and the panel run and what is out; the server looks for updates every 6 hours by itself. simai-env is
     updated right here after the server's plan; the panel updates itself on «Программы и обновления». --}}
@if ($plans === null)
    <p class="m-0" data-env-setup-unsupported>{{ __('larena-env::admin.setup.unsupported') }}</p>
@else
    <dl class="m-0" style="display: block;" data-env-facts>
        @foreach ($plans as $target => $one)
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-setup-update="{{ $target }}">
                <dt class="color-on-surface-variant">{{ __('larena-env::admin.update.targets.'.$target) }}</dt>
                <dd class="m-0 min-w-0 text-end">{{ $one?->current ?? '—' }}@if ($one?->updateAvailable) → <b>{{ $one->available }}</b>@endif</dd>
            </div>
        @endforeach
    </dl>
    @if (($plans['env'] ?? null)?->updateAvailable && $canUpdate)
        <div><sf-button appearance="outline" text="{{ __('larena-env::admin.setup.updates.env') }}" native-type="submit" name="intent" value="update" data-env-setup-update-env></sf-button></div>
    @else
        <p class="m-0">{{ __('larena-env::admin.setup.updates.fresh') }}</p>
    @endif
@endif
