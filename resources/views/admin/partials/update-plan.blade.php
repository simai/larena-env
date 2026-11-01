{{-- What a safe update would change (simai-env 1.32.0), on the update screen and in its plan. --}}
<div data-env-update-facts="{{ $update->target }}">
    <dl class="larena-recipe-facts">
        <div data-env-update-current><dt>{{ __('larena-env::admin.update.current') }}</dt><dd>{{ $update->current ?? __('larena-env::admin.update.none') }}</dd></div>
        <div data-env-update-available><dt>{{ __('larena-env::admin.update.available') }}</dt><dd>{{ $update->available ?? '—' }}</dd></div>
        @if ($update->backup !== null)
            <div data-env-update-backup><dt>{{ __('larena-env::admin.update.backup') }}</dt><dd style="overflow-wrap: anywhere;">{{ $update->backup }}</dd></div>
        @endif
        <div data-env-update-restarts><dt>{{ __('larena-env::admin.update.restarts') }}</dt><dd>{{ $update->restarts === [] ? __('larena-env::admin.update.no_restarts') : implode(', ', $update->restarts) }}</dd></div>
        @if ($update->target === 'env')
            <div data-env-update-checks><dt>{{ __('larena-env::admin.update.checks') }}</dt><dd style="overflow-wrap: anywhere;">{{ $update->checks === [] ? __('larena-env::admin.update.no_checks') : implode(', ', $update->checks) }}</dd></div>
        @endif
    </dl>
    <h3>{{ __('larena-env::admin.update.rollback_title') }}</h3>
    <p data-env-update-rollback role="note">{{ __('larena-env::admin.update.rollback.'.$update->target) }}</p>
    @if ($update->notes !== [])
        <h3>{{ __('larena-env::admin.update.notes') }}</h3>
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
