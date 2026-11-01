{{-- A change the server undoes by itself unless it is kept in time (the firewall, who reaches the panel): how long is
     left and the button that keeps it. Keeping it is asked over the new rules, so a panel shut out never keeps it. --}}
@if ($change !== null && $change->pending())
    <div role="alert" data-env-timed-change="{{ $change->changeId }}" style="padding: .75rem; border: 1px solid var(--sf-error, #b3261e); border-radius: .625rem;">
        @if ($change->secondsLeft($now) !== null)
            <p data-env-timed-left="{{ $change->secondsLeft($now) }}">{{ __('larena-env::admin.timed.left', ['seconds' => (string) $change->secondsLeft($now), 'time' => \Larena\Env\Server\Moment::human($change->expiresAt)]) }}</p>
        @else
            <p>{{ __('larena-env::admin.timed.left_unknown') }}</p>
        @endif
        @if ($canKeep)
            <form method="post" action="{{ route('larena.env.admin.change.keep', [], false) }}" data-env-timed-keep="{{ $change->changeId }}">
                @csrf
                <input type="hidden" name="change" value="{{ $change->changeId }}">
                <input type="hidden" name="kind" value="{{ $change->kind ?? '' }}">
                <button type="submit" class="larena-button" style="min-height: 2.75rem;">{{ __('larena-env::admin.timed.keep') }}</button>
            </form>
        @endif
        <p>{{ __('larena-env::admin.timed.explain') }}</p>
    </div>
@endif
