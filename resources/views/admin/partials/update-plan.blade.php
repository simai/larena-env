{{-- What a safe update would change (simai-env 1.32.0), on the update screen and in its plan. --}}
<div data-env-update-facts="{{ $update->target }}">
    <dl class="m-0" style="display: block;" data-env-facts>
        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-update-current><dt class="color-on-surface-variant">{{ __('larena-env::admin.update.current') }}</dt><dd class="m-0 min-w-0 text-end">{{ $update->current ?? ($update->target === 'panel' && ($panelBuild ?? null) !== null ? __('larena-env::admin.update.own_build', ['version' => $panelBuild]) : __('larena-env::admin.update.none')) }}</dd></div>
        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-update-available><dt class="color-on-surface-variant">{{ __('larena-env::admin.update.available') }}</dt><dd class="m-0 min-w-0 text-end">{{ $update->available ?? '—' }}</dd></div>
        @if ($update->backup !== null)
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-update-backup><dt class="color-on-surface-variant">{{ __('larena-env::admin.update.backup') }}</dt><dd class="m-0 min-w-0 text-end" style="overflow-wrap: anywhere;">{{ $update->backup }}</dd></div>
        @endif
        <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-update-restarts><dt class="color-on-surface-variant">{{ __('larena-env::admin.update.restarts') }}</dt><dd class="m-0 min-w-0 text-end">{{ $update->restarts === [] ? __('larena-env::admin.update.no_restarts') : implode(', ', $update->restarts) }}</dd></div>
        @if ($update->target === 'env')
            <div class="flex content-main-between g-2 p-y-1 border-top-1 border-top-solid border-outline-variant" data-env-update-checks><dt class="color-on-surface-variant">{{ __('larena-env::admin.update.checks') }}</dt><dd class="m-0 min-w-0 text-end" style="overflow-wrap: anywhere;">{{ $update->checks === [] ? __('larena-env::admin.update.no_checks') : implode(', ', $update->checks) }}</dd></div>
        @endif
    </dl>
    <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.update.rollback_title') }}</h3>
    <p data-env-update-rollback role="note">{{ __('larena-env::admin.update.rollback.'.$update->target) }}</p>
    @if ($update->notes !== [])
        <h3 class="m-top-2 m-bottom-1 text-medium">{{ __('larena-env::admin.update.notes') }}</h3>
        @foreach ($update->notes as $note)
            <section data-env-update-note="{{ $note['version'] }}" aria-label="{{ $note['version'] }}">
                <h4>{{ $note['version'] }}</h4>
                <p style="white-space: pre-line; overflow-wrap: anywhere;">{{ $note['text'] }}</p>
            </section>
        @endforeach
    @endif
    @if ($update->notesUrl !== null)
        <p><a href="{{ $update->notesUrl }}" rel="noopener noreferrer" target="_blank" data-env-update-notes-url>{{ __('larena-env::admin.update.notes_url') }}</a></p>
    @endif
</div>
