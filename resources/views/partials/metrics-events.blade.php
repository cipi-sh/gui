{{-- A timeline of metrics events, newest first: $events, $showSubject. --}}
@php $events = array_slice((array) $events, 0, 25); @endphp
@if(! $events)
    <p class="text-sm text-muted">Nothing in this range.</p>
@else
    <div class="timeline" data-metrics-events>
        @foreach($events as $e)
            @php
                $source = (string) ($e['source'] ?? '');
                $status = (string) ($e['status'] ?? '');
                $dot = $source === 'deploy' && $status !== 'crit' ? 'is-deploy' : \CipiGui\Support\Metrics::eventClass($status);
                $what = match ($source) {
                    'deploy' => 'Deploy',
                    'health' => $status === 'ok' ? 'Back up' : 'Down',
                    'monitor' => 'Monitor · '.($e['subject'] ?? ''),
                    'heartbeat' => 'Heartbeat',
                    default => ucfirst($source),
                };
            @endphp
            <div class="timeline-item">
                <span class="timeline-time">{{ \CipiGui\Support\Clock::format((int) ($e['ts'] ?? 0), 'M j, H:i') }}</span>
                <span class="timeline-dot {{ $dot }}"></span>
                @php $summary = (string) preg_replace('/^(down|back up|deploy) — /', '', (string) ($e['summary'] ?? '')); @endphp
                <div class="timeline-body"><b>{{ $what }}</b>@if($showSubject && in_array($source, ['deploy', 'health'], true)) <span class="text-muted">{{ $e['subject'] ?? '' }}</span>@endif — {{ $summary }}</div>
            </div>
        @endforeach
    </div>
@endif
