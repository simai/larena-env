{{-- What every panel screen says first: recorded answers are never taken for a live server, and a person looking at
     the panel as someone else always sees it and can stop. --}}
@if (($acting ?? null) !== null)
    <section class="larena-panel larena-panel-padded" data-env-acting="{{ $acting['owner'] }}" role="status">
        <p>{{ __('larena-env::admin.acting.notice', ['person' => $acting['label'], 'owner' => $acting['owner']]) }}</p>
        <form method="post" action="{{ route('larena.env.admin.people.stop', [], false) }}">
            @csrf
            <button type="submit">{{ __('larena-env::admin.acting.stop') }}</button>
        </form>
    </section>
@endif
@if (($source ?? null) === 'recorded')
    <section class="larena-panel larena-panel-padded" data-env-source="recorded" role="note">
        <p>{{ __('larena-env::admin.recorded_notice') }}</p>
    </section>
@endif
@if (($notice ?? null) !== null)
    <section class="larena-panel larena-panel-padded" data-env-notice role="alert">
        <p>{{ $notice }}</p>
    </section>
@endif
