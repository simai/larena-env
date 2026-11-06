{{-- The load history as one chart per measure (owner 2026-11-06, after a hosting provider's dashboard): the period and
     the scale chosen above, each chart with its whole in the title, its scale in its own units on the left and the time
     along the bottom; every point names its time and value on hover. Drawn by the panel until the Framework's chart set
     reaches Larena (ui-chart, sf-chart-line). --}}
<section class="larena-panel larena-panel-padded" data-env-charts="{{ $charts->period }}" aria-labelledby="env-charts">
    <h2 id="env-charts" class="text-2">{{ __('larena-env::admin.history.title') }}</h2>
    <p class="color-on-surface-variant" data-env-history-from-server>{{ __('larena-env::admin.history.from_server') }}</p>
    <nav class="flex flex-wrap items-cross-center g-2" aria-label="{{ __('larena-env::admin.history.period_label') }}">
        <span style="font-weight: 600;">{{ __('larena-env::admin.history.period_label') }}</span>
        @foreach (array_keys(\Larena\Env\History\HistoryCharts::PERIODS) as $period)
            <a href="?{{ http_build_query(['period' => $period, 'scale' => $charts->scale]) }}#env-charts" class="larena-button @if ($charts->period === $period) larena-button-primary @endif"
               style="min-height: 2.5rem;" data-env-charts-period="{{ $period }}" @if ($charts->period === $period) aria-current="true" @endif>{{ __('larena-env::admin.history.periods.'.$period) }}</a>
        @endforeach
    </nav>
    <nav class="flex flex-wrap items-cross-center g-2 m-t-2" aria-label="{{ __('larena-env::admin.history.scale_label') }}">
        <span style="font-weight: 600;">{{ __('larena-env::admin.history.scale_label') }}</span>
        @foreach (\Larena\Env\History\HistoryCharts::SCALES as $scale)
            <a href="?{{ http_build_query(['period' => $charts->period, 'scale' => $scale]) }}#env-charts" class="larena-button @if ($charts->scale === $scale) larena-button-primary @endif"
               style="min-height: 2.5rem;" data-env-charts-scale="{{ $scale }}" @if ($charts->scale === $scale) aria-current="true" @endif>{{ __('larena-env::admin.history.scales.'.$scale) }}</a>
        @endforeach
    </nav>
    @if ($charts->charts === [])
        <p class="m-t-2" data-env-history-none="{{ $charts->period }}">{{ __('larena-env::admin.history.none') }}</p>
    @else
        <div class="m-t-3" style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr));">
            @foreach ($charts->charts as $chart)
                <figure class="m-0 p-3" style="border: 1px solid var(--larena-line); border-radius: .75rem;" data-env-chart="{{ $chart['key'] }}">
                    <figcaption class="flex flex-wrap items-cross-baseline g-1">
                        <strong>{{ $chart['title'] }}</strong>
                        @if ($chart['capacity'] !== null)<span class="color-on-surface-variant">{{ $chart['capacity'] }}</span>@endif
                        <span class="grow"></span>
                        <span class="color-on-surface-variant">{{ __('larena-env::admin.history.now') }} <strong style="color: var(--sf-on-surface);">{{ $chart['last'] }}</strong></span>
                    </figcaption>
                    <div style="display: grid; grid-template-columns: auto 1fr; column-gap: .5rem; margin-top: .75rem;">
                        <div class="color-on-surface-variant text-0" style="display: flex; flex-direction: column; justify-content: space-between; text-align: end; block-size: 10rem; white-space: nowrap;" data-env-chart-ticks>
                            @foreach ($chart['ticks'] as $tick)<span style="line-height: 1;">{{ $tick }}</span>@endforeach
                        </div>
                        <svg viewBox="0 0 {{ \Larena\Env\History\HistoryCharts::WIDTH }} {{ \Larena\Env\History\HistoryCharts::HEIGHT }}" preserveAspectRatio="none" role="img"
                             aria-label="{{ $chart['title'] }}: {{ $chart['ticks'][4] }} – {{ $chart['ticks'][0] }}, {{ __('larena-env::admin.history.now') }} {{ $chart['last'] }}"
                             style="inline-size: 100%; block-size: 10rem; display: block; overflow: visible; color: var(--sf-primary);">
                            @foreach (range(0, 4) as $step)
                                <line x1="0" x2="{{ \Larena\Env\History\HistoryCharts::WIDTH }}" y1="{{ \Larena\Env\History\HistoryCharts::HEIGHT * $step / 4 }}" y2="{{ \Larena\Env\History\HistoryCharts::HEIGHT * $step / 4 }}"
                                      stroke="currentColor" stroke-opacity="{{ $step === 4 ? '0.35' : '0.12' }}" stroke-width="1" vector-effect="non-scaling-stroke" style="color: var(--sf-on-surface);" />
                            @endforeach
                            <polygon points="{{ $chart['area'] }}" fill="currentColor" fill-opacity="0.15" />
                            <polyline points="{{ $chart['line'] }}" fill="none" stroke="currentColor" stroke-width="1.75" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
                            @foreach ($chart['points'] as $point)
                                <rect x="{{ max(0, $point['x'] - 3) }}" y="0" width="6" height="{{ \Larena\Env\History\HistoryCharts::HEIGHT }}" fill="transparent"><title>{{ $point['label'] }}</title></rect>
                            @endforeach
                        </svg>
                        <span></span>
                        <div class="color-on-surface-variant text-0" style="display: flex; justify-content: space-between; margin-top: .25rem; white-space: nowrap;" data-env-chart-times>
                            @foreach ($chart['times'] as $time)<span>{{ $time }}</span>@endforeach
                        </div>
                    </div>
                </figure>
            @endforeach
        </div>
        <p class="m-t-2 color-on-surface-variant">{{ __('larena-env::admin.history.points', ['count' => (string) $charts->count, 'from' => \Larena\Env\Server\Moment::human($charts->from), 'to' => \Larena\Env\Server\Moment::human($charts->to)]) }} · {{ __('larena-env::admin.history.utc') }}</p>
    @endif
</section>
