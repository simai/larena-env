{{-- One measure as a tile: its name, the value, a meter that takes the threshold's colour and the line over a day.
     Drawn with Framework utilities until the Framework's metric tile ships (msg-993e1e39151942c19b23); the line moves
     to the chart package (ui-chart) then. $tone: success, warning or error; $points: the day's points, or null. --}}
<div class="larena-panel p-2 flex flex-col g-1 min-w-0" data-env-metric="{{ $key }}" data-env-metric-tone="{{ $tone }}">
    <div class="flex items-cross-center content-main-between g-1">
        <span class="color-on-surface-variant truncate">{{ $label }}</span>
        @if ($tone !== 'success')
            <sf-badge status="{{ $tone }}" appearance="tonal" size="1/3" text="{{ __('larena-env::admin.warnings.severity.'.$tone) }}"></sf-badge>
        @endif
    </div>
    <div class="flex items-cross-end content-main-between g-2">
        <strong class="text-5 line-tight" style="font-variant-numeric: tabular-nums;">{{ $value }}</strong>
        @if (($points ?? null) !== null && count($points) > 1)
            <svg viewBox="0 0 120 32" width="120" height="32" aria-hidden="true" preserveAspectRatio="none" data-env-metric-line="{{ $key }}"
                class="{{ $tone === 'error' ? 'color-error' : ($tone === 'warning' ? 'color-warning' : 'color-primary') }}" style="flex: 0 1 120px; min-width: 48px;">
                <polyline fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" points="{{ \Larena\Env\History\MetricHistory::polyline($points, 120, 32) }}" />
            </svg>
        @endif
    </div>
    @if (($percent ?? null) !== null)
        <sf-progress-bar value="{{ min(100, max(0, $percent)) }}" status="{{ $tone }}" size="1/2" label="{{ $label }}"></sf-progress-bar>
    @endif
    <span class="color-on-surface-variant">{{ $sub }}</span>
</div>
