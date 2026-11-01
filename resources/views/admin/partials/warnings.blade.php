{{-- What needs attention (Panel plan 4.4), said before anyone asks: errors first, each with the place it is dealt with.
     $warnings is a list of Larena\Env\Warnings\PanelWarning; nothing is drawn when it is null (not read). --}}
@if (($warnings ?? null) !== null)
    <section class="larena-panel larena-panel-padded" data-env-warnings="{{ count($warnings) }}" aria-labelledby="env-warnings" style="margin-bottom: 1rem;"
        @if (array_filter($warnings, static fn ($warning): bool => $warning->severity === 'error') !== []) role="alert" @endif>
        <h2 id="env-warnings">{{ __('larena-env::admin.warnings.title') }}</h2>
        @if ($fromServer ?? false)
            <p data-env-warnings-from-server>{{ __('larena-env::admin.warnings.from_server') }}</p>
        @endif
        @if ($warnings === [])
            <p data-env-warnings-none>{{ __('larena-env::admin.warnings.none') }}</p>
        @else
            <ul class="larena-list">
                @foreach ($warnings as $warning)
                    <li data-env-warning="{{ $warning->kind }}" data-env-warning-severity="{{ $warning->severity }}" @if ($warning->domain !== null) data-env-warning-site="{{ $warning->domain }}" @endif
                        @if ($warning->alertId !== null) data-env-alert="{{ $warning->alertId }}" data-env-alert-state="{{ $warning->state }}" @endif>
                        {{ __('larena-env::admin.warnings.'.$warning->kind, $warning->replace) }}
                        @if ($warning->domain !== null && ($linkSites ?? true))
                            <a href="{{ route('larena.env.admin.site', ['domain' => $warning->domain], false) }}?tab={{ $warning->tab() }}">{{ __('larena-env::admin.warnings.go') }}</a>
                        @elseif ($warning->domain !== null)
                            <a href="?tab={{ $warning->tab() }}">{{ __('larena-env::admin.warnings.go') }}</a>
                        @endif
                        @if ($warning->state === 'acked')
                            <br><small data-env-alert-acked>{{ __('larena-env::admin.warnings.acked', ['by' => $warning->ackedBy ?? '—', 'time' => \Larena\Env\Server\Moment::human($warning->ackedAt)]) }}</small>
                        @elseif ($warning->acknowledgeable() && ($canAcknowledge ?? false))
                            {{-- An open alert of the server: a person says they have seen it, by plan and confirmation. --}}
                            <form method="post" action="{{ route('larena.env.admin.alerts.ack.plan', [], false) }}" data-env-alert-ack="{{ $warning->alertId }}" style="display: inline;">
                                @csrf
                                <input type="hidden" name="id" value="{{ $warning->alertId }}">
                                <input type="hidden" name="back" value="{{ $warningsBack ?? '' }}">
                                <button type="submit" class="larena-button" style="min-height: 44px;">{{ __('larena-env::admin.warnings.ack') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
