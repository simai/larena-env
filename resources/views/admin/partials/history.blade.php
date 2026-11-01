{{-- The load over the last day and week (Panel plan 4.6): one line each for the processor, memory and every disk, in
     percent of the whole, as the panel's check measured it. $history is null where the person may not see the server. --}}
@if (($history ?? null) !== null)
    <section class="larena-panel larena-panel-padded" data-env-history aria-labelledby="env-history">
        <h2 id="env-history">{{ __('larena-env::admin.history.title') }}</h2>
        @foreach ($history as $period => $line)
            <h3>{{ __('larena-env::admin.history.'.$period) }}</h3>
            @if ($line->count === 0)
                <p data-env-history-none="{{ $period }}">{{ __('larena-env::admin.history.none') }}</p>
            @else
                <svg viewBox="0 0 600 120" width="100%" height="120" role="img" data-env-history-chart="{{ $period }}" preserveAspectRatio="none"
                    aria-label="{{ __('larena-env::admin.history.'.$period) }}, {{ __('larena-env::admin.history.scale') }}" style="max-width: 100%; color: currentColor;">
                    <line x1="0" y1="120" x2="600" y2="120" stroke="currentColor" stroke-opacity="0.3" stroke-width="1" />
                    <line x1="0" y1="12" x2="600" y2="12" stroke="currentColor" stroke-opacity="0.15" stroke-width="1" />
                    @foreach (array_values($line->lines) as $index => $points)
                        @if (count($points) === 1)
                            <circle cx="{{ number_format($points[0][0] * 600, 1, '.', '') }}" cy="{{ number_format(120 - min(100, max(0, $points[0][1])) * 1.2, 1, '.', '') }}" r="2.5" fill="currentColor" />
                        @else
                            <polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="{{ ['', '6 4', '2 3', '8 3 2 3'][$index % 4] }}" points="{{ \Larena\Env\History\MetricHistory::polyline($points, 600, 120) }}" />
                        @endif
                    @endforeach
                </svg>
                <ul class="larena-list" data-env-history-legend="{{ $period }}">
                    @foreach ($line->lines as $name => $points)
                        <li data-env-history-line="{{ $name }}"><svg width="24" height="6" aria-hidden="true"><line x1="0" y1="3" x2="24" y2="3" stroke="currentColor" stroke-width="1.5" stroke-dasharray="{{ ['', '6 4', '2 3', '8 3 2 3'][$loop->index % 4] }}" /></svg> {{ str_starts_with($name, 'disk:') ? __('larena-env::admin.history.disk', ['mount' => substr($name, 5)]) : __('larena-env::admin.history.'.$name) }} · {{ (string) round(end($points)[1]) }}%</li>
                    @endforeach
                </ul>
                <p>{{ __('larena-env::admin.history.points', ['count' => (string) $line->count, 'from' => (string) $line->from, 'to' => (string) $line->to]) }}</p>
            @endif
        @endforeach
    </section>
@endif
