{{-- What needs attention (Panel plan 4.4), said before anyone asks: errors first, each with the place it is dealt with.
     $warnings is a list of Larena\Env\Warnings\PanelWarning; nothing is drawn when it is null (not read). --}}
@if (($warnings ?? null) !== null)
    <section class="larena-panel larena-panel-padded" data-env-warnings="{{ count($warnings) }}" aria-labelledby="env-warnings" style="margin-bottom: 1rem;"
        @if (array_filter($warnings, static fn ($warning): bool => $warning->severity === 'error') !== []) role="alert" @endif>
        <h2 id="env-warnings">{{ __('larena-env::admin.warnings.title') }}</h2>
        @if ($warnings === [])
            <p data-env-warnings-none>{{ __('larena-env::admin.warnings.none') }}</p>
        @else
            <ul class="larena-list">
                @foreach ($warnings as $warning)
                    <li data-env-warning="{{ $warning->kind }}" data-env-warning-severity="{{ $warning->severity }}" @if ($warning->domain !== null) data-env-warning-site="{{ $warning->domain }}" @endif>
                        {{ __('larena-env::admin.warnings.'.$warning->kind, $warning->replace) }}
                        @if ($warning->domain !== null && ($linkSites ?? true))
                            <a href="{{ route('larena.env.admin.site', ['domain' => $warning->domain], false) }}?tab={{ $warning->tab() }}">{{ __('larena-env::admin.warnings.go') }}</a>
                        @elseif ($warning->domain !== null)
                            <a href="?tab={{ $warning->tab() }}">{{ __('larena-env::admin.warnings.go') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
