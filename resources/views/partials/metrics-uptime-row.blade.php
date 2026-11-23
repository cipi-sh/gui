{{-- 30 bars, one per UTC day: $u = an uptime answer (days, uptime), $label = app name or null. --}}
@php
    $bars = \CipiGui\Support\Metrics::uptimeDays((array) ($u['days'] ?? []), 30);
@endphp
<div class="uptime-row" @if($label === null) style="grid-template-columns: minmax(0, 1fr) 4.5rem" @endif data-uptime-row="{{ $label ?? 'app' }}">
    @if($label !== null)
        <a href="{{ route('cipi-gui.apps.show', ['name' => $label, 'server' => $serverId, 'tab' => 'metrics']) }}" class="row-link truncate">{{ $label }}</a>
    @endif
    <div class="uptime-bars" role="img" aria-label="Uptime per day, last 30 days">
        @foreach($bars as $b)
            <span class="uptime-bar {{ \CipiGui\Support\Metrics::uptimeClass($b['pct']) }}" title="{{ \CipiGui\Support\Clock::format($b['ts'], 'D j M') }} — {{ $b['pct'] === null ? 'no checks' : \CipiGui\Support\Metrics::format($b['pct'], '%', 2) }}"></span>
        @endforeach
    </div>
    <span class="uptime-pct {{ \CipiGui\Support\Metrics::uptimeClass($u['uptime'] ?? null) }}">{{ is_numeric($u['uptime'] ?? null) ? \CipiGui\Support\Metrics::format($u['uptime'], '%', 2) : '—' }}</span>
</div>
