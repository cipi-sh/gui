{{-- One chart of the Metrics tab: $key, $c = [title, hint, chart (Support\Metrics::chart)]. --}}
@php
    $chart = $c['chart'];
    $first = $chart['series'][0] ?? null;
    $last = $first && ! empty($first['points']) ? end($first['points'])[1] : null;
@endphp
<div class="chart-card" data-chart-card="{{ $key }}">
    <div class="chart-head">
        <div class="min-w-0">
            <div class="chart-title">{{ $c['title'] }}</div>
            <div class="chart-hint">{{ $c['hint'] }}</div>
        </div>
        @if($last !== null)
            <span class="chart-now">{{ \CipiGui\Support\Metrics::format($last, $chart['unit'] === 'bps' ? 'bps' : ($chart['unit'] === 'count' ? 'count' : $chart['unit'])) }}</span>
        @endif
    </div>
    @if(count($chart['series']) > 1 || ! empty($chart['threshold']))
        <div class="chart-legend">
            @foreach($chart['series'] as $s)
                <span><span class="chart-swatch" style="background: var(--{{ $s['color'] }})"></span>{{ $s['name'] }}</span>
            @endforeach
            @if(! empty($chart['threshold']))
                <span><span class="chart-swatch" style="background: var(--warn); height: 2px"></span>{{ $chart['threshold']['label'] }}</span>
            @endif
        </div>
    @endif
    <div class="chart-box" wire:ignore wire:key="chart-{{ $key }}-{{ $version }}" aria-label="{{ $c['title'] }}"
         data-empty="No data in this range yet — the collector writes a point every minute."
         data-cipi-chart="{{ json_encode($chart, JSON_UNESCAPED_SLASHES) }}"></div>
</div>
