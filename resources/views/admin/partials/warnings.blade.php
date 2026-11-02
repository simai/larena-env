{{-- What needs attention (Panel plan 4.4), said before anyone asks: errors first, each with the place it is dealt with.
     $warnings is a list of Larena\Env\Warnings\PanelWarning; nothing is drawn when it is null (not read). With nothing
     to say it is one quiet line, not an empty card: a block that is always there teaches people not to look at it.
     Drawn with Framework utilities until the Framework's attention list ships (msg-993e1e39151942c19b23). --}}
@if (($warnings ?? null) !== null)
    @if ($warnings === [])
        <p class="flex flex-wrap items-cross-center g-1 m-bottom-2 color-on-surface-variant" data-env-warnings="0" data-env-warnings-none>
            <sf-badge status="success" appearance="tonal" text="{{ __('larena-env::admin.warnings.none') }}"></sf-badge>
            @if ($fromServer ?? false)
                <span data-env-warnings-from-server>{{ __('larena-env::admin.warnings.from_server') }}</span>
            @endif
        </p>
    @else
        <section class="larena-panel m-bottom-2" data-env-warnings="{{ count($warnings) }}" aria-labelledby="env-warnings"
            @if (array_filter($warnings, static fn ($warning): bool => $warning->severity === 'error') !== []) role="alert" @endif>
            <div class="flex flex-wrap items-cross-center g-1 p-2 p-bottom-1">
                <h2 id="env-warnings" class="m-0">{{ __('larena-env::admin.warnings.title') }}</h2>
                <sf-badge status="{{ array_filter($warnings, static fn ($warning): bool => $warning->severity === 'error') !== [] ? 'error' : 'warning' }}" text="{{ count($warnings) }}"></sf-badge>
                @if ($fromServer ?? false)
                    <span class="color-on-surface-variant" data-env-warnings-from-server>{{ __('larena-env::admin.warnings.from_server') }}</span>
                @endif
            </div>
            <ul class="m-0 p-0" style="list-style: none;">
                @foreach ($warnings as $warning)
                    <li class="flex flex-wrap items-cross-center g-2 p-2 border-top-1 border-top-solid border-outline-variant"
                        data-env-warning="{{ $warning->kind }}" data-env-warning-severity="{{ $warning->severity }}" @if ($warning->domain !== null) data-env-warning-site="{{ $warning->domain }}" @endif
                        @if ($warning->alertId !== null) data-env-alert="{{ $warning->alertId }}" data-env-alert-state="{{ $warning->state }}" @endif>
                        <sf-badge status="{{ $warning->severity === 'error' ? 'error' : 'warning' }}" appearance="tonal"
                            text="{{ __('larena-env::admin.warnings.severity.'.($warning->severity === 'error' ? 'error' : 'warning')) }}"></sf-badge>
                        <div class="grow min-w-0" style="flex-basis: 16rem;">
                            {{ __('larena-env::admin.warnings.'.$warning->kind, $warning->replace) }}
                            @if ($warning->state === 'acked')
                                <br><small class="color-on-surface-variant" data-env-alert-acked>{{ __('larena-env::admin.warnings.acked', ['by' => $warning->ackedBy ?? '—', 'time' => \Larena\Env\Server\Moment::human($warning->ackedAt)]) }}</small>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-cross-center g-1">
                            @if ($warning->domain !== null && ($linkSites ?? true))
                                <a class="larena-button" href="{{ route('larena.env.admin.site', ['domain' => $warning->domain], false) }}?tab={{ $warning->tab() }}">{{ __('larena-env::admin.warnings.go') }}</a>
                            @elseif ($warning->domain !== null)
                                <a class="larena-button" href="?tab={{ $warning->tab() }}">{{ __('larena-env::admin.warnings.go') }}</a>
                            @endif
                            @if ($warning->state !== 'acked' && $warning->acknowledgeable() && ($canAcknowledge ?? false))
                                {{-- An open alert of the server: a person says they have seen it, by plan and confirmation. --}}
                                <form method="post" action="{{ route('larena.env.admin.alerts.ack.plan', [], false) }}" data-env-alert-ack="{{ $warning->alertId }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $warning->alertId }}">
                                    <input type="hidden" name="back" value="{{ $warningsBack ?? '' }}">
                                    <button type="submit" class="larena-button">{{ __('larena-env::admin.warnings.ack') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endif
