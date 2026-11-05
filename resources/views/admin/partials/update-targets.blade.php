{{-- simai-env and the panel's own build: each with its state, what an update changes and how it comes back. Shown at
     the head of «Программы и обновления» and on the update's own address. --}}
@if (!$supported)
    <section class="larena-panel larena-empty" data-env-update="unsupported">
        <p>{{ __('larena-env::admin.update.unsupported') }}</p>
    </section>
@else
    {{-- simai-env itself, then the panel's build: each with its state in the head, what it changes and how it comes back. --}}
    <div class="grid g-2 grid-col-1 xl:grid-col-2" style="align-items: start;">
    @foreach ($plans as $target => $update)
        <section class="larena-panel p-2" style="margin: 0;" data-env-update="{{ $target }}" data-env-update-state="{{ $update === null ? 'unknown' : ($update->blocked ?? ($update->updateAvailable ? 'available' : 'current')) }}" aria-labelledby="env-update-{{ $target }}">
            <div class="flex flex-wrap items-cross-center content-main-between g-1 m-bottom-1">
                <h2 id="env-update-{{ $target }}" class="m-0 text-2">{{ __('larena-env::admin.update.targets.'.$target) }}</h2>
                <sf-badge appearance="tonal" status="{{ $update === null ? 'error' : ($update->blocked !== null ? 'warning' : ($update->updateAvailable ? 'info' : 'success')) }}"
                    text="{{ __('larena-env::admin.update.states.'.($update === null ? 'unknown' : ($update->blocked !== null ? 'blocked' : ($update->updateAvailable ? 'available' : 'current')))) }}"></sf-badge>
            </div>
            @if ($update === null)
                <p class="m-0" role="alert">{{ __('larena-env::admin.update.unreadable') }}</p>
            @else
                @if ($update->blocked !== null)
                    <p data-env-update-blocked role="note">{{ __('larena-env::admin.update.blocked.'.$update->blocked) }}</p>
                @elseif ($update->updateAvailable)
                    <p data-env-update-summary>{{ ($update->current === null ? __('larena-env::admin.update.summary_new', ['available' => $update->available ?? '—']) : __('larena-env::admin.update.summary', ['current' => $update->current, 'available' => $update->available ?? '—'])) }}</p>
                @else
                    <p data-env-update-summary class="color-on-surface-variant">{{ __('larena-env::admin.update.up_to_date', ['current' => $update->current ?? ($update->target === 'panel' ? ($panelBuild ?? '—') : '—')]) }}</p>
                @endif
                @include('larena-env::admin.partials.update-plan', ['update' => $update])
                @if ($update->updateAvailable && $canUpdate)
                    <form method="post" action="{{ route('larena.env.admin.update.plan', [], false) }}" class="m-0 m-top-2" data-env-update-plan="{{ $target }}">
                        @csrf
                        <input type="hidden" name="target" value="{{ $target }}">
                        <button type="submit" class="larena-button larena-button-primary">{{ __('larena-env::admin.update.plan') }}</button>
                    </form>
                @endif
            @endif
        </section>
    @endforeach
    </div>
@endif
